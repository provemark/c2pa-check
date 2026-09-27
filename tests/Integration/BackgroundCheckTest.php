<?php

declare(strict_types=1);

const PIXEL_PHOTO = 'google-20250919-pixel10-npld-picnic-table.jpg';

it('AC1: an upload schedules the check and does not run it', function (): void {
    // A fresh cron lock: for 60 s no request spawns WP-Cron, so nothing but
    // the upload request itself could have checked the file.
    wpEval('set_transient("doing_cron", sprintf("%.22F", microtime(true)));');
    $upload = httpUpload('rest', fixturePath('fixture-signed.jpg'), 'm13-pending.jpg');
    $id = $upload['id'];

    expect($upload['status'])->toBe(201)
        ->and($id)->toBeGreaterThan(0)
        ->and(storedEntry($id))->toBeNull()
        ->and(abs((pendingMarker($id) ?? 0) - time()))->toBeLessThan(120)
        ->and(scheduledChecks($id))->toBe(1)
        ->and(visibleText(columnHtml($id)))->toContain('Check pending');

    wpEval("delete_transient('doing_cron');");
    runPendingChecks();
})->group('SPEC-013');

it('AC2: the scheduled check gives the verdict the upload gave before', function (string $fixture): void {
    $id = importWithoutChecking(fixturePath($fixture));

    expect(storedEntry($id))->toBeNull()
        ->and(runPendingChecks())->toBe(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath($fixture), defaultSettingsFile()))
        ->and(pendingMarker($id))->toBeNull()
        ->and(scheduledChecks($id))->toBe(0);
})->with(['fixture-signed.jpg', PIXEL_PHOTO])->group('SPEC-013');

it('AC3: a check that dies does not break the upload', function (): void {
    [$rest, $async] = withCheckThatDies(function (): array {
        $rest = httpUpload('rest', fixturePath(PIXEL_PHOTO), 'm13-dies-rest.jpg');
        $async = httpUpload('async', fixturePath(PIXEL_PHOTO), 'm13-dies-async.jpg');
        // A check that dies ends its cron request; the next due check runs
        // in the next one, as with wp-cron.php.
        runPendingChecks();
        runPendingChecks();

        return [$rest, $async];
    });
    $asyncJson = json_decode($async['body'], true);

    expect($rest['status'])->toBe(201)
        ->and($async['status'])->toBe(200)
        ->and(is_array($asyncJson) ? ($asyncJson['success'] ?? null) : null)->toBeTrue();
    foreach ([$rest['id'], $async['id']] as $id) {
        expect(imageSizes($id))->toBeGreaterThan(0)
            ->and(storedEntry($id)['state'] ?? null)->toBe('error')
            ->and(storedEntry($id)['reason'] ?? null)->toBe('interrupted')
            ->and(visibleText(columnHtml($id)))->toContain('Could not be checked')
            ->and(visibleText(columnHtml($id)))->not->toContain('Check pending');
    }
})->group('SPEC-013');

it('AC4: the check runs with the raised memory limit', function (): void {
    wpEval(<<<'PHP'
        delete_option('provemark_test_memory');
        file_put_contents(WPMU_PLUGIN_DIR.'/provemark-test-memory.php', '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (doing_action("provemark_c2pa_check")) { update_option("provemark_test_memory", ini_get("memory_limit"), false); } return $v; });');
        PHP);
    try {
        importWithoutChecking(fixturePath('fixture-signed.jpg'));
        // An HTTP request to wp-cron.php as spawn_cron() makes it (not
        // WP-CLI): the lock set first, and passed as doing_wp_cron.
        $key = wpEval('$key = sprintf("%.22F", microtime(true)); set_transient("doing_cron", $key); echo $key;');
        exec('curl -s -o /dev/null --max-time 120 '.escapeshellarg(TEST_SITE.'/wp-cron.php?doing_wp_cron='.$key));

        expect(wpEval("echo get_option('provemark_test_memory');"))->toBe(wpEval('echo WP_MAX_MEMORY_LIMIT;'));
    } finally {
        wpEval('@unlink(WPMU_PLUGIN_DIR."/provemark-test-memory.php"); delete_option("provemark_test_memory");');
    }
})->group('SPEC-013');

