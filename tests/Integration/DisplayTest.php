<?php

declare(strict_types=1);

const HEADLINES = [
    'Verified: trusted signer', 'Intact: signer not trusted', 'Does not verify',
    'No Content Credentials', 'Could not be checked',
];

it('registers a Content Credentials column in the Media Library', function (): void {
    expect(wpEval("echo json_encode(apply_filters('manage_media_columns', []));"))
        ->toContain('"tracefern":"Content Credentials"');
})->group('SPEC-002');

it('AC1: shows one headline per state in the column', function (mixed $stored, string $headline): void {
    expect(visibleText(columnHtml(attachmentWithEntry($stored))))->toBe($headline);
})->with([
    'Trusted' => [sampleEntry(['state' => 'Trusted']), 'Verified: trusted signer'],
    'Valid' => [sampleEntry(['state' => 'Valid']), 'Intact: signer not trusted'],
    'Invalid' => [sampleEntry(['state' => 'Invalid', 'codes' => ['assertion.dataHash.mismatch']]), 'Does not verify'],
    'none' => [sampleEntry(['state' => 'none', 'signer' => null]), 'No Content Credentials'],
    'error' => [sampleEntry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => 'unreadable']), 'Could not be checked'],
    'no entry' => [null, 'Not checked'],
])->group('SPEC-002');

it('AC2: shows the signer and the check in the details', function (bool $inModal): void {
    $text = visibleText(detailsHtml(attachmentWithEntry(sampleEntry([])), $inModal));

    expect($text)->toContain('Content Credentials')
        ->toContain('Intact: signer not trusted')
        ->toContain('Signer C2PA Signer (C2PA Test Signing Cert)')
        ->toContain('Checked 2026-09-26 12:00 UTC, c2pa-verifier v0.2.3');
})->with(['Edit Media' => false, 'modal' => true])->group('SPEC-002');

it('AC3: shows every code of an invalid file, in order', function (): void {
    $id = attachmentWithEntry(sampleEntry(['state' => 'Invalid', 'codes' => ['signingCredential.untrusted', 'assertion.dataHash.mismatch']]));

    expect(visibleText(detailsHtml($id, false)))->toContain('Does not verify')
        ->toContain('Codes signingCredential.untrusted assertion.dataHash.mismatch');
})->group('SPEC-002');

it('AC4: shows a manifest URL as text, never as a link', function (): void {
    $html = detailsHtml(attachmentWithEntry(sampleEntry(['state' => 'none', 'signer' => null, 'remote_manifest_url' => 'https://example.test/manifest'])), true);

    expect(visibleText($html))->toContain('Refers to https://example.test/manifest (not checked)')
        ->and($html)->not->toContain('<a')
        ->and($html)->not->toContain('href');
})->group('SPEC-002');

it('AC5: says why a file could not be checked', function (string $reason, string $words): void {
    $id = attachmentWithEntry(sampleEntry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => $reason]));

    expect(visibleText(detailsHtml($id, false)))->toContain('Could not be checked')
        ->toContain('Reason '.$words);
})->with([
    ['interrupted', 'the check did not finish'],
    ['unreadable', 'the file could not be read'],
    ['exception', 'the verifier failed'],
])->group('SPEC-002');

// language=TEXT
const HOSTILE = '<script>alert(1)</script>"><img src=x onerror=alert(1)>\'';

it('AC6: escapes hostile text from the file everywhere', function (array $stored): void {
    $id = attachmentWithEntry($stored);

    foreach ([columnHtml($id), detailsHtml($id, false), detailsHtml($id, true)] as $html) {
        expect(activeMarkup($html))->toBe([])
            ->and($html)->not->toContain('<script')
            ->and($html)->not->toContain('<img');
    }
    expect(detailsHtml($id, false))->toContain('&lt;script&gt;');
})->with([
    'signer and time' => [sampleEntry(['signer' => ['issuer' => HOSTILE, 'common_name' => HOSTILE], 'signed_at' => HOSTILE])],
    'codes' => [sampleEntry(['state' => 'Invalid', 'codes' => [HOSTILE]])],
    'manifest URL' => [sampleEntry(['state' => 'none', 'signer' => null, 'remote_manifest_url' => HOSTILE])],
])->group('SPEC-002');

