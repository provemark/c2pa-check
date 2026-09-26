<?php

declare(strict_types=1);

use Provemark\C2paCheck\Outcome;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

function reportFor(string $path): VerificationReport
{
    $stream = fopen($path, 'rb');
    assert(is_resource($stream));

    return (new Verifier)->verify($stream);
}

/**
 * @return array<string, mixed>
 */
function outcomeFor(string $path): array
{
    return Outcome::fromReport(reportFor($path), 'v0.0.0-test', new DateTimeImmutable('2026-09-26T12:00:00Z'));
}

it('AC1: gives a signed file the CLI\'s verdict, signer and time', function (string $name): void {
    expect(stable(outcomeFor(fixturePath($name))))->toBe(expectedEntry(fixturePath($name)))
        ->and(outcomeFor(fixturePath($name))['state'])->toBe('Valid');
})->with(['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp'])->group('SPEC-001');

it('AC2: gives a file without a manifest `none`, although the verifier says Invalid', function (string $name): void {
    expect(reportFor(fixturePath($name))->result->state->value)->toBe('Invalid')
        ->and(stable(outcomeFor(fixturePath($name))))->toBe(expectedEntry(fixturePath($name)))
        ->and(outcomeFor(fixturePath($name))['state'])->toBe('none');
})->with(['fixture-unsigned.jpg', 'fixture-unsigned.png', 'fixture-unsigned.webp'])->group('SPEC-001');

it('AC3: gives an altered file Invalid with the CLI\'s codes, in order', function (): void {
    $path = alteredSignedJpeg();

    expect(stable(outcomeFor($path)))->toBe(expectedEntry($path))
        ->and(outcomeFor($path)['codes'])->toContain('assertion.dataHash.mismatch');
})->group('SPEC-001');

it('AC9: passes the verifier\'s state through unchanged when there is a manifest', function (string $path): void {
    expect(outcomeFor($path)['state'])->toBe(reportFor($path)->result->state->value);
})->with([
    'signed jpg' => fn () => fixturePath('fixture-signed.jpg'),
    'signed png' => fn () => fixturePath('fixture-signed.png'),
    'signed webp' => fn () => fixturePath('fixture-signed.webp'),
    'lightroom' => fn () => fixturePath('adobe-20260425-lightroom-classic-church.jpg'),
    'altered' => fn () => alteredSignedJpeg(),
])->group('SPEC-001');

it('AC10: keeps a manifest URL without fetching it, and says `none`', function (): void {
    $path = fixturePath('adobe-20260304-photoshop-remote-manifest.jpg');
    $url = reportFor($path)->remoteManifestUrl;

    expect($url)->toBeString()
        ->and(outcomeFor($path)['state'])->toBe('none')
        ->and(outcomeFor($path)['remote_manifest_url'])->toBe($url);
})->group('SPEC-001');

it('records the verifier version and the UTC time of the check', function (): void {
    $entry = Outcome::fromReport(reportFor(fixturePath('fixture-signed.jpg')), 'v9.9.9', new DateTimeImmutable('2026-09-26T14:00:00+02:00'));

    expect($entry['verifier'])->toBe('v9.9.9')
        ->and($entry['checked_at'])->toBe('2026-09-26T12:00:00Z');
})->group('SPEC-001');
