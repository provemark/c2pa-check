<?php

declare(strict_types=1);

/**
 * The kept path (`_tracefern_source`), relative to the uploads folder.
 */
function keptPath(int $id): string
{
    return wpEval("echo get_post_meta($id, '_tracefern_source', true);");
}

/**
 * Whether the file at a path relative to the uploads folder is
 * byte-identical to a host file.
 */
function sameAsHostFile(string $relative, string $hostPath): bool
{
    return hash_file('sha256', hostCopyOfUpload($relative)) === hash_file('sha256', $hostPath);
}

it('AC1: a rotated upload on the browser route is checked on the upload', function (): void {
    $source = fixturePath('c2pa-rs-no_alg.jpg');
    $id = browserUpload($source, rotated: true, scaled: false);

    // The replay gives what the browser gave (notes/exif-rotation.md).
    expect(attachedFile($id))->toMatch('/-rotated-1\.jpg$/');

    runPendingChecks();

    expect(sameAsHostFile(keptPath($id), $source))->toBeTrue()
        ->and(storedEntry($id)['file'] ?? null)->toBe(keptPath($id))
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()))
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check');
})->group('SPEC-029');

it('AC2: a rotated and scaled upload', function (): void {
    $source = fixturePath('truepic-20230212-camera.jpg');
    $id = browserUpload($source, rotated: true, scaled: true);

    expect(attachedFile($id))->toMatch('/-scaled-1\.jpg$/')
        ->and(wpEval("echo wp_get_attachment_metadata($id)['original_image'] ?? '';"))->toMatch('/-rotated-1\.jpg$/');

    runPendingChecks();

    expect(sameAsHostFile(keptPath($id), $source))->toBeTrue()
        ->and(storedEntry($id)['file'] ?? null)->toBe(keptPath($id))
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()))
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check');
})->group('SPEC-029');

it('AC3: the browser route without rotation is unchanged', function (): void {
    $source = fixturePath('adobe-20260425-lightroom-classic-church.jpg');
    $id = browserUpload($source, rotated: false, scaled: true);
    runPendingChecks();

    expect(attachedFile($id))->toMatch('/-scaled\.jpg$/')
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()));
})->group('SPEC-029');

it('AC4: an edit still moves the kept path', function (): void {
    $id = browserUpload(fixturePath('c2pa-rs-no_alg.jpg'), rotated: true, scaled: false);
    runPendingChecks();

    editImage($id);

    expect(runPendingChecks())->toBe(1)
        ->and(keptPath($id))->toMatch('/-e\d+\.jpg$/')
        ->and(storedEntry($id)['file'] ?? null)->toBe(keptPath($id))
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(hostCopyOfUpload(keptPath($id)), defaultSettingsFile()));
})->group('SPEC-029');

it('AC5: two uploads with the same name each check their own upload', function (): void {
    $source = fixturePath('c2pa-rs-no_alg.jpg');
    $first = browserUpload($source, rotated: true, scaled: false);
    $second = browserUpload($source, rotated: true, scaled: false);
    runPendingChecks();

    // The second's rotated copy, without its number, names the first's file.
    expect(keptPath($second))->not->toBe(keptPath($first));
    foreach ([$first, $second] as $id) {
        expect(sameAsHostFile(keptPath($id), $source))->toBeTrue()
            ->and(storedEntry($id)['file'] ?? null)->toBe(keptPath($id))
            ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()));
    }
})->group('SPEC-029');

it('AC6: the display and the command use the kept path for a browser copy', function (): void {
    $source = fixturePath('truepic-20230212-camera.jpg');
    $id = browserUpload($source, rotated: true, scaled: true);
    runPendingChecks();
    $background = storedEntry($id) ?? [];

    expect(visibleText(columnHtml($id)))->not->toContain('Changed since its check')
        ->and(visibleText(detailsHtml($id, true)))->not->toContain('Changed since its check');

    wpCli(['tracefern', 'check', (string) $id]);
    $command = storedEntry($id) ?? [];

    expect(stable($command))->toBe(stable($background))
        ->and([$command['file'] ?? null, $command['size'] ?? null, $command['modified'] ?? null])
        ->toBe([$background['file'] ?? null, $background['size'] ?? null, $background['modified'] ?? null])
        ->and(sameAsHostFile(is_string($command['file'] ?? null) ? $command['file'] : '', $source))->toBeTrue();
})->group('SPEC-029');

it('AC8: uninstall removes the recorded browser copies', function (): void {
    browserUpload(fixturePath('c2pa-rs-no_alg.jpg'), rotated: true, scaled: false);
    $count = "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_tracefern_browser_copies'\");";

    expect((int) wpEval($count))->toBeGreaterThan(0);
    wpEval(UNINSTALL_PLUGIN_PHP);
    expect((int) wpEval($count))->toBe(0);
})->group('SPEC-029');

it('AC9: the browser\'s sequence without finalize', function (): void {
    $source = fixturePath('truepic-20230212-camera.jpg');
    $id = browserUpload($source, rotated: true, scaled: true, rounds: 2, finalize: false);

    // As measured: the second scaled copy attached, no original_image.
    expect(attachedFile($id))->toMatch('/-scaled-2\.jpg$/')
        ->and(wpEval("echo wp_get_attachment_metadata($id)['original_image'] ?? '-';"))->toBe('-');

    runPendingChecks();

    expect(sameAsHostFile(keptPath($id), $source))->toBeTrue()
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()))
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed since its check');
})->group('SPEC-029');
