<?php

declare(strict_types=1);

const PIXEL = 'google-20250919-pixel10-npld-picnic-table.jpg';

/**
 * Runs $test with a must-use plugin in the test environment; removed afterwards.
 */
function withTestPlugin(string $name, string $code, callable $test): void
{
    $payload = base64_encode($code);
    wpEval("if (! is_dir(WPMU_PLUGIN_DIR)) { mkdir(WPMU_PLUGIN_DIR, 0777, true); } file_put_contents(WPMU_PLUGIN_DIR.'/$name.php', base64_decode('$payload'));");
    try {
        $test();
    } finally {
        wpEval("@unlink(WPMU_PLUGIN_DIR.'/$name.php');");
    }
}

it('AC1: a symlinked uploads folder', function (): void {
    runPendingChecks();
    wpEval("\$base = wp_get_upload_dir()['basedir']; if (! file_exists(\$base.'-link')) { symlink(\$base, \$base.'-link'); }");
    $mu = '<?php add_filter("upload_dir", static function ($dir) { foreach (["basedir", "path"] as $k) { $dir[$k] = preg_replace("#/uploads(?=/|$)#", "/uploads-link", $dir[$k], 1); } return $dir; });';
    try {
        withTestPlugin('provemark-test-symlinked-uploads', $mu, function (): void {
            expect(wpEval("echo wp_get_upload_dir()['basedir'];"))->toEndWith('/uploads-link');
            $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
            runPendingChecks();

            expect(storedEntry($id)['file'] ?? '')->not->toStartWith('/')
                ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
                ->and(visibleText(columnHtml($id)))->toContain('Intact')
                ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check');

            wpEval("wp_update_attachment_metadata($id, wp_get_attachment_metadata($id));");

            expect(pendingMarker($id))->toBeNull()
                ->and(storedEntry($id)['state'] ?? null)->toBe('Valid');
        });
    } finally {
        wpEval("@unlink(wp_get_upload_dir()['basedir'].'-link');");
    }
})->group('SPEC-018');

it('AC2: an edited scaled image is checked again, on what visitors see', function (): void {
    runPendingChecks();
    $id = importMedia(fixturePath(PIXEL));
    expect(storedEntry($id)['state'] ?? null)->toBe('Trusted')
        ->and(attachedFile($id))->toEndWith('-scaled.jpg');

    editImage($id);

    expect(attachedFile($id))->toMatch('/-scaled-e\d+\.jpg$/')
        ->and(storedEntry($id))->toBeNull()
        ->and(runPendingChecks())->toBe(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(hostCopyOfUpload(attachedFile($id)), defaultSettingsFile()))
        ->and(storedEntry($id)['file'] ?? null)->toBe(attachedFile($id));

    wpCli(['provemark-c2pa', 'check', (string) $id]);
    expect(storedEntry($id)['file'] ?? null)->toBe(attachedFile($id));

    restoreImage($id);

    expect(runPendingChecks())->toBe(1)
        ->and(storedEntry($id)['state'] ?? null)->toBe('Trusted')
        ->and(storedEntry($id)['file'] ?? '')->not->toContain('-scaled');
})->group('SPEC-018');

it('AC3: an edit during a running check', function (): void {
    runPendingChecks();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    $mu = '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { $id = (int) get_option("provemark_test_edit_id"); if ($id > 0 && doing_action("provemark_c2pa_check")) { delete_option("provemark_test_edit_id"); require_once ABSPATH."wp-admin/includes/image-edit.php"; require_once ABSPATH."wp-admin/includes/image.php"; $_REQUEST["history"] = wp_json_encode([["r" => 90]]); $_REQUEST["target"] = "all"; $_REQUEST["context"] = ""; wp_save_image($id); } return $v; });';
    withTestPlugin('provemark-test-edit-during-check', $mu, function () use ($id): void {
        wpEval("update_option('provemark_test_edit_id', $id, false);");
        runPendingChecks();
    });

    expect(attachedFile($id))->toMatch('/-e\d+\.jpg$/')
        ->and(storedEntry($id))->toBeNull()
        ->and(pendingMarker($id))->not->toBeNull()
        ->and(runPendingChecks())->toBe(1)
        ->and(storedEntry($id)['file'] ?? null)->toBe(attachedFile($id))
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(hostCopyOfUpload(attachedFile($id)), defaultSettingsFile()));
})->group('SPEC-018');

