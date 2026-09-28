<?php

declare(strict_types=1);

// SPEC-020: the plugin is Tracefern Image Check for C2PA; "Provemark" is
// left only where it names the bundled verifier or its organisation.

it('carries the Tracefern name in the main file and readme.txt', function (): void {
    $root = dirname(__DIR__, 2);
    $main = (string) @file_get_contents($root.'/tracefern-image-check-for-c2pa.php');
    $readme = (string) file_get_contents($root.'/readme.txt');

    expect($main)->toMatch('/^ \* Plugin Name:\s+Tracefern Image Check for C2PA$/m')
        ->toMatch('/^ \* Text Domain:\s+tracefern-image-check-for-c2pa$/m')
        ->and(strtok($readme, "\n"))->toBe('=== Tracefern Image Check for C2PA ===');
});

it('uses the old name only for the bundled verifier and in the history', function (): void {
    $root = dirname(__DIR__, 2);
    $files = explode("\n", trim((string) shell_exec('git -C '.escapeshellarg($root).' ls-files')));
    // Records of what happened under the old name.
    $history = '#^(AI-LOG\.md|specs/SPEC-0|notes/|tests/Unit/NameTest\.php$)#';
    $allowed = [
        '#provemark/c2pa-verifier#i',
        '#vendor/provemark/#',
        '#Provemark(\\\\+)C2paVerifier#',
        '#provemark\.github\.io#',
        '#provemark/content-credentials#',
        '#provemark/tracefern-image-check#',
    ];

    $found = [];
    foreach ($files as $file) {
        if ($file === '' || preg_match($history, $file) === 1 || ! is_file($root.'/'.$file)) {
            continue;
        }
        $text = preg_replace($allowed, '', (string) file_get_contents($root.'/'.$file));
        if (stripos((string) $text, 'provemark') !== false) {
            $found[] = $file;
        }
    }

    expect($found)->toBe([]);
});
