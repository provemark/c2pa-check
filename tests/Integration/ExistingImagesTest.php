<?php

declare(strict_types=1);

// Each test starts from an empty library, so "all images" is only its own.
beforeEach(function (): void {
    emptyTestEnvironment();
});

/**
 * JPEG/PNG/WebP attachments made from a fixture without the plugin's
 * check, as images uploaded before the plugin was activated: no entry, no
 * marker, nothing scheduled. Returns their IDs, ascending.
 *
 * @return list<int>
 */
function uncheckedAttachments(string $fixture, int $count): array
{
    $source = containerPath(fixturePath($fixture));
    $php = <<<PHP
        require_once ABSPATH.'wp-admin/includes/image.php';
        \$ids = [];
        for (\$i = 0; \$i < $count; \$i++) {
            \$dir = wp_upload_dir();
            \$file = \$dir['path'].'/before-'.wp_generate_password(8, false).'-'.basename('$source');
            copy('$source', \$file);
            \$ids[] = wp_insert_attachment(['post_mime_type' => wp_check_filetype(\$file)['type'], 'post_title' => 'before', 'post_status' => 'inherit'], \$file);
        }
        global \$wpdb;
        \$in = implode(',', array_map('intval', \$ids));
        \$wpdb->query("DELETE FROM {\$wpdb->postmeta} WHERE post_id IN (\$in) AND meta_key LIKE '\\\\_tracefern\\\\_%'");
        wp_unschedule_hook('tracefern_check');
        echo implode(',', \$ids);
        PHP;

    return array_map('intval', explode(',', wpEval($php)));
}

/**
 * Presses a button of the "Existing images" section as $user would: a POST
 * to admin-post.php with the section's nonce (or none).
 */
function pressExistingImagesButton(string $action, string $mode = '', string $user = 'admin', bool $nonce = true): string
{
    $nonceCode = $nonce ? "\$_REQUEST['_wpnonce'] = \$_POST['_wpnonce'] = wp_create_nonce('tracefern_existing_images');" : '';

    return wpEvalAs($user, "\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_POST['mode'] = \$_REQUEST['mode'] = '$mode'; $nonceCode do_action('admin_post_$action');");
}

/**
 * The stored run, or null.
 *
 * @return array<mixed>|null
 */
function existingImagesRun(): ?array
{
    $run = json_decode(wpEval("echo wp_json_encode(get_option('tracefern_existing_images', null));"), true);

    return is_array($run) ? $run : null;
}

/**
 * Runs the check queue, and the runs it schedules (safety runs included),
 * until nothing is scheduled any more. Returns how many requests ran.
 */
function runQueueUntilIdle(int $max = 30): int
{
    for ($i = 0; $i < $max; $i++) {
        if (wpEval("echo wp_next_scheduled('tracefern_check') === false ? 'idle' : 'busy';") === 'idle') {
            return $i;
        }
        runPendingChecksOutput();
    }

    throw new RuntimeException('the queue did not go idle');
}

/** The settings page as the admin sees it, as visible text. */
function settingsPageText(): string
{
    return visibleText(wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();"));
}

/**
 * Runs $test while the check of one attachment dies after its provisional
 * entry is written (out of memory, as SPEC-015's hook does for every check).
 *
 * @template T
 *
 * @param  callable(): T  $test
 * @return T
 */
function withCheckThatDiesFor(int $id, callable $test): mixed
{
    wpEval(<<<PHP
        if (! is_dir(WPMU_PLUGIN_DIR)) { mkdir(WPMU_PLUGIN_DIR, 0777, true); }
        file_put_contents(WPMU_PLUGIN_DIR.'/tracefern-test-check-dies-for.php', '<?php add_filter("get_attached_file", static function (\$f, \$id) { \$GLOBALS["tracefern_test_current"] = \$id; return \$f; }, 10, 2); add_filter("pre_option_tracefern_digicert", static function (\$v) { if (doing_action("tracefern_check") && (\$GLOBALS["tracefern_test_current"] ?? 0) === $id) { \$a = []; while (true) { \$a[] = str_repeat("x", 1 << 20); } } return \$v; });');
        PHP);
    try {
        return $test();
    } finally {
        wpEval('@unlink(WPMU_PLUGIN_DIR."/tracefern-test-check-dies-for.php");');
    }
}

it('AC1: never-checked images get checked, checked ones are left alone', function (): void {
    $before = [...uncheckedAttachments('fixture-signed.jpg', 1), ...uncheckedAttachments('fixture-signed.png', 1), ...uncheckedAttachments('fixture-unsigned.jpg', 1)];
    $checked = importMedia(fixturePath('fixture-signed.webp'));
    $checkedAt = storedEntry($checked)['checked_at'] ?? null;
    sleep(1);

    pressExistingImagesButton('tracefern_existing_start', 'unchecked');
    runQueueUntilIdle();

    foreach (array_combine($before, ['fixture-signed.jpg', 'fixture-signed.png', 'fixture-unsigned.jpg']) as $id => $fixture) {
        expect(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath($fixture), defaultSettingsFile()));
    }
    expect(storedEntry($checked)['checked_at'] ?? null)->toBe($checkedAt)
        ->and(existingImagesRun()['finished'] ?? null)->toBeInt();
})->group('SPEC-031');

