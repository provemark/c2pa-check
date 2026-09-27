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

it('has one screenshot file for each screenshot caption', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root.'/readme.txt');
    $section = explode('== Screenshots ==', $readme)[1] ?? '';
    $section = explode("\n== ", $section)[0];
    preg_match_all('/^(\d+)\. /m', $section, $captions);
    $files = glob($root.'/.wordpress-org/screenshot-*.png') ?: [];

    expect($captions[1])->not->toBeEmpty()
        ->and(array_map('intval', $captions[1]))->toBe(range(1, count($captions[1])))
        ->and(count($files))->toBe(count($captions[1]));
    foreach ($captions[1] as $n) {
        expect(is_file($root.'/.wordpress-org/screenshot-'.$n.'.png'))->toBeTrue("screenshot-{$n}.png");
    }
});

it('SPEC-016 AC3: has no Upgrade Notice, and explains the AI claim of an untrusted signer', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/.*= Does "AI-generated \(signed\)" detect AI images\? =(.*?)\n= .*/s', '$1', $readme);

    expect($readme)->not->toContain('== Upgrade Notice ==')
        ->and((string) preg_replace('/\s+/', ' ', $faq))->toContain('Intact: signer not trusted');
})->group('SPEC-016');

it('SPEC-016 AC4: the review notes answer the .pem files, the slug and the callbacks', function (): void {
    $notes = (string) file_get_contents(dirname(__DIR__, 2).'/notes/wporg-review.md');

    expect($notes)->toContain('## The `.pem` files in `trust/`')
        ->and($notes)->toContain('## The name and slug')
        ->and($notes)->toContain('## Hook callbacks');
})->group('SPEC-016');
