<?php

declare(strict_types=1);

it('AC1: many uploads, one event', function (): void {
    runPendingChecks(); // anything an earlier test left scheduled
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-signed.jpg')), range(1, 5));

    expect(queueEvents())->toBe(['events' => 1, 'with_args' => 0]);
    foreach ($ids as $id) {
        expect(pendingMarker($id))->not->toBeNull();
    }

    runPendingChecks();
})->group('SPEC-017');

it('AC2: the queue checks them all', function (): void {
    runPendingChecks();
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-signed.jpg')), range(1, 5));

    expect(runPendingChecks())->toBe(5);
    foreach ($ids as $id) {
        expect(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile()))
            ->and(pendingMarker($id))->toBeNull();
    }
    expect(queueEvents()['events'])->toBe(0);
})->group('SPEC-017');

it('AC3: in batches of 20', function (): void {
    runPendingChecks();
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-unsigned.jpg')), range(1, 21));

    expect(runPendingChecks())->toBe(20)
        ->and(count(array_filter($ids, fn (int $id): bool => pendingMarker($id) !== null)))->toBe(1)
        ->and(queueEvents()['events'])->toBe(1)
        ->and(runPendingChecks())->toBe(1)
        ->and(queueEvents()['events'])->toBe(0);
})->group('SPEC-017');

it('AC4: a run that dies loses nothing', function (): void {
    runPendingChecks();
    $ids = array_map(fn (): int => importWithoutChecking(fixturePath('fixture-signed.jpg')), range(1, 3));
    // Dies in the first check only, quickly: a low limit, then memory filled.
    $mu = '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (doing_action("provemark_c2pa_check") && get_option("provemark_test_die_once")) { delete_option("provemark_test_die_once"); ini_set("memory_limit", "32M"); $a = []; while (true) { $a[] = str_repeat("x", 1 << 20); } } return $v; });';
    $payload = base64_encode($mu);
    wpEval("file_put_contents(WPMU_PLUGIN_DIR.'/provemark-test-die-once.php', base64_decode('$payload')); update_option('provemark_test_die_once', 1, false);");
    try {
        $died = runPendingChecksOutput();
        $states = array_map(fn (int $id): ?string => is_string(storedEntry($id)['state'] ?? null) ? storedEntry($id)['state'] : null, $ids);
        // Which image dies first depends on markers set in the same second:
        // compare the counts, not their order (CI of da84e45 failed on it).
        $counts = array_count_values(array_map(fn (?string $s): string => $s ?? 'none yet', $states));
        ksort($counts);

        expect($died)->not->toContain('RAN:')
            ->and($counts)->toBe(['error' => 1, 'none yet' => 2])
            ->and(queueEvents()['events'])->toBe(1)
            ->and(wpEval("echo wp_next_scheduled('provemark_c2pa_check') > time() ? 'later' : 'now';"))->toBe('later')
            ->and(runPendingChecks())->toBe(2);
        foreach ($ids as $id) {
            expect(storedEntry($id)['state'] ?? null)->toBeIn(['error', 'Valid']);
        }
        expect(count(array_filter($ids, fn (int $id): bool => (storedEntry($id)['state'] ?? null) === 'Valid')))->toBe(2);
    } finally {
        wpEval("@unlink(WPMU_PLUGIN_DIR.'/provemark-test-die-once.php'); delete_option('provemark_test_die_once');");
    }
})->group('SPEC-017');

it('AC6: pending while the queue runs', function (): void {
    runPendingChecks();
    $id = attachmentWithEntry(null);
    wpEval("update_post_meta($id, '_provemark_c2pa_pending', time() - 7200); wp_schedule_single_event(time() + 3600, 'provemark_c2pa_check');");

    expect(visibleText(columnHtml($id)))->toContain('Check pending')
        ->and(listedIds([$id], ['provemark_c2pa' => 'pending'])['ids'])->toBe([$id]);

    wpEval("wp_unschedule_hook('provemark_c2pa_check');");

    expect(visibleText(columnHtml($id)))->toContain('Not checked')
        ->and(listedIds([$id], ['provemark_c2pa' => 'unchecked'])['ids'])->toBe([$id]);
})->group('SPEC-017');