it('AC2: all images again, with the current trust settings', function (): void {
    $ids = [importMedia(fixturePath('fixture-signed.jpg')), importMedia(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'))];
    expect(storedEntry($ids[0])['trust'] ?? null)->toContain('+digicert');

    setOption('tracefern_digicert', false);
    try {
        pressExistingImagesButton('tracefern_existing_start', 'all');
        runQueueUntilIdle();

        expect(storedEntry($ids[0])['trust'] ?? '')->not->toContain('digicert')
            ->and(stable((array) storedEntry($ids[0])))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile(false)))
            ->and(stable((array) storedEntry($ids[1])))->toBe(expectedEntry(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'), defaultSettingsFile(false)));
    } finally {
        resetTrustOptions();
    }
})->group('SPEC-031');

it('AC3: an upload is checked before the images of a run', function (): void {
    uncheckedAttachments('fixture-unsigned.jpg', 21);
    pressExistingImagesButton('tracefern_existing_start', 'unchecked');
    $upload = importWithoutChecking(fixturePath('fixture-signed.jpg'));

    runPendingChecksOutput();

    expect(storedEntry($upload)['state'] ?? null)->toBe('Valid')
        ->and(existingImagesRun()['done'] ?? null)->toBe(19)
        ->and(existingImagesRun()['finished'] ?? null)->toBeNull();
})->group('SPEC-031');

it('AC4: progress, finish and stop', function (): void {
    $ids = uncheckedAttachments('fixture-unsigned.jpg', 21);
    pressExistingImagesButton('tracefern_existing_start', 'unchecked');

    expect(settingsPageText())->toContain('0 of 21 done')->toContain('Stop');
    runPendingChecksOutput();
    expect(settingsPageText())->toContain('20 of 21 done');

    pressExistingImagesButton('tracefern_existing_stop');
    expect(existingImagesRun())->toBeNull();
    runQueueUntilIdle();
    expect(storedEntry($ids[20]))->toBeNull();
    expect(settingsPageText())->not->toContain('of 21 done');

    pressExistingImagesButton('tracefern_existing_start', 'unchecked');
    runQueueUntilIdle();
    expect(settingsPageText())->toContain('Finished')
        ->and(storedEntry($ids[20])['state'] ?? null)->toBe('none');
})->group('SPEC-031');

it('AC5: a check that dies does not stop the run', function (): void {
    $ids = uncheckedAttachments('fixture-signed.jpg', 3);

    withCheckThatDiesFor($ids[1], function (): void {
        pressExistingImagesButton('tracefern_existing_start', 'unchecked');
        runQueueUntilIdle();
    });

    expect(storedEntry($ids[0])['state'] ?? null)->toBe('Valid')
        ->and(storedEntry($ids[1])['state'] ?? null)->toBe('error')
        ->and(storedEntry($ids[1])['reason'] ?? null)->toBe('interrupted')
        ->and(storedEntry($ids[2])['state'] ?? null)->toBe('Valid');
})->group('SPEC-031');

it('AC6: no nonce, no permission, no run', function (): void {
    uncheckedAttachments('fixture-signed.jpg', 1);
    wpCli(['user', 'create', 'm31-subscriber', 'm31-subscriber@example.test', '--role=subscriber']);

    pressExistingImagesButton('tracefern_existing_start', 'unchecked', 'admin', nonce: false);
    pressExistingImagesButton('tracefern_existing_start', 'unchecked', 'm31-subscriber');
    pressExistingImagesButton('tracefern_existing_start', 'sideways');

    expect(existingImagesRun())->toBeNull()
        ->and(wpEval("echo wp_next_scheduled('tracefern_check') === false ? 'idle' : 'busy';"))->toBe('idle');
})->group('SPEC-031');

it('AC7: pressing a button writes one option and no per-image meta', function (): void {
    uncheckedAttachments('fixture-signed.jpg', 21);
    $count = 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");';
    $before = (int) wpEval($count);

    pressExistingImagesButton('tracefern_existing_start', 'unchecked');

    expect((int) wpEval($count))->toBe($before)
        ->and(existingImagesRun()['total'] ?? null)->toBe(21)
        ->and(wpEval("echo wp_next_scheduled('tracefern_check') === false ? 'idle' : 'busy';"))->toBe('busy');
})->group('SPEC-031');

it('AC9: uninstall removes the run', function (): void {
    uncheckedAttachments('fixture-signed.jpg', 1);
    pressExistingImagesButton('tracefern_existing_start', 'unchecked');
    expect(existingImagesRun())->not->toBeNull();

    wpEval(UNINSTALL_PLUGIN_PHP);

    expect(existingImagesRun())->toBeNull();
    wpCli(['plugin', 'activate', 'tracefern-image-check-for-c2pa']);
})->group('SPEC-031');

it('the settings page points at the buttons instead of "new uploads only"', function (): void {
    $page = settingsPageText();

    expect($page)->toContain('Check images that were never checked')
        ->and($page)->toContain('Check all images again')
        ->and(str_contains($page, 'Settings apply to new uploads only'))->toBeFalse();
})->group('SPEC-031');

/**
 * The progress action as $user's browser calls it (admin-ajax), with the
 * section's nonce or without; returns the decoded JSON answer.
 *
 * @return array<mixed>
 */
function askProgress(string $user = 'admin', bool $nonce = true): array
{
    $nonceCode = $nonce ? "\$_REQUEST['nonce'] = \$_POST['nonce'] = wp_create_nonce('tracefern_existing_progress');" : '';
    $out = wpEvalAs($user, "add_filter('wp_doing_ajax', '__return_true'); add_filter('wp_die_ajax_handler', static fn () => static function () { exit; }); \$_SERVER['REQUEST_METHOD'] = 'POST'; $nonceCode do_action('wp_ajax_tracefern_existing_progress');");
    $json = json_decode(trim((string) preg_replace('/^[^{]*/', '', $out)), true);

    return is_array($json) ? $json : [];
}

it('AC10 (amendment 2): the progress updates itself while a run goes', function (): void {
    uncheckedAttachments('fixture-unsigned.jpg', 21);

    $idle = wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();");
    expect($idle)->not->toContain('aria-live')
        ->and($idle)->not->toContain('tracefern_existing_progress');

    pressExistingImagesButton('tracefern_existing_start', 'unchecked');
    $running = wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();");
    expect($running)->toContain('aria-live="polite"')
        ->and($running)->toContain('tracefern_existing_progress');

    runPendingChecksOutput();
    $answer = askProgress();
    $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];
    expect($answer['success'] ?? null)->toBeTrue()
        ->and($data['done'] ?? null)->toBe(20)
        ->and($data['total'] ?? null)->toBe(21)
        ->and($data['finished'] ?? null)->toBeFalse()
        ->and(is_string($data['text'] ?? null) ? $data['text'] : '')->toContain('20 of 21 done');

    runQueueUntilIdle();
    $after = askProgress();
    expect(is_array($after['data'] ?? null) ? ($after['data']['finished'] ?? null) : null)->toBeTrue();
})->group('SPEC-031');

it('AC10 (amendment 2): the progress action refuses without the nonce or the permission', function (): void {
    wpCli(['user', 'create', 'm31-subscriber', 'm31-subscriber@example.test', '--role=subscriber']);

    expect(askProgress('admin', nonce: false)['success'] ?? null)->toBeFalse()
        ->and(askProgress('m31-subscriber')['success'] ?? null)->toBeFalse();
})->group('SPEC-031');
