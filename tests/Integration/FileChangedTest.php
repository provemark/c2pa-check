<?php

declare(strict_types=1);

it('AC1: an image edited in WordPress is checked again', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    expect(storedEntry($id)['state'] ?? null)->toBe('Valid');

    editImage($id);

    expect(currentOriginal($id))->toMatch('/-e\d+\.jpg$/')
        ->and(storedEntry($id))->toBeNull()
        ->and(pendingMarker($id))->not->toBeNull()
        ->and(scheduledChecks($id))->toBe(1)
        ->and(runPendingChecks())->toBe(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(hostCopyOfOriginal($id), defaultSettingsFile()))
        ->and(storedEntry($id)['file'] ?? null)->toBe(currentOriginal($id));
})->group('SPEC-014');

it('AC2: restoring the original checks it again', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    editImage($id);
    runPendingChecks();
    expect(storedEntry($id)['state'] ?? null)->not->toBe('Valid');

    restoreImage($id);

    expect(runPendingChecks())->toBe(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile()))
        ->and(storedEntry($id)['file'] ?? null)->toBe(currentOriginal($id))
        ->and(currentOriginal($id))->not->toMatch('/-e\d+\.jpg$/');
})->group('SPEC-014');

it('AC3: an edit before the first check', function (): void {
    runPendingChecks(); // anything an earlier test left scheduled
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    editImage($id);

    expect(runPendingChecks())->toBeGreaterThanOrEqual(1)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(hostCopyOfOriginal($id), defaultSettingsFile()))
        ->and(storedEntry($id)['file'] ?? null)->toBe(currentOriginal($id));
})->group('SPEC-014');

it('AC4: a file changed without WordPress knowing', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    $other = base64_encode((string) file_get_contents(fixturePath('fixture-unsigned.jpg')));
    // Overwritten in place, as a plugin or FTP might: no WordPress call.
    wpEval("file_put_contents(wp_get_original_image_path($id), base64_decode('$other')); touch(wp_get_original_image_path($id), time() + 5);");

    $column = visibleText(columnHtml($id));
    $details = visibleText(detailsHtml($id, true));

    expect($column)->toContain('Changed since its check')
        ->and($column)->not->toContain('Intact')
        ->and($details)->toContain('Changed since its check')
        ->and($details)->not->toContain('Signer')
        ->and($details)->not->toContain('AI-generated');

    wpCli(['provemark-c2pa', 'check', (string) $id]);

    expect(visibleText(columnHtml($id)))->toContain('No Content Credentials')
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check');
})->group('SPEC-014');

it('AC5: an ordinary upload is checked once, and records its file', function (): void {
    runPendingChecks(); // anything an earlier test left scheduled
    $id = importWithoutChecking(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'));

    expect(scheduledChecks($id))->toBe(1)
        ->and(runPendingChecks())->toBe(1);

    $entry = storedEntry($id) ?? [];

    expect($entry['state'] ?? null)->toBe('Trusted')
        ->and($entry['file'] ?? null)->toBe(currentOriginal($id))
        ->and($entry['file'] ?? '')->not->toContain('-scaled')
        ->and($entry['size'] ?? null)->toBe(filesize(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg')))
        ->and(is_int($entry['modified'] ?? null))->toBeTrue()
        ->and(visibleText(columnHtml($id)))->toContain('Verified')
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check')
        ->and(scheduledChecks($id))->toBe(0);
})->group('SPEC-014');

it('AC6: the command records the same file', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    $background = storedEntry($id) ?? [];
    wpCli(['provemark-c2pa', 'check', (string) $id]);
    $command = storedEntry($id) ?? [];

    expect($background['file'] ?? null)->not->toBeNull()
        ->and([$command['file'] ?? null, $command['size'] ?? null, $command['modified'] ?? null])
        ->toBe([$background['file'] ?? null, $background['size'] ?? null, $background['modified'] ?? null]);
})->group('SPEC-014');

it('AC7: uninstall removes the kept path', function (): void {
    importWithoutChecking(fixturePath('fixture-signed.jpg'));
    $count = "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_provemark_c2pa_source'\");";

    expect((int) wpEval($count))->toBeGreaterThan(0);
    wpEval(UNINSTALL_PLUGIN_PHP);
    expect((int) wpEval($count))->toBe(0);
})->group('SPEC-014');
