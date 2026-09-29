<?php

declare(strict_types=1);

/**
 * The stored fingerprint of an attachment's upload (SPEC-028), or null.
 *
 * @return array<mixed>|null
 */
function uploadFingerprint(int $id): ?array
{
    $value = json_decode(wpEval("echo wp_json_encode(get_post_meta($id, '_tracefern_upload', true));"), true);

    return is_array($value) ? $value : null;
}

/** Overwrites an attachment's kept file in place with a host file, as an optimizer does. */
function overwriteKeptFile(int $id, string $hostPath): void
{
    $bytes = base64_encode((string) file_get_contents($hostPath));
    wpEval("\$f = wp_get_upload_dir()['basedir'].'/'.get_post_meta($id, '_tracefern_source', true); file_put_contents(\$f, base64_decode('$bytes')); touch(\$f, time() + 5);");
}

const REPLACING_PLUGIN = 'wp-content/mu-plugins/tracefern-test-replace.php';

it('AC1: an ordinary upload is fingerprinted and shown as today', function (): void {
    $source = fixturePath('fixture-signed.jpg');
    $id = importMedia($source);

    expect(uploadFingerprint($id))->toMatchArray(['sha256' => hash_file('sha256', $source), 'size' => filesize($source)])
        ->and(storedEntry($id))->not->toHaveKey('changed_after_upload')
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
        ->and(visibleText(columnHtml($id)))->not->toContain('Changed after upload');
})->group('SPEC-028');

it('AC2: a file rewritten in place after the check', function (): void {
    $id = importMedia(fixturePath('fixture-signed.png'));
    expect(storedEntry($id)['state'] ?? null)->toBe('Valid');

    $rewritten = recompressedPng(fixturePath('fixture-signed.png'));
    overwriteKeptFile($id, $rewritten);
    wpCli(['tracefern', 'check', (string) $id]);

    expect(storedEntry($id)['changed_after_upload'] ?? null)->toBeTrue()
        ->and(stable(array_diff_key((array) storedEntry($id), ['changed_after_upload' => 0])))->toBe(expectedEntry($rewritten, defaultSettingsFile()))
        ->and(storedEntry($id)['codes'] ?? [])->toContain('assertion.dataHash.mismatch')
        ->and(visibleText(columnHtml($id)))->toContain('Does not verify')
        ->and(visibleText(columnHtml($id)))->toContain('Changed after upload')
        ->and(visibleText(detailsHtml($id, true)))->toContain('Changed after upload')
        ->and(visibleText(detailsHtml($id, true)))->toContain('image optimizer');
})->group('SPEC-028');

it('AC3: a file replaced during the upload', function (): void {
    $unsigned = containerPath(fixturePath('fixture-unsigned.jpg'));
    wpEval("file_put_contents(ABSPATH.'".REPLACING_PLUGIN."', '<?php add_filter(\"wp_handle_upload\", static function (\$u) { if (str_contains(\$u[\"file\"] ?? \"\", \"replace-during-upload\")) { copy(\"$unsigned\", \$u[\"file\"]); } return \$u; }, 10);');");
    try {
        $source = tmpDir().'/replace-during-upload.jpg';
        copy(fixturePath('fixture-signed.jpg'), $source);
        $id = importMedia($source);
    } finally {
        wpEval("unlink(ABSPATH.'".REPLACING_PLUGIN."');");
    }

    expect(uploadFingerprint($id)['sha256'] ?? null)->toBe(hash_file('sha256', fixturePath('fixture-signed.jpg')))
        ->and(storedEntry($id)['state'] ?? null)->toBe('none')
        ->and(storedEntry($id)['changed_after_upload'] ?? null)->toBeTrue()
        ->and(visibleText(columnHtml($id)))->toContain('No Content Credentials')
        ->and(visibleText(columnHtml($id)))->toContain('Changed after upload');
})->group('SPEC-028');

it('AC4: no fingerprint when the file cannot be read', function (): void {
    $unchanged = wpEval("echo wp_json_encode([Tracefern\\ImageCheck\\UploadHook::fingerprint(['type' => 'image/jpeg']), Tracefern\\ImageCheck\\UploadHook::fingerprint(['file' => '/nonexistent/x.jpg', 'type' => 'image/jpeg'])]);");
    expect(json_decode($unchanged, true))->toBe([['type' => 'image/jpeg'], ['file' => '/nonexistent/x.jpg', 'type' => 'image/jpeg']]);

    // A file that never passed the upload filter: no fingerprint, checked as today.
    $source = containerPath(fixturePath('fixture-signed.jpg'));
    $id = (int) wpEval("\$d = wp_get_upload_dir(); \$f = \$d['path'].'/no-fingerprint-'.wp_generate_password(6, false).'.jpg'; copy('$source', \$f); echo wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'x', 'post_status' => 'inherit'], \$f);");
    runPendingChecks();

    expect(uploadFingerprint($id))->toBeNull()
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
        ->and(storedEntry($id))->not->toHaveKey('changed_after_upload');
})->group('SPEC-028');

it('AC5: an edit in WordPress is not "changed after upload"', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    editImage($id);
    runPendingChecks();

    expect(storedEntry($id)['file'] ?? '')->toMatch('/-e\d+\.jpg$/')
        ->and(storedEntry($id))->not->toHaveKey('changed_after_upload');
})->group('SPEC-028');

it('AC6: image sizes do not overwrite the fingerprint', function (): void {
    $source = fixturePath('c2pa-rs-no_alg.jpg');
    $id = browserUpload($source, rotated: true, scaled: false);
    runPendingChecks();

    expect(uploadFingerprint($id)['sha256'] ?? null)->toBe(hash_file('sha256', $source))
        ->and(uploadFingerprint($id)['file'] ?? null)->toBe(keptPath($id))
        ->and(storedEntry($id))->not->toHaveKey('changed_after_upload');
})->group('SPEC-028');

it('AC8: uninstall removes the fingerprint', function (): void {
    importMedia(fixturePath('fixture-signed.jpg'));
    $count = "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_tracefern_upload'\");";

    expect((int) wpEval($count))->toBeGreaterThan(0);
    wpEval(UNINSTALL_PLUGIN_PHP);
    expect((int) wpEval($count))->toBe(0);
})->group('SPEC-028');
