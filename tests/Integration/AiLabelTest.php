<?php

declare(strict_types=1);

const AI_LABEL = 'AI-generated (signed)';

it('AC1: labels a verifying AI upload in the column and the details', function (): void {
    $id = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));

    expect(storedEntry($id)['ai'] ?? null)->toBeTrue()
        ->and(visibleText(columnHtml($id)))->toContain(AI_LABEL)
        ->and(visibleText(detailsHtml($id, true)))->toContain(AI_LABEL);
})->group('SPEC-003');

it('AC2: does not label an invalid upload that claims AI', function (): void {
    // Amendment 1: with DigiCert on (the default) Amazon Titan is Valid and
    // rightly labelled; with it off, it is Invalid.
    setOption('provemark_c2pa_digicert', false);
    try {
        $id = importMedia(fixturePath('amazon-20240925-titan-g1.png'));
    } finally {
        resetTrustOptions();
    }

    expect(storedEntry($id)['ai'] ?? null)->toBeTrue()
        ->and(storedEntry($id)['state'] ?? null)->toBe('Invalid')
        ->and(columnHtml($id))->not->toContain('AI-generated')
        ->and(detailsHtml($id, false))->not->toContain('AI-generated');
})->group('SPEC-003');

it('AC3: does not label a tampered AI upload', function (): void {
    $path = tamperedOpenAiPng();
    $id = importMedia($path);

    expect(storedEntry($id)['state'] ?? null)->toBe(expectedEntry($path, defaultSettingsFile())['state'])
        ->and(storedEntry($id)['state'] ?? null)->toBe('Invalid')
        ->and(columnHtml($id))->not->toContain('AI-generated')
        ->and(detailsHtml($id, true))->not->toContain('AI-generated');
})->group('SPEC-003');

it('AC5: shows the label for Trusted and Valid only', function (string $state, bool $labelled): void {
    $fields = ['state' => $state, 'ai' => true];
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
        expect(str_contains(visibleText($html), AI_LABEL))->toBe($labelled);
    }
})->with([
    ['Trusted', true], ['Valid', true], ['Invalid', false], ['none', false], ['error', false],
])->group('SPEC-003');

it('AC6: shows no label for an entry without `ai`, and no verdict for a malformed one', function (): void {
    $old = sampleEntry([]);
    unset($old['ai']);
    $oldId = attachmentWithEntry($old);
    $badId = attachmentWithEntry(sampleEntry(['ai' => 'true']));

    expect(visibleText(columnHtml($oldId)))->toBe('Intact: signer not trusted')
        ->and(visibleText(columnHtml($badId)))->toBe('Result unreadable');
})->group('SPEC-003');
