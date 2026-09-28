<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Verifier\Verifier;
use Tracefern\ImageCheck\Outcome;

/**
 * @return array<string, mixed>
 */
function aiOutcomeFor(string $path): array
{
    $stream = fopen($path, 'rb');
    assert(is_resource($stream));

    return Outcome::fromReport((new Verifier)->verify($stream), 'v0.0.0-test', new DateTimeImmutable);
}

it('AC1: flags a verifying AI image', function (): void {
    $entry = aiOutcomeFor(fixturePath('openai-20260826-c2pa_2x.png'));

    expect($entry['state'])->toBe('Valid')
        ->and($entry['ai'])->toBeTrue()
        ->and(stable($entry))->toBe(expectedEntry(fixturePath('openai-20260826-c2pa_2x.png')));
})->group('SPEC-003');

it('AC2: flags an invalid file that claims AI, and keeps it Invalid', function (): void {
    $entry = aiOutcomeFor(fixturePath('amazon-20240925-titan-g1.png'));

    expect($entry['state'])->toBe('Invalid')
        ->and($entry['ai'])->toBeTrue();
})->group('SPEC-003');

it('AC3: gives a tampered AI image the CLI\'s verdict, Invalid', function (): void {
    $path = tamperedOpenAiPng();

    expect(aiOutcomeFor($path)['state'])->toBe('Invalid')
        ->and(stable(aiOutcomeFor($path)))->toBe(expectedEntry($path));
})->group('SPEC-003');

it('AC4: does not flag files without the statement', function (string $name): void {
    expect(aiOutcomeFor(fixturePath($name))['ai'])->toBeFalse();
})->with([
    'fixture-signed.jpg', 'adobe-20260425-lightroom-classic-church.jpg',
    'fixture-unsigned.png', 'c2pa-rs-ocsp.jpg',
])->group('SPEC-003');

it('AC7: never reads the verifier\'s internal parse model', function (): void {
    $found = [];
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            fn (array|string $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            if (is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && is_array($next) && $next[1] === 'store') {
                $found[] = basename($file).':'.$next[2];
            }
        }
    }

    expect($found)->toBe([]);
})->group('SPEC-003');