it('AC4: overlapping runs check each image once', function (): void {
    runPendingChecks();
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-signed.jpg')), range(1, 3));
    // Counts every check; during the first one, starts a second queue run.
    $mu = '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (! doing_action("provemark_c2pa_check")) { return $v; } $n = (int) get_option("provemark_test_checks", 0); update_option("provemark_test_checks", $n + 1, false); if (get_option("provemark_test_nest")) { delete_option("provemark_test_nest"); do_action("provemark_c2pa_check"); } return $v; });';
    withTestPlugin('provemark-test-overlap', $mu, function (): void {
        wpEval("update_option('provemark_test_nest', 1, false); delete_option('provemark_test_checks');");
        runPendingChecks();
    });

    expect((int) wpEval("echo get_option('provemark_test_checks'); delete_option('provemark_test_checks');"))->toBe(3);
    foreach ($ids as $id) {
        expect(storedEntry($id)['state'] ?? null)->toBe('Valid');
    }
})->group('SPEC-018');

it('AC5: every run checks at least one image', function (): void {
    runPendingChecks();
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-signed.jpg')), range(1, 2));
    // In the cron request: a 4 s limit, and 2.5 s of it used before the queue.
    $mu = '<?php if (defined("DOING_CRON") && DOING_CRON) { ini_set("max_execution_time", "4"); set_time_limit(4); add_action("init", static function () { $end = microtime(true) + 2.5; while (microtime(true) < $end) { } }, 0); }';
    withTestPlugin('provemark-test-late-queue', $mu, function (): void {
        $key = wpEval('$key = sprintf("%.22F", microtime(true)); set_transient("doing_cron", $key); echo $key;');
        exec('curl -s -o /dev/null --max-time 120 '.escapeshellarg(TEST_SITE.'/wp-cron.php?doing_wp_cron='.$key));
    });
    $checked = array_filter($ids, fn (int $id): bool => (storedEntry($id)['state'] ?? null) === 'Valid');

    expect(count($checked))->toBeGreaterThanOrEqual(1);
    runPendingChecks();
})->group('SPEC-018');

it('AC10: a JPEG saved as WebP is checked on its original', function (): void {
    runPendingChecks();
    $mu = '<?php add_filter("image_editor_output_format", static fn ($formats) => ["image/jpeg" => "image/webp"] + (array) $formats);';
    withTestPlugin('provemark-test-webp-output', $mu, function (): void {
        $id = importWithoutChecking(fixturePath(PIXEL));
        runPendingChecks();

        expect(attachedFile($id))->toEndWith('-scaled.webp')
            ->and(storedEntry($id)['state'] ?? null)->toBe('Trusted')
            ->and(storedEntry($id)['file'] ?? '')->toEndWith('.jpg');
    });
})->group('SPEC-018');

it('AC11: deleted right after the provisional entry', function (): void {
    runPendingChecks();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    $mu = '<?php add_filter("update_post_metadata", static function ($check, $id, $key, $value) { if ($key === "_provemark_c2pa_result" && (int) get_option("provemark_test_delete_now") === $id && is_array($value) && ($value["reason"] ?? null) === "interrupted") { delete_option("provemark_test_delete_now"); wp_delete_attachment($id, true); } return $check; }, 10, 4);';
    withTestPlugin('provemark-test-delete-at-start', $mu, function () use ($id): void {
        wpEval("update_option('provemark_test_delete_now', $id, false);");
        runPendingChecks();
    });

    expect(wpEval("echo get_post_type($id) === false ? 'gone' : 'there';"))->toBe('gone')
        ->and(wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE post_id = $id AND meta_key LIKE '\\\\_provemark\\\\_c2pa\\\\_%'\");"))->toBe('0');
})->group('SPEC-018');
