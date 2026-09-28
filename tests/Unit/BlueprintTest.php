<?php

declare(strict_types=1);

it('AC1: is well formed and pinned', function (): void {
    $root = dirname(__DIR__, 2);
    $blueprint = blueprint();
    preg_match('/^ \* Requires PHP:\s*(\S+)$/m', (string) file_get_contents($root.'/tracefern-image-check-for-c2pa.php'), $php);
    $install = blueprintSteps($blueprint, 'installPlugin');

    expect($blueprint['preferredVersions'] ?? null)->toBe(['php' => $php[1] ?? 'missing', 'wp' => 'latest'])
        ->and($blueprint['landingPage'] ?? null)->toBe('/wp-admin/upload.php?mode=list')
        ->and($install)->toHaveCount(1)
        ->and($install[0]['pluginData'] ?? null)->toBe(['resource' => 'wordpress.org/plugins', 'slug' => 'tracefern-image-check-for-c2pa'])
        ->and($install[0]['options'] ?? null)->toBe(['activate' => true]);

    $prefix = 'https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/';
    $urls = [];
    foreach (blueprintSteps($blueprint, 'writeFile') as $step) {
        $data = is_array($step['data'] ?? null) ? $step['data'] : [];
        if (($data['resource'] ?? null) === 'url') {
            $urls[] = is_string($data['url'] ?? null) ? $data['url'] : '';
        }
    }

    expect($urls)->toHaveCount(4);
    foreach ($urls as $url) {
        expect($url)->toStartWith($prefix);
        $file = substr($url, strlen($prefix));
        exec('git -C '.escapeshellarg($root).' cat-file -e '.escapeshellarg('v0.1.0:tests/Fixtures/'.$file).' 2>/dev/null', $unused, $exit);
        expect($exit)->toBe(0, $file.' is not in tests/Fixtures/ at tag v0.1.0');
    }
})->group('SPEC-024');
