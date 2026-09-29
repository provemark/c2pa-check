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
    $main = (string) file_get_contents($root.'/tracefern-image-check-for-c2pa.php');
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

it('SPEC-018 AC9: the FAQ explains a false "Changed since its check"', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/.*= Why does an image say "Changed since its check"\? =(.*?)\n= .*/s', '$1', $readme));

    expect($faq)->toContain('modification times')
        ->and($faq)->toContain('external storage');
})->group('SPEC-018');

it('SPEC-019 AC1: links the development location', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $section = (string) preg_replace('/.*\n== Development ==\n(.*?)(\n== .*|$)/s', '$1', $readme);

    expect($readme)->toContain("\n== Development ==\n")
        ->and($section)->toContain('https://github.com/provemark/tracefern-image-check')
        ->and($section)->toContain('https://github.com/provemark/c2pa-verifier')
        ->and($section)->toContain('composer build');
})->group('SPEC-019');

it('SPEC-025 AC1: the short description says it verifies', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $blocks = explode("\n\n", $readme, 3);

    expect(trim(explode("\n", $blocks[1] ?? '')[0]))
        ->toBe('Verifies the Content Credentials (C2PA) of uploaded images (signature, image hash and signer) and shows the verdict in the Media Library.');
})->group('SPEC-025');

it('SPEC-025 AC2: the description opens with what is verified', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $body = (string) preg_replace('/.*\n== Description ==\n\n(.*?)\n\n.*/s', '$1', $readme);
    $first = trim((string) preg_replace('/\s+/', ' ', $body));

    expect($first)->toStartWith('**Verified, not just detected.**')
        ->toContain('the signature')
        ->toContain('the hash that ties the manifest to the image\'s own bytes')
        ->toContain('the signer\'s certificate against the C2PA trust lists')
        ->toEndWith('Only when the signature and the hash hold does it show "AI-generated (signed)", and only when the signer is also on the trust list does it say "Verified".');
})->group('SPEC-025');

it('SPEC-025 AC3: the FAQ answers the comparison first', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/.*\n== Frequently Asked Questions ==\n(.*?)(\n== .*|$)/s', '$1', $readme);
    preg_match_all('/^= (.+) =$/m', $faq, $questions);
    $answers = preg_split('/^= .+ =$/m', $faq) ?: [];
    $answer = trim((string) preg_replace('/\s+/', ' ', $answers[1] ?? ''));

    expect($questions[1][0] ?? null)->toBe('How is this different from plugins that label AI images?')
        ->and($answer)->toContain('Some count any C2PA manifest as AI')
        ->toContain('Others read what the manifest claims without checking it')
        ->toContain('a camera photo stays a camera photo')
        ->toContain('a changed image says "Does not verify" and loses the label');
})->group('SPEC-025');

it('SPEC-025 AC4: links to no other plugin', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    preg_match_all('#https?://[^\s)\]<>,]+#', $readme, $urls);
    $allowed = [
        'https://opensource.org/licenses/',
        'https://creativecommons.org/licenses/',
        'https://github.com/provemark/tracefern-image-check',
        'https://github.com/provemark/c2pa-verifier',
        'https://github.com/c2pa-org/',
        'https://wordpress.org/plugins/tracefern-image-check-for-c2pa',
    ];

    expect($urls[0])->not->toBeEmpty();
    foreach ($urls[0] as $url) {
        $url = rtrim($url, '.');
        expect(array_filter($allowed, fn (string $prefix): bool => str_starts_with($url, $prefix)))->not->toBeEmpty($url.' is not on the allow-list');
    }
})->group('SPEC-025');

it('SPEC-026 AC8: documents the verdict for other plugins', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/.*\n= Can other plugins use the verdict\? =\n(.*?)(\n= .*|\n== .*|$)/s', '$1', $readme));

    expect($readme)->toContain("\n= Can other plugins use the verdict? =\n")
        ->and($faq)->toContain("apply_filters( 'tracefern_verdict', null, \$attachment_id )")
        ->toContain('not active')
        ->toContain('escape')
        ->and(strlen($readme))->toBeLessThan(10240);
})->group('SPEC-026');

it('the FAQ explains what an image optimizer does to Content Credentials', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/.*\n= Why does a genuine photo say "Does not verify"\? =\n(.*?)(\n= .*|\n== .*|$)/s', '$1', $readme));

    expect($faq)->toContain('image optimizer')
        ->toContain('assertion.dataHash.mismatch')
        ->toContain('resizes the original on upload')
        ->toContain('"No Content Credentials"')
        ->and(strlen($readme))->toBeLessThan(10240);
})->group('image-optimizer');

it('the changelog keeps the current version and links earlier ones to the GitHub releases', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $changelog = (string) preg_replace('/.*\n== Changelog ==\n(.*?)(\n== .*|$)/s', '$1', $readme);
    preg_match('/^Stable tag: (.+)$/m', $readme, $stable);
    preg_match_all('/^= (.+) =$/m', $changelog, $versions);

    expect($versions[1])->toBe([$stable[1] ?? null])
        ->and($changelog)->toContain('https://github.com/provemark/tracefern-image-check/releases');
})->group('changelog');

it('SPEC-028: the FAQ names "Changed after upload"', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/.*\n= Why does a genuine photo say "Does not verify"\? =\n(.*?)(\n= .*|\n== .*|$)/s', '$1', $readme));

    expect($faq)->toContain('"Changed after upload"')
        ->and(strlen($readme))->toBeLessThan(10240);
})->group('SPEC-028');

it('SPEC-025 AC6 (amendment 1): the answer names what sets the verification apart', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/readme.txt');
    $faq = (string) preg_replace('/.*\n== Frequently Asked Questions ==\n(.*?)(\n== .*|$)/s', '$1', $readme);
    $answers = preg_split('/^= .+ =$/m', $faq) ?: [];
    $paragraphs = array_values(array_filter(array_map(static fn (string $p): string => trim((string) preg_replace('/\s+/', ' ', $p)), preg_split('/\n\s*\n/', trim($answers[1] ?? '')) ?: [])));

    expect($paragraphs)->toHaveCount(2)
        ->and($paragraphs[1] ?? '')->toStartWith('A few plugins verify too.')
        ->toContain('on the server')
        ->toContain('every upload')
        ->toContain('C2PA trust lists')
        ->toContain('"Verified"')
        ->and(strlen($readme))->toBeLessThan(10240);
})->group('SPEC-025');
