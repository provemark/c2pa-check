<?php

declare(strict_types=1);

/**
 * The five images of the Live Preview (SPEC-024), by attachment title: the
 * file the oracle checks, the state it must have with the default
 * settings, whether the column shows the AI label, and what the caption
 * must name.
 *
 * @return array<string, array{file: string, state: string, label: bool, credits: list<string>}>
 */
function previewImages(): array
{
    return [
        'google-20250919-pixel10-npld-picnic-table' => ['file' => fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'), 'state' => 'Trusted', 'label' => false, 'credits' => ['Wikimedia Commons', 'Bureau of Land Management', 'public domain']],
        'openai-20260826-c2pa_2x' => ['file' => fixturePath('openai-20260826-c2pa_2x.png'), 'state' => 'Trusted', 'label' => true, 'credits' => ['richardwooding/c2pa', 'MIT']],
        'amazon-20240925-titan-g1' => ['file' => fixturePath('amazon-20240925-titan-g1.png'), 'state' => 'Valid', 'label' => true, 'credits' => ['TrustNXT/c2pa-ts', 'Apache-2.0']],
        'openai-20260826-c2pa_2x-altered' => ['file' => tamperedOpenAiPng(), 'state' => 'Invalid', 'label' => false, 'credits' => ['richardwooding/c2pa', 'MIT', 'one byte']],
        'fixture-unsigned' => ['file' => fixturePath('fixture-unsigned.jpg'), 'state' => 'none', 'label' => false, 'credits' => ['provemark/c2pa-verifier', 'MIT']],
    ];
}

/**
 * Runs a blueprint in Playground CLI with the plugin from the local build
 * instead of the directory, then $extraSteps, and returns the exit code,
 * the output and what the run left in /out: per attachment title, the
 * stored result, the Media Library column, the caption and the SHA-256 of
 * the original file; when the plugin's queue is next scheduled, and the
 * time then; and one line per admin request logged by previewRequestLog().
 *
 * @param  array<mixed>  $blueprint
 * @param  list<array<mixed>>  $extraSteps
 * @return array{exit: int, output: string, results: array<mixed>, next_check: mixed, now: mixed, requests: list<mixed>, out: string}
 */
function runPreview(array $blueprint, array $extraSteps = []): array
{
    $root = dirname(__DIR__, 2);
    $out = tmpDir().'/preview-'.bin2hex(random_bytes(4));
    mkdir($out);

    $steps = [];
    foreach (is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : [] as $step) {
        $steps[] = is_array($step) && ($step['step'] ?? null) === 'installPlugin'
            ? ['step' => 'activatePlugin', 'pluginPath' => 'tracefern-image-check-for-c2pa/tracefern-image-check-for-c2pa.php']
            : $step;
    }
    array_push($steps, ...$extraSteps);
    $steps[] = ['step' => 'runPHP', 'code' => <<<'PHP'
        <?php
        require '/wordpress/wp-load.php';
        $results = [];
        foreach (get_posts(['post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any']) as $post) {
            ob_start();
            do_action('manage_media_custom_column', 'tracefern', $post->ID);
            $results[$post->post_title] = [
                'entry' => get_post_meta($post->ID, '_tracefern_result', true),
                'column' => (string) ob_get_clean(),
                'caption' => $post->post_excerpt,
                'sha256' => hash_file('sha256', (string) wp_get_original_image_path($post->ID)),
            ];
        }
        file_put_contents('/out/results.json', json_encode($results));
        file_put_contents('/out/cron.json', json_encode(['next_check' => wp_next_scheduled('tracefern_check'), 'now' => time()]));
        PHP];
    $blueprint['steps'] = $steps;
    file_put_contents($out.'/blueprint.json', json_encode($blueprint));

    $command = escapeshellarg($root.'/node_modules/.bin/wp-playground-cli').' run-blueprint'
        .' --blueprint='.escapeshellarg($out.'/blueprint.json')
        .' --mount='.escapeshellarg($root.'/build/tracefern-image-check-for-c2pa:/wordpress/wp-content/plugins/tracefern-image-check-for-c2pa')
        .' --mount='.escapeshellarg($out.':/out')
        .' 2>&1';
    exec($command, $lines, $exit);
    $decoded = is_file($out.'/results.json') ? json_decode((string) file_get_contents($out.'/results.json'), true) : null;
    $cron = is_file($out.'/cron.json') ? json_decode((string) file_get_contents($out.'/cron.json'), true) : null;
    $requests = [];
    foreach (is_file($out.'/requests.jsonl') ? (file($out.'/requests.jsonl', FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        $requests[] = json_decode($line, true);
    }

    return [
        'exit' => $exit,
        'output' => implode("\n", $lines),
        'results' => is_array($decoded) ? $decoded : [],
        'next_check' => is_array($cron) ? ($cron['next_check'] ?? null) : null,
        'now' => is_array($cron) ? ($cron['now'] ?? null) : null,
        'requests' => $requests,
        'out' => $out,
    ];
}

/**
 * A test-only must-use plugin: for each wp-admin request it appends the
 * URI, the response code, the user, any PHP warning, notice or fatal
 * error, and when the plugin's queue is next scheduled at the end of the
 * request, to /out/requests.jsonl. Loaded after the preview's own must-use
 * plugin (the name sorts last), before any admin hook runs.
 *
 * @return array<mixed>
 */
function previewRequestLog(): array
{
    return ['step' => 'writeFile', 'path' => '/wordpress/wp-content/mu-plugins/zz-tracefern-test-log.php', 'data' => <<<'PHP'
        <?php
        if (! is_admin()) {
            return;
        }
        $GLOBALS['tracefern_test_errors'] = [];
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            if (($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_USER_ERROR)) !== 0) {
                $GLOBALS['tracefern_test_errors'][] = "$message at $file:$line";
            }

            return false;
        });
        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $GLOBALS['tracefern_test_errors'][] = $last['message'];
            }
            file_put_contents('/out/requests.jsonl', json_encode([
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'status' => http_response_code(),
                'user' => get_current_user_id(),
                'errors' => $GLOBALS['tracefern_test_errors'],
                'next_check' => wp_next_scheduled('tracefern_check'),
                'now' => time(),
            ])."\n", FILE_APPEND);
        });
        PHP];
}

