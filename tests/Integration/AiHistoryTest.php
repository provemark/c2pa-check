<?php

declare(strict_types=1);

const AI_EDITED_LABEL = 'AI-edited (signed)';

it('AC7: labels the real files by their history', function (string $name, bool $ai, bool $edited): void {
    $path = fixturePath($name);
    $id = importMedia($path);
    $entry = (array) storedEntry($id);

    expect($entry['ai'] ?? null)->toBe($ai)
        ->and($entry['ai_edited'] ?? null)->toBe($edited)
        ->and(stable($entry))->toBe(expectedEntry($path, defaultSettingsFile()));

    foreach ([visibleText(columnHtml($id)), visibleText(detailsHtml($id, true))] as $text) {
        expect(str_contains($text, 'AI-generated (signed)'))->toBe($ai)
            ->and(str_contains($text, AI_EDITED_LABEL))->toBe($edited);
    }
})->with([
    ['openai-20260826-c2pa_2x.png', true, false],
    ['c2pa-rs-ocsp.jpg', false, true],
    ['fixture-signed.jpg', false, false],
    ['adobe-20260425-lightroom-classic-church.jpg', false, false],
    ['fixture-unsigned.png', false, false],
    ['c2pa-verifier-ai-parent-chain.png', true, false],
])->group('SPEC-027');

it('AC8: shows the edited label for Trusted and Valid only', function (string $state, bool $labelled): void {
    $fields = ['state' => $state, 'ai_edited' => true];
    if ($state === 'Invalid') {
        $fields['codes'] = ['assertion.dataHash.mismatch'];
    }
    if (in_array($state, ['none', 'error'], true)) {
        $fields += ['signer' => null];
    }
    if ($state === 'error') {
        $fields += ['format' => null, 'reason' => 'exception'];
    }
    $id = attachmentWithEntry(sampleEntry($fields));

    foreach ([columnHtml($id), detailsHtml($id, false)] as $html) {
        expect(str_contains(visibleText($html), AI_EDITED_LABEL))->toBe($labelled);
    }
    expect(verdict($id)['ai_edited'] ?? null)->toBe($labelled);
})->with([
    ['Trusted', true], ['Valid', true], ['Invalid', false], ['none', false], ['error', false],
])->group('SPEC-027');

it('AC8: shows no edited label for an entry without the key, and no verdict for a malformed one', function (): void {
    $old = sampleEntry([]);
    unset($old['ai_edited']);
    $oldId = attachmentWithEntry($old);
    $badId = attachmentWithEntry(sampleEntry(['ai_edited' => 'true']));

    expect(visibleText(columnHtml($oldId)))->toBe('Intact: signer not trusted')
        ->and(verdict($oldId)['ai_edited'] ?? null)->toBeFalse()
        ->and(visibleText(columnHtml($badId)))->toBe('Result unreadable')
        ->and(verdict($badId))->toMatchArray(['status' => 'unreadable', 'ai' => false, 'ai_edited' => false]);
})->group('SPEC-027');

it('AC9: does not label a tampered file', function (): void {
    $path = tamperedOcspJpeg();
    $id = importMedia($path);

    expect(storedEntry($id)['state'] ?? null)->toBe(expectedEntry($path, defaultSettingsFile())['state'])
        ->and(storedEntry($id)['state'] ?? null)->toBe('Invalid')
        ->and(visibleText(columnHtml($id)))->not->toContain('AI-')
        ->and(visibleText(detailsHtml($id, true)))->not->toContain('AI-')
        ->and(verdict($id))->toMatchArray(['ai' => false, 'ai_edited' => false]);
})->group('SPEC-027');
