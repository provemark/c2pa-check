<?php

declare(strict_types=1);

/**
 * The header field of readme.txt named $name, or null.
 */
function readmeHeader(string $readme, string $name): ?string
{
    return preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/m', $readme, $m) === 1 ? trim($m[1]) : null;
}

it('keeps readme.txt within wordpress.org\'s limits', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root.'/readme.txt');
    $main = (string) file_get_contents($root.'/provemark-c2pa-check.php');
    preg_match('/^ \* Version:\s*(\S+)$/m', $main, $version);
    // The short description is the first line after the header block.
    $blocks = explode("\n\n", $readme, 3);
    $short = trim(explode("\n", $blocks[1] ?? '')[0]);

    expect(strlen($readme))->toBeLessThan(10240)
        ->and(count(array_map('trim', explode(',', (string) readmeHeader($readme, 'Tags')))))->toBeLessThanOrEqual(5)
        ->and($short)->not->toBe('')
        ->and(mb_strlen($short))->toBeLessThanOrEqual(150)
        ->and(readmeHeader($readme, 'Stable tag'))->toBe($version[1] ?? 'missing')
        ->and(readmeHeader($readme, 'Requires PHP'))->toBe('8.3')
        ->and($readme)->toContain('== Screenshots ==')
        ->toContain('== Changelog ==')
        ->toContain('= '.($version[1] ?? 'missing').' =');
});
