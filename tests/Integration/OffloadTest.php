<?php

declare(strict_types=1);

/*
 * SPEC-032: an original that another plugin moved to cloud storage. The
 * offload plugin is tests/Integration/fake-offload.php, a stand-in for WP
 * Offload Media with "Remove Local Media" and delivery on: it removes the
 * local files after the upload and hands out tfoffload:// paths from
 * get_attached_file() and wp_get_original_image_path(). The real plugin is
 * measured by hand (AC7, notes/offload-media.md).
 */

const OFFLOAD_PLUGIN = 'tracefern-test-offload.php';

const OFFLOAD_LIMIT = 64 * 1024 * 1024;

function installOffload(): void
{
    $source = containerPath(__DIR__.'/fake-offload.php');
    wpEval("wp_mkdir_p(WPMU_PLUGIN_DIR); copy('$source', WPMU_PLUGIN_DIR.'/".OFFLOAD_PLUGIN."'); update_option('tracefern_test_offload', 1, false);");
}

function offloadOption(string $name, mixed $value): void
{
    $encoded = base64_encode((string) json_encode($value));
    wpEval("update_option('$name', json_decode(base64_decode('$encoded'), true), false);");
}

/** The bucket's copy of a path relative to uploads/, as the container sees it. */
function bucketPath(string $relative): string
{
    return "WP_CONTENT_DIR.'/tf-offload-bucket/$relative'";
}

/** The uploaded file's path relative to uploads/, from the unfiltered attached file and original_image. */
function uploadedRelative(int $id): string
{
    return wpEval("\$a = get_attached_file($id, true); \$m = wp_get_attachment_metadata($id); \$f = isset(\$m['original_image']) ? dirname(\$a).'/'.\$m['original_image'] : \$a; echo substr(\$f, strlen(wp_get_upload_dir()['basedir']) + 1);");
}

afterEach(function (): void {
    wpEval(<<<'PHP'
        @unlink(WPMU_PLUGIN_DIR.'/tracefern-test-offload.php');
        foreach (['tracefern_test_offload', 'tracefern_test_offload_mode', 'tracefern_test_offload_paths', 'tracefern_test_offload_trap', 'tracefern_test_offload_served', 'tracefern_test_trap_opens'] as $option) {
            delete_option($option);
        }
        $bucket = WP_CONTENT_DIR.'/tf-offload-bucket';
        if (is_dir($bucket)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bucket, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($bucket);
        }
        PHP);
});

it('AC1: an offloaded original is checked', function (): void {
    installOffload();
    $source = fixturePath('fixture-signed.jpg');
    $id = importMedia($source);
    $relative = uploadedRelative($id);

    // the stand-in did its work: the local file is gone, the bucket holds it
    expect(wpEval("echo file_exists(wp_get_upload_dir()['basedir'].'/$relative') ? 'local' : 'gone';"))->toBe('gone')
        ->and(wpEval('echo hash_file("sha256", '.bucketPath($relative).');'))->toBe(hash_file('sha256', $source))
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()))
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
        ->and(storedEntry($id)['file'] ?? null)->toStartWith('tfoffload://bucket/');
})->group('SPEC-032');

it('AC2: a large image\'s original is checked, not its -scaled copy', function (): void {
    installOffload();
    $source = fixturePath('adobe-20260425-lightroom-classic-church.jpg');
    $id = importMedia($source);
    $relative = uploadedRelative($id);

    expect(wpEval("echo get_attached_file($id, true);"))->toEndWith('-scaled.jpg')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($source, defaultSettingsFile()))
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid')
        ->and(storedEntry($id)['file'] ?? null)->toBe('tfoffload://bucket/'.$relative);
})->group('SPEC-032');

it('AC3: PHP\'s own wrappers are not opened', function (string $path): void {
    installOffload();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    offloadOption('tracefern_test_offload_paths', [$id => $path]);
    offloadOption('tracefern_test_offload_trap', 1);
    wpEval("update_post_meta($id, '_tracefern_source', '".addslashes($path)."');");
    runPendingChecks();

    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('unreadable')
        ->and((int) wpEval("echo (int) get_option('tracefern_test_trap_opens', 0);"))->toBe(0);
})->with([
    'http' => 'http://example.test/wp-content/uploads/x.jpg',
    'php' => 'php://filter/resource=/etc/hostname',
    'data' => 'data:image/jpeg;base64,/9j/4AAQ',
    'phar' => 'phar:///tmp/x.phar/x.jpg',
])->group('SPEC-032');

it('AC4: a file larger than the limit is not copied past it', function (): void {
    installOffload();
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    $relative = uploadedRelative($id);
    // 1 MiB over the limit, so a copy that read it all would show
    wpEval('$f = fopen('.bucketPath($relative).', "r+b"); ftruncate($f, '.(OFFLOAD_LIMIT + 1024 * 1024).'); fclose($f);');
    offloadOption('tracefern_test_offload_served', 0);
    wpCli(['tracefern', 'check', (string) $id]);
    $served = (int) wpEval("echo (int) get_option('tracefern_test_offload_served', 0);");

    // PHP asks a stream for 8 KiB at a time, so one chunk past the limit may be served
    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('too_large')
        ->and($served)->toBeGreaterThan(OFFLOAD_LIMIT)
        ->and($served)->toBeLessThanOrEqual(OFFLOAD_LIMIT + 1 + 8192)
        ->and(visibleText(columnHtml($id)))->toContain('Could not be checked');
})->group('SPEC-032');

it('AC5: a stream that breaks gives no verdict', function (string $mode): void {
    installOffload();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    offloadOption('tracefern_test_offload_mode', $mode);
    runPendingChecks();

    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('unreadable');
})->with(['fail_open', 'stop_half'])->group('SPEC-032');

it('AC6: "Changed after upload" works for an offloaded original', function (): void {
    installOffload();
    $unchanged = importMedia(fixturePath('fixture-signed.png'));
    $changed = importMedia(fixturePath('fixture-signed.png'));
    $rewritten = base64_encode((string) file_get_contents(recompressedPng(fixturePath('fixture-signed.png'))));
    wpEval('file_put_contents('.bucketPath(uploadedRelative($changed)).", base64_decode('$rewritten'));");
    wpCli(['tracefern', 'check', (string) $changed]);

    expect(storedEntry($unchanged)['state'] ?? null)->toBe('Valid')
        ->and(storedEntry($unchanged))->not->toHaveKey('changed_after_upload')
        ->and(storedEntry($changed)['changed_after_upload'] ?? null)->toBeTrue()
        ->and(storedEntry($changed)['codes'] ?? [])->toContain('assertion.dataHash.mismatch');
})->group('SPEC-032');
