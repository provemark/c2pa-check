<?php

declare(strict_types=1);

/**
 * A SPEC-001 entry with the given fields replaced.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function entry(array $fields): array
{
    return $fields + [
        'schema' => 1,
        'state' => 'Valid',
        'format' => 'jpeg',
        'signer' => ['issuer' => 'C2PA Test Signing Cert', 'common_name' => 'C2PA Signer'],
        'signed_at' => null,
        'codes' => [],
        'remote_manifest_url' => null,
        'reason' => null,
        'verifier' => 'v0.2.3',
        'checked_at' => '2026-09-26T12:00:00Z',
    ];
}

const HEADLINES = [
    'Verified: trusted signer', 'Intact: signer not trusted', 'Does not verify',
    'No Content Credentials', 'Could not be checked',
];

it('registers a Content Credentials column in the Media Library', function (): void {
    expect(wpEval("echo json_encode(apply_filters('manage_media_columns', []));"))
        ->toContain('"provemark_c2pa":"Content Credentials"');
})->group('SPEC-002');

it('AC1: shows one headline per state in the column', function (mixed $stored, string $headline): void {
    expect(visibleText(columnHtml(attachmentWithEntry($stored))))->toBe($headline);
})->with([
    'Trusted' => [entry(['state' => 'Trusted']), 'Verified: trusted signer'],
    'Valid' => [entry(['state' => 'Valid']), 'Intact: signer not trusted'],
    'Invalid' => [entry(['state' => 'Invalid', 'codes' => ['assertion.dataHash.mismatch']]), 'Does not verify'],
    'none' => [entry(['state' => 'none', 'signer' => null]), 'No Content Credentials'],
    'error' => [entry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => 'unreadable']), 'Could not be checked'],
    'no entry' => [null, 'Not checked'],
])->group('SPEC-002');

it('AC2: shows the signer and the check in the details', function (bool $inModal): void {
    $text = visibleText(detailsHtml(attachmentWithEntry(entry([])), $inModal));

    expect($text)->toContain('Content Credentials')
        ->toContain('Intact: signer not trusted')
        ->toContain('Signed by C2PA Signer (C2PA Test Signing Cert)')
        ->toContain('Checked 2026-09-26T12:00:00Z with c2pa-verifier v0.2.3');
})->with(['Edit Media' => false, 'modal' => true])->group('SPEC-002');

it('AC3: shows every code of an invalid file, in order', function (): void {
    $id = attachmentWithEntry(entry(['state' => 'Invalid', 'codes' => ['signingCredential.untrusted', 'assertion.dataHash.mismatch']]));

    expect(visibleText(detailsHtml($id, false)))->toContain('Does not verify')
        ->toContain('Codes: signingCredential.untrusted, assertion.dataHash.mismatch');
})->group('SPEC-002');

it('AC4: shows a manifest URL as text, never as a link', function (): void {
    $html = detailsHtml(attachmentWithEntry(entry(['state' => 'none', 'signer' => null, 'remote_manifest_url' => 'https://example.test/manifest'])), true);

    expect(visibleText($html))->toContain('Refers to Content Credentials elsewhere (not checked): https://example.test/manifest')
        ->and($html)->not->toContain('<a')
        ->and($html)->not->toContain('href');
})->group('SPEC-002');

it('AC5: says why a file could not be checked', function (string $reason, string $words): void {
    $id = attachmentWithEntry(entry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => $reason]));

    expect(visibleText(detailsHtml($id, false)))->toContain('Could not be checked')
        ->toContain('Reason: '.$words);
})->with([
    ['interrupted', 'the check did not finish'],
    ['unreadable', 'the file could not be read'],
    ['exception', 'the verifier failed'],
])->group('SPEC-002');

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
    'signer and time' => [entry(['signer' => ['issuer' => HOSTILE, 'common_name' => HOSTILE], 'signed_at' => HOSTILE])],
    'codes' => [entry(['state' => 'Invalid', 'codes' => [HOSTILE]])],
    'manifest URL' => [entry(['state' => 'none', 'signer' => null, 'remote_manifest_url' => HOSTILE])],
])->group('SPEC-002');

it('AC7: replaces control and direction characters with U+FFFD', function (): void {
    $name = "A\x00B\x1BC\u{85}D\u{202E}E";
    $html = detailsHtml(attachmentWithEntry(entry(['signer' => ['issuer' => null, 'common_name' => $name]])), false);

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
    'unknown state' => [entry(['state' => 'Maybe'])],
    'another schema' => [entry(['schema' => 2])],
    'codes not a list' => [entry(['state' => 'Invalid', 'codes' => 'assertion.dataHash.mismatch'])],
    'signer without a name' => [entry(['signer' => ['issuer' => 'X']])],
])->group('SPEC-002');

it('AC9: shows a real upload\'s result end to end', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));

    expect(visibleText(columnHtml($id)))->toBe('Intact: signer not trusted')
        ->and(visibleText(detailsHtml($id, true)))->toContain('Signed by C2PA Signer (C2PA Test Signing Cert)');
})->group('SPEC-002');