/**
 * A load of the Media Library as the logged-in admin, through WordPress's
 * own wp-admin/admin.php, so admin_init runs as for a visitor. Playground
 * CLI 3.1.55 no longer runs the `request` step (measured 2026-09-28: "The
 * "request" Blueprint is no longer supported"), so this is a runPHP step:
 * it skips Playground's auto-login (which redirects on init without its
 * cookie), sets the admin's auth cookies, and resolves the user again
 * from them before admin.php checks them.
 *
 * @return array<mixed>
 */
function previewAdminRequest(): array
{
    return ['step' => 'runPHP', 'code' => <<<'PHP'
        <?php
        define('WP_ADMIN', true);
        $_SERVER['REQUEST_URI'] = '/wp-admin/upload.php?mode=list';
        $_SERVER['PHP_SELF'] = '/wp-admin/upload.php';
        $_GET['mode'] = 'list';
        $_COOKIE['playground_auto_login_already_happened'] = '1';
        require '/wordpress/wp-load.php';
        $expires = time() + HOUR_IN_SECONDS;
        $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie(1, $expires, 'auth');
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie(1, $expires, 'logged_in');
        $GLOBALS['current_user'] = null;
        wp_get_current_user();
        ob_start();
        require ABSPATH.'wp-admin/upload.php';
        ob_end_clean();
        PHP];
}

/**
 * The run of the blueprint as committed; once per test file, as it takes
 * a while.
 *
 * @return array{exit: int, output: string, results: array<mixed>, next_check: mixed, now: mixed, requests: list<mixed>, out: string}
 */
function previewRun(): array
{
    /** @var array{exit: int, output: string, results: array<mixed>, next_check: mixed, now: mixed, requests: list<mixed>, out: string}|null $run */
    static $run = null;

    return $run ??= runPreview(blueprint());
}

/**
 * One attachment's part of the run.
 *
 * @return array<mixed>
 */
function previewResult(string $title): array
{
    $result = previewRun()['results'][$title] ?? null;

    return is_array($result) ? $result : [];
}

/**
 * One text field of an attachment's part of the run, or ''.
 */
function previewText(string $title, string $field): string
{
    $text = previewResult($title)[$field] ?? null;

    return is_string($text) ? $text : '';
}

it('AC2: shows the verifier\'s verdict for every image', function (): void {
    $run = previewRun();

    expect($run['exit'])->toBe(0, $run['output'])
        ->and(array_keys($run['results']))->toEqualCanonicalizing(array_keys(previewImages()));

    foreach (previewImages() as $title => $image) {
        $result = previewResult($title);
        $entry = is_array($result['entry'] ?? null) ? $result['entry'] : [];

        expect($result['sha256'] ?? null)->toBe(hash_file('sha256', $image['file']), $title)
            ->and($entry['state'] ?? null)->toBe($image['state'], $title)
            ->and(stable($entry))->toBe(expectedEntry($image['file'], defaultSettingsFile()), $title);
    }
})->group('SPEC-024');