it('AC5: a lost event does not say "pending" forever', function (): void {
    $lost = attachmentWithEntry(null);
    $fresh = attachmentWithEntry(null);
    // A lost event: no queue scheduled (SPEC-017 keeps a marker pending
    // while the queue is, AC6 there).
    wpEval("update_post_meta($lost, '_provemark_c2pa_pending', time() - 3601); update_post_meta($fresh, '_provemark_c2pa_pending', time()); wp_unschedule_hook('provemark_c2pa_check');");
    $unchecked = json_decode((string) preg_replace('/^[^\[]*/s', '', wpCli(['provemark-c2pa', 'check', '--unchecked', '--dry-run', '--format=json'])['output']), true);
    $listed = array_map(fn (mixed $row): mixed => is_array($row) ? ($row['id'] ?? null) : null, is_array($unchecked) ? $unchecked : []);

    expect(scheduledChecks($lost))->toBe(0)
        ->and(visibleText(columnHtml($lost)))->toContain('Not checked')
        ->and(visibleText(detailsHtml($lost, true)))->toContain('Not checked')
        ->and(visibleText(columnHtml($fresh)))->toContain('Check pending')
        ->and(visibleText(detailsHtml($fresh, false)))->toContain('Check pending')
        ->and($listed)->toContain($lost);
})->group('SPEC-013');

it('AC6: an image deleted before its check', function (): void {
    // Markers an earlier test left without a queue (AC5) would be counted.
    wpEval("delete_post_meta_by_key('_provemark_c2pa_pending');");
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    wpEval("wp_delete_attachment($id, true);");

    // Deleting it took its marker with it: the queue finds nothing to check
    // for it, and stores nothing (SPEC-017 counts checks, not events).
    expect(runPendingChecksOutput())->toBe('RAN:0')
        ->and(storedEntry($id))->toBeNull()
        ->and(wpEval("echo get_post_type($id) === false ? 'gone' : 'there';"))->toBe('gone');
})->group('SPEC-013');

it('AC9: the check reads the original even in the -scaled window', function (): void {
    $id = importWithoutChecking(fixturePath(PIXEL_PHOTO));
    // The state _wp_image_meta_replace_original() leaves between switching
    // the attached file to -scaled and saving the metadata that names the
    // original (SPEC-013 amendment 1).
    wpEval("\$meta = wp_get_attachment_metadata($id); unset(\$meta['original_image']); update_post_meta($id, '_wp_attachment_metadata', \$meta);");

    expect(wpEval("echo basename((string) wp_get_original_image_path($id));"))->toEndWith('-scaled.jpg')
        ->and(runPendingChecks())->toBe(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath(PIXEL_PHOTO), defaultSettingsFile()));
})->group('SPEC-013');

it('AC10: a late check in a batch waits instead of dying', function (): void {
    runPendingChecks(); // anything an earlier test left scheduled
    wpEval(<<<'PHP'
        file_put_contents(WPMU_PLUGIN_DIR.'/provemark-test-time-limit.php', '<?php if (defined("DOING_CRON") && DOING_CRON) { ini_set("max_execution_time", "3"); set_time_limit(3); } add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (doing_action("provemark_c2pa_check")) { $end = microtime(true) + 2; while (microtime(true) < $end) { } } return $v; });');
        PHP);
    $cron = function (): void {
        // wp-cron.php as spawn_cron() calls it: the lock set, and passed.
        $key = wpEval('$key = sprintf("%.22F", microtime(true)); set_transient("doing_cron", $key); echo $key;');
        exec('curl -s -o /dev/null --max-time 120 '.escapeshellarg(TEST_SITE.'/wp-cron.php?doing_wp_cron='.$key));
    };
    try {
        $ids = [importWithoutChecking(fixturePath('fixture-signed.jpg')), importWithoutChecking(fixturePath('fixture-signed.jpg'))];
        $cron();
        $afterFirst = array_map(fn (int $id): ?string => is_string(storedEntry($id)['state'] ?? null) ? storedEntry($id)['state'] : null, $ids);
        $waiting = array_values(array_filter($ids, fn (int $id): bool => storedEntry($id) === null));

        expect($afterFirst)->toContain('Valid')
            ->and($afterFirst)->not->toContain('error')
            ->and(count($waiting))->toBe(1)
            ->and(scheduledChecks($waiting[0] ?? 0))->toBe(1);

        $cron();
        foreach ($ids as $id) {
            expect(storedEntry($id)['state'] ?? null)->toBe('Valid');
        }
    } finally {
        wpEval('@unlink(WPMU_PLUGIN_DIR."/provemark-test-time-limit.php");');
    }
})->group('SPEC-013', 'SPEC-017');

it('AC11: the command removes the scheduled check it made unnecessary', function (): void {
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));

    expect(scheduledChecks($id))->toBe(1);
    wpCli(['provemark-c2pa', 'check', (string) $id]);
    expect(scheduledChecks($id))->toBe(0)
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid');
})->group('SPEC-013');

it('AC12: without a time, the display uses the current one', function (): void {
    expect(visibleText(wpEval('echo Provemark\\C2paCheck\\Display::headline(null, time() - 7200);')))->toBe('Not checked');
})->group('SPEC-013');
