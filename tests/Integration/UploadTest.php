<?php

declare(strict_types=1);

/**
 * Uploads a host file into WordPress with WP-CLI and returns the attachment ID.
 */
function importMedia(string $hostPath): int
{
    $result = wpCli(['media', 'import', containerPath($hostPath), '--porcelain']);
    $id = (int) $result['output'];

    return $id > 0 ? $id : throw new RuntimeException('wp media import failed: '.$result['output']);
}

/**
 * The stored entry of an attachment, or null when there is none.
 *
 * @return array<mixed>|null
 */
function storedEntry(int $id): ?array
{
    $result = wpCli(['post', 'meta', 'get', (string) $id, '_provemark_c2pa_result', '--format=json']);
    $lines = array_values(array_filter(explode("\n", $result['output']), fn (string $l): bool => str_starts_with($l, '{')));
    $entry = $lines === [] ? null : json_decode($lines[0], true);

    return is_array($entry) ? $entry : null;
}

/**
 * Runs PHP in WordPress (wp eval) and returns its output.
 */
function wpEval(string $php): string
{
    return wpCli(['eval', $php])['output'];
}

it('AC1: stores the CLI\'s verdict for a signed upload', function (string $name): void {
    $entry = storedEntry(importMedia(fixturePath($name)));

    expect($entry)->not->toBeNull()
        ->and(stable((array) $entry))->toBe(expectedEntry(fixturePath($name)));
})->with(['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp'])->group('SPEC-001');

it('AC2: stores `none` for an upload without a manifest', function (string $name): void {
    $entry = storedEntry(importMedia(fixturePath($name)));

    expect($entry['state'] ?? null)->toBe('none')
        ->and(stable((array) $entry))->toBe(expectedEntry(fixturePath($name)));
})->with(['fixture-unsigned.jpg', 'fixture-unsigned.png', 'fixture-unsigned.webp'])->group('SPEC-001');

it('AC3: stores Invalid with the CLI\'s codes for an altered upload', function (): void {
    $path = alteredSignedJpeg();
    $entry = storedEntry(importMedia($path));

    expect(stable((array) $entry))->toBe(expectedEntry($path))
        ->and($entry['state'] ?? null)->toBe('Invalid');
})->group('SPEC-001');

it('AC4: checks the original of a large image, not its -scaled copy', function (): void {
    $path = fixturePath('adobe-20260425-lightroom-classic-church.jpg');
    $id = importMedia($path);

    expect(wpEval("echo basename(get_attached_file($id)), \"\\n\";"))->toEndWith('-scaled.jpg')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($path))
        ->and(storedEntry($id)['state'] ?? null)->toBe('Valid');
})->group('SPEC-001');

it('AC5: leaves other file types alone', function (string $name, string $bytes): void {
    $path = tmpDir().'/'.$name;
    file_put_contents($path, $bytes);
    $id = importMedia($path);

    expect($id)->toBeGreaterThan(0)
        ->and(storedEntry($id))->toBeNull();
})->with([
    'a GIF' => ['other.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
    'a PDF' => ['other.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"],
])->group('SPEC-001');

it('AC6: stores `error` / `unreadable` when the file is gone, and keeps the attachment', function (): void {
    $out = wpEval(<<<'PHP'
        $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'gone', 'post_status' => 'inherit'], '/var/www/html/wp-content/uploads/does-not-exist.jpg');
        echo 'ID:', $id, "\n";
        PHP);
    $id = (int) preg_replace('/.*ID:(\d+).*/s', '$1', $out);

    expect($id)->toBeGreaterThan(0)
        ->and(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('unreadable');
})->group('SPEC-001');

it('AC7: stores `error` / `exception` when verifying throws', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    wpEval("(new Provemark\\C2paCheck\\UploadHook(new Provemark\\C2paCheck\\Checker(fn () => throw new RuntimeException('secret'))))->onAddAttachment($id);");

    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('exception');
})->group('SPEC-001');

it('AC8: leaves `error` / `interrupted` when the check is stopped midway', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    wpEval("(new Provemark\\C2paCheck\\UploadHook(new Provemark\\C2paCheck\\Checker(function () { exit(0); })))->onAddAttachment($id);");

    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('interrupted');
})->group('SPEC-001');

it('AC10: stores `none` and the manifest URL for a manifest referenced by URL', function (): void {
    $path = fixturePath('adobe-20260304-photoshop-remote-manifest.jpg');
    $entry = storedEntry(importMedia($path));

    expect($entry['state'] ?? null)->toBe('none')
        ->and($entry['remote_manifest_url'] ?? null)->toStartWith('https://cai-manifests.adobe.com/');
})->group('SPEC-001');
