<?php

declare(strict_types=1);

/**
 * What `apply_filters('tracefern_verdict', $default, $id)` returns in
 * WordPress, and everything the request printed besides it. $id and
 * $default are PHP expressions.
 *
 * @return array{value: mixed, output: string}
 */
function verdictOf(string $id, string $default = 'null'): array
{
    $output = wpEval("echo 'VERDICT:', wp_json_encode(apply_filters('tracefern_verdict', $default, $id));");
    $json = (string) preg_replace('/.*VERDICT:/s', '', $output);

    return ['value' => json_decode($json, true), 'output' => $output];
}

/**
 * The verdict for an attachment, which must be an array.
 *
 * @return array<mixed>
 */
function verdict(int $id): array
{
    $value = verdictOf((string) $id)['value'];

    return is_array($value) ? $value : throw new RuntimeException("no verdict array for $id");
}

/**
 * The keys every verdict has (SPEC-026, Behavior).
 */
const VERDICT_KEYS = ['schema', 'status', 'state', 'intact', 'trusted', 'ai', 'signer', 'signed_at', 'checked_at', 'codes', 'reason', 'verifier', 'trust'];

/**
 * The column headline a verdict must match (AC7).
 *
 * @param  array<mixed>  $verdict
 */
function expectedHeadline(array $verdict): string
{
    return match ($verdict['status']) {
        'pending' => 'Check pending',
        'not_checked' => 'Not checked',
        'changed' => 'Changed since its check',
        'unreadable' => 'Result unreadable',
        default => match ($verdict['state']) {
            'Trusted' => 'Verified: trusted signer',
            'Valid' => 'Intact: signer not trusted',
            'Invalid' => 'Does not verify',
            'none' => 'No Content Credentials',
            default => 'Could not be checked',
        },
    };
}

it('AC1: a trusted AI image', function (): void {
    $id = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));
    $entry = (array) storedEntry($id);
    $verdict = verdict($id);

    expect(array_keys($verdict))->toEqualCanonicalizing(VERDICT_KEYS)
        ->and($verdict)->toMatchArray(['schema' => 1, 'status' => 'checked', 'state' => 'Trusted', 'intact' => true, 'trusted' => true, 'ai' => true, 'codes' => [], 'reason' => null])
        ->and($verdict['signer'])->toBe($entry['signer'])
        ->and($verdict['signed_at'])->toBe($entry['signed_at'])
        ->and($verdict['checked_at'])->toBe($entry['checked_at'])
        ->and($verdict['verifier'])->toBe($entry['verifier'])
        ->and($verdict['trust'])->toBe($entry['trust']);
})->group('SPEC-026');

it('AC2: intact, signer not trusted', function (): void {
    $verdict = verdict(importMedia(fixturePath('fixture-signed.jpg')));

    expect($verdict)->toMatchArray(['status' => 'checked', 'state' => 'Valid', 'intact' => true, 'trusted' => false]);
})->group('SPEC-026');

it('AC3: a changed AI image gets no AI flag', function (): void {
    $id = importMedia(tamperedOpenAiPng());
    $entry = (array) storedEntry($id);

    expect($entry['ai'] ?? null)->toBeTrue()
        ->and(verdict($id))->toMatchArray(['status' => 'checked', 'state' => 'Invalid', 'intact' => false, 'trusted' => false, 'ai' => false, 'codes' => $entry['codes']]);
})->group('SPEC-026');

it('AC4: not checked, pending, no credentials', function (): void {
    $flagsOff = ['state' => null, 'intact' => false, 'trusted' => false, 'ai' => false];

    expect(verdict(attachmentWithEntry(null)))->toMatchArray(['status' => 'not_checked'] + $flagsOff)
        ->and(verdict(importWithoutChecking(fixturePath('fixture-signed.jpg'))))->toMatchArray(['status' => 'pending'] + $flagsOff)
        ->and(verdict(importMedia(fixturePath('fixture-unsigned.jpg'))))->toMatchArray(['status' => 'checked', 'state' => 'none', 'intact' => false, 'trusted' => false, 'ai' => false]);
    runPendingChecks();
})->group('SPEC-026');

it('AC5: changed since its check', function (): void {
    $id = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));
    $other = base64_encode((string) file_get_contents(fixturePath('fixture-unsigned.jpg')));
    // Overwritten in place, as in SPEC-014 AC4: no WordPress call.
    wpEval("file_put_contents(wp_get_original_image_path($id), base64_decode('$other')); touch(wp_get_original_image_path($id), time() + 5);");

    expect(verdict($id))->toMatchArray(['status' => 'changed', 'state' => null, 'signer' => null, 'intact' => false, 'trusted' => false, 'ai' => false]);

    wpCli(['tracefern', 'check', (string) $id]);
})->group('SPEC-026');

it('AC6: not an attachment, or a broken entry', function (): void {
    $post = (int) wpEval("echo wp_insert_post(['post_title' => 'not an attachment', 'post_status' => 'publish']);");

    // (a) The default comes back unchanged.
    foreach (['999999999', "'abc'", '0', (string) $post] as $id) {
        expect(verdictOf($id)['value'])->toBeNull($id)
            ->and(verdictOf($id, "'my-default'")['value'])->toBe('my-default', $id);
    }

    // (b) A stored value that is not a SPEC-001 entry.
    foreach (['a string', sampleEntry(['state' => 42])] as $broken) {
        $result = verdictOf((string) attachmentWithEntry($broken));

        expect($result['value'])->toBeArray()
            ->and($result['value'])->toMatchArray(['status' => 'unreadable', 'state' => null, 'intact' => false, 'trusted' => false, 'ai' => false])
            ->and($result['output'])->not->toMatch('/Warning|Notice|Deprecated|Fatal/');
    }

    // (c) Control and direction characters in the signer.
    $id = attachmentWithEntry(sampleEntry(['signer' => ['issuer' => "Evil\u{202E}Corp\x07", 'common_name' => "Signer\u{200F}"]]));
    expect(verdict($id)['signer'])->toBe(['issuer' => "Evil\u{FFFD}Corp\u{FFFD}", 'common_name' => "Signer\u{FFFD}"]);
})->group('SPEC-026');

it('AC7: always the same answer as the column', function (): void {
    $ids = [
        importMedia(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg')),
        importMedia(fixturePath('openai-20260826-c2pa_2x.png')),
        importMedia(tamperedOpenAiPng()),
        importMedia(fixturePath('amazon-20240925-titan-g1.png')),
        importMedia(fixturePath('fixture-unsigned.jpg')),
        attachmentWithEntry(null),
        attachmentWithEntry('a string'),
        attachmentWithEntry(sampleEntry(['state' => 'error', 'reason' => 'unreadable'])),
    ];
    $pending = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    $ids[] = $pending;

    foreach ($ids as $id) {
        $verdict = verdict($id);
        $column = visibleText(columnHtml($id));

        // toContain() takes several needles, so no message argument here.
        expect($column)->toContain(expectedHeadline($verdict))
            ->and(str_contains($column, 'AI-generated (signed)'))->toBe($verdict['ai'], "attachment $id");
    }
    runPendingChecks();
})->group('SPEC-026');