it('AC7: replaces control and direction characters with U+FFFD', function (): void {
    $name = "A\x00B\x1BC\u{85}D\u{202E}E";
    $html = detailsHtml(attachmentWithEntry(sampleEntry(['signer' => ['issuer' => null, 'common_name' => $name]])), false);

    expect($html)->toContain("A\u{FFFD}B\u{FFFD}C\u{FFFD}D\u{FFFD}E");
    foreach (["\x00", "\x1B", "\u{85}", "\u{202E}"] as $char) {
        expect(str_contains($html, $char))->toBeFalse();
    }
})->group('SPEC-002');

it('AC8: shows no verdict for an entry that is not a SPEC-001 entry', function (mixed $stored): void {
    $id = attachmentWithEntry($stored);

    foreach ([columnHtml($id), detailsHtml($id, false)] as $html) {
        expect(visibleText($html))->toContain('Result unreadable');
        foreach (HEADLINES as $headline) {
            expect(visibleText($html))->not->toContain($headline);
        }
    }
})->with([
    'a string' => ['garbage'],
    'no state' => [['schema' => 1]],
    'unknown state' => [sampleEntry(['state' => 'Maybe'])],
    'another schema' => [sampleEntry(['schema' => 2])],
    'codes not a list' => [sampleEntry(['state' => 'Invalid', 'codes' => 'assertion.dataHash.mismatch'])],
    'signer without a name' => [sampleEntry(['signer' => ['issuer' => 'X']])],
])->group('SPEC-002');

it('AC9: shows a real upload\'s result end to end', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));

    expect(visibleText(columnHtml($id)))->toBe('Intact: signer not trusted')
        ->and(visibleText(detailsHtml($id, true)))->toContain('Signer C2PA Signer (C2PA Test Signing Cert)');
})->group('SPEC-002');

it('amendment 1: loads its stylesheet in the admin, with Dashicons', function (): void {
    // Only the plugin's own callback: firing the whole hook in WP-CLI runs
    // core callbacks that expect an admin screen.
    $out = wpEval(<<<'PHP'
        $screens = new Tracefern\ImageCheck\MediaScreens;
        $screens->enqueueStyle();
        echo json_encode([
            'hooked' => has_action('admin_enqueue_scripts') !== false,
            'enqueued' => wp_style_is('tracefern-image-check-for-c2pa', 'enqueued'),
            'deps' => wp_styles()->registered['tracefern-image-check-for-c2pa']->deps ?? [],
            'src' => wp_styles()->registered['tracefern-image-check-for-c2pa']->src ?? '',
        ]);
        PHP);
    $result = json_decode($out, true);

    expect($result)->toBeArray()
        ->and(is_array($result) ? $result['enqueued'] ?? null : null)->toBeTrue()
        ->and(is_array($result) ? $result['hooked'] ?? null : null)->toBeTrue()
        ->and(is_array($result) ? $result['deps'] ?? null : null)->toBe(['dashicons'])
        ->and(is_array($result) ? $result['src'] ?? null : null)->toEndWith('/tracefern-image-check-for-c2pa/assets/admin.css');
})->group('SPEC-002');

it('amendment 1: shows the verdict as a badge, and the facts as rows', function (): void {
    $html = detailsHtml(attachmentWithEntry(sampleEntry(['state' => 'Trusted', 'ai' => true, 'trust' => 'c2pa-2026-08-14+digicert'])), true);

    expect($html)->toContain('tracefern-badge--trusted')
        ->toContain('tracefern-badge--ai')
        ->toContain('<dl class="tracefern-facts">')
        ->and(visibleText($html))->toContain('Verified: trusted signer AI-generated (signed)');
})->group('SPEC-002');
