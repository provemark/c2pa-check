<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Provemark\C2paCheck\Checker;

it('checks a file with the bundled verifier', function (): void {
    $entry = (new Checker)->check(fixturePath('fixture-signed.jpg'));

    expect(stable($entry))->toBe(expectedEntry(fixturePath('fixture-signed.jpg')))
        ->and($entry['verifier'])->toBe(InstalledVersions::getPrettyVersion('provemark/c2pa-verifier'));
})->group('SPEC-001');

it('AC6: gives a file that cannot be opened `error` / `unreadable`', function (string $path): void {
    $entry = (new Checker)->check($path);

    expect($entry['state'])->toBe('error')
        ->and($entry['reason'])->toBe('unreadable')
        ->and($entry['format'])->toBeNull();
})->with([
    'missing' => fn () => tmpDir().'/does-not-exist.jpg',
    'a directory' => fn () => tmpDir(),
])->group('SPEC-001');

it('AC7: turns any Throwable into `error` / `exception`, without its message', function (Throwable $thrown): void {
    $entry = (new Checker(fn () => throw $thrown))->check(fixturePath('fixture-signed.jpg'));

    expect($entry['state'])->toBe('error')
        ->and($entry['reason'])->toBe('exception')
        ->and(json_encode($entry))->not->toContain('secret');
})->with([
    'an exception' => fn () => new RuntimeException('/secret/path/photo.jpg'),
    'an error' => fn () => new TypeError('secret'),
])->group('SPEC-001');

it('AC8: has an `interrupted` entry to write before verifying', function (): void {
    $entry = (new Checker)->interrupted();

    expect($entry['state'])->toBe('error')
        ->and($entry['reason'])->toBe('interrupted');
})->group('SPEC-001');
