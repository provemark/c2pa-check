<?php

declare(strict_types=1);

use Tracefern\ImageCheck\Checker;
use Tracefern\ImageCheck\TrustConfig;

function bundledTrustDir(): string
{
    return dirname(__DIR__, 2).'/trust';
}

/**
 * The verifier's recipe (docs/trust-settings.md), built here independently.
 */
function recipeSettings(bool $digiCert): string
{
    $entry = fn (string $file, string $kind): array => ['trust_anchors' => (string) file_get_contents(bundledTrustDir().'/'.$file), 'trust_kind' => $kind];
    $anchors = [$entry('C2PA-TRUST-LIST.pem', 'manifest'), $entry('C2PA-TSA-TRUST-LIST.pem', 'tsa')];
    if ($digiCert) {
        $anchors[] = $entry('DigiCertTrustedRootG4.crt.pem', 'tsa');
    }

    return (string) json_encode(['verify' => ['verify_trust' => true], 'trust' => ['anchors' => $anchors]], JSON_UNESCAPED_SLASHES);
}

it('builds the bundled lists with DigiCert, by the verifier\'s recipe', function (): void {
    $config = new TrustConfig(bundledTrustDir(), '', true);
    [$settings, $source] = $config->build();

    expect($settings)->not->toBeNull()
        ->and($source)->toBe('c2pa-2026-08-14+digicert')
        ->and($config->settingsJson())->toBe(recipeSettings(true));
})->group('SPEC-004');

it('builds the bundled lists without DigiCert', function (): void {
    $config = new TrustConfig(bundledTrustDir(), '', false);

    expect($config->build()[1])->toBe('c2pa-2026-08-14')
        ->and($config->settingsJson())->toBe(recipeSettings(false));
})->group('SPEC-004');

it('AC4: lets custom settings replace the bundled lists', function (): void {
    $config = new TrustConfig(bundledTrustDir(), customSettingsJson(), true);
    [$settings, $source] = $config->build();

    expect($settings)->not->toBeNull()
        ->and($source)->toBe('custom')
        ->and($config->settingsJson())->toBe(customSettingsJson());
})->group('SPEC-004');

it('AC6: builds nothing, and says `none`, when the settings cannot be built', function (string $dir, string $custom): void {
    [$settings, $source] = (new TrustConfig($dir, $custom, true))->build();

    expect($settings)->toBeNull()
        ->and($source)->toBe('none');
})->with([
    'custom JSON that is not JSON' => [dirname(__DIR__, 2).'/trust', '{not json'],
    'custom JSON with a loose allowed_list' => [dirname(__DIR__, 2).'/trust', '{"trust":{"allowed_list":"x"}}'],
    'no bundled directory' => [dirname(__DIR__, 2).'/does-not-exist', ''],
])->group('SPEC-004');

it('AC9: calls the bundled list stale 183 days after its date, not before', function (): void {
    expect(TrustConfig::isStale(new DateTimeImmutable('2027-02-12T23:59:59Z')))->toBeFalse()
        ->and(TrustConfig::isStale(new DateTimeImmutable('2027-02-13T00:00:00Z')))->toBeTrue()
        ->and(TrustConfig::isStale(new DateTimeImmutable('2026-09-26T12:00:00Z')))->toBeFalse();
})->group('SPEC-004');

it('checks with the settings it is given, and records their source', function (): void {
    [$settings, $source] = (new TrustConfig(bundledTrustDir(), customSettingsJson(), true))->build();
    $entry = (new Checker)->check(fixturePath('fixture-signed.jpg'), $settings, $source);

    expect($entry['state'])->toBe('Trusted')
        ->and($entry['trust'])->toBe('custom')
        ->and(stable($entry))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), customSettingsFile()));
})->group('SPEC-004');
