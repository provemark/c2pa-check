<?php

declare(strict_types=1);

it('AC3: does nothing when uninstall.php is loaded directly', function (): void {
    $file = dirname(__DIR__, 2).'/uninstall.php';

    expect(is_file($file))->toBeTrue();

    // In a child process: the file exits, and would end this one.
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('include '.var_export($file, true).'; echo "ran past the guard";').' 2>&1', $lines, $exit);

    expect(implode("\n", $lines))->toBe('')
        ->and($exit)->toBe(0);
})->group('SPEC-005');