it('AC3: shows the AI label only where it may be shown', function (): void {
    foreach (previewImages() as $title => $image) {
        $column = visibleText(previewText($title, 'column'));

        expect($column)->not->toBe('', $title);
        $image['label']
            ? expect($column)->toContain('AI-generated (signed)')
            : expect($column)->not->toContain('AI-generated');
    }

    $altered = previewResult('openai-20260826-c2pa_2x-altered')['entry'] ?? [];
    expect(is_array($altered) ? ($altered['ai'] ?? null) : null)->toBeTrue();
})->group('SPEC-024');

it('AC4: credits each image in its caption', function (): void {
    foreach (previewImages() as $title => $image) {
        $caption = previewText($title, 'caption');

        foreach ($image['credits'] as $credit) {
            expect($caption)->toContain($credit);
        }
    }
})->group('SPEC-024');

it('AC5: stops at a missing image', function (): void {
    $blueprint = blueprint();
    $missing = 'https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/does-not-exist.png';
    $steps = is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : [];
    foreach ($steps as $i => $step) {
        $data = is_array($step) && is_array($step['data'] ?? null) ? $step['data'] : [];
        if (is_array($step) && ($step['step'] ?? null) === 'writeFile' && ($data['resource'] ?? null) === 'url') {
            $step['data'] = ['resource' => 'url', 'url' => $missing];
            $steps[$i] = $step;
            break;
        }
    }
    $blueprint['steps'] = $steps;
    $run = runPreview($blueprint);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('Error when executing the blueprint step #')
        ->toContain('Could not download "'.$missing.'"')
        ->and($run['results'])->toBe([]);
})->group('SPEC-024');

/**
 * The run for amendment 1: the preview after setup, then (a) an admin page
 * with nothing scheduled; a visitor's upload, imported without running
 * cron, and an admin page; then the plugin deactivated, an event
 * scheduled in the past, its time noted in /out/scheduled.json, and (b)
 * an admin page.
 *
 * @return array{exit: int, output: string, results: array<mixed>, next_check: mixed, now: mixed, requests: list<mixed>, out: string, scheduled: mixed}
 */
function visitorRun(): array
{
    /** @var array{exit: int, output: string, results: array<mixed>, next_check: mixed, now: mixed, requests: list<mixed>, out: string, scheduled: mixed}|null $run */
    static $run = null;
    if ($run !== null) {
        return $run;
    }

    $result = runPreview(blueprint(), [
        previewRequestLog(),
        previewAdminRequest(),
        ['step' => 'writeFile', 'path' => '/tmp/visitor-upload.jpg', 'data' => ['resource' => 'url', 'url' => 'https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/fixture-signed.jpg']],
        ['step' => 'wp-cli', 'command' => 'wp media import /tmp/visitor-upload.jpg --title=visitor-upload'],
        previewAdminRequest(),
        ['step' => 'wp-cli', 'command' => 'wp plugin deactivate tracefern-image-check-for-c2pa'],
        ['step' => 'runPHP', 'code' => "<?php require '/wordpress/wp-load.php'; \$at = time() - 10; wp_schedule_single_event(\$at, 'tracefern_check'); file_put_contents('/out/scheduled.json', json_encode(wp_next_scheduled('tracefern_check')));"],
        previewAdminRequest(),
    ]);
    $scheduled = is_file($result['out'].'/scheduled.json') ? json_decode((string) file_get_contents($result['out'].'/scheduled.json'), true) : null;

    return $run = $result + ['scheduled' => $scheduled];
}

it('AC6 (amendment 1): checks a visitor\'s upload on the next admin page', function (): void {
    $run = visitorRun();
    $visitor = $run['results']['visitor-upload'] ?? null;
    $entry = is_array($visitor) ? ($visitor['entry'] ?? null) : null;
    $requests = $run['requests'];
    $afterUpload = is_array($requests[1] ?? null) ? $requests[1] : [];

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($afterUpload['user'] ?? null)->toBe(1)
        ->and($afterUpload['status'] ?? null)->toBe(200)
        ->and(is_array($entry) ? ($entry['state'] ?? null) : null)->toBe('Valid')
        ->and(is_int($afterUpload['next_check'] ?? null) && $afterUpload['next_check'] <= ($afterUpload['now'] ?? 0))->toBeFalse()
        ->and(stable(is_array($entry) ? $entry : []))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile()));
})->group('SPEC-024');

it('AC7 (amendment 1): does nothing when there is nothing to do, or no plugin', function (): void {
    $run = visitorRun();
    $requests = $run['requests'];

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($requests)->toHaveCount(3);
    foreach ($requests as $request) {
        expect(is_array($request) ? $request : [])->toMatchArray(['status' => 200, 'user' => 1, 'errors' => []]);
    }

    // (b): with the plugin inactive, the event scheduled in the past is
    // still there, at the same time.
    expect($run['scheduled'])->toBeInt()
        ->and($run['next_check'])->toBe($run['scheduled'])
        ->and($run['next_check'] < $run['now'])->toBeTrue();
})->group('SPEC-024');
