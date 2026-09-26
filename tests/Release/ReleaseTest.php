<?php

declare(strict_types=1);

beforeAll(fn () => installReleaseZip());

it('AC1: holds what runs, and nothing else', function (): void {
    exec('unzip -Z1 '.escapeshellarg(releaseZip()), $lines, $exit);
    $tops = [];
    $inside = [];
    foreach ($lines as $line) {
        if ($line !== '') {
            $tops[explode('/', $line)[0]] = true;
            $inside[] = substr($line, strlen('provemark-c2pa-check/'));
        }
    }

    expect($exit)->toBe(0)
        ->and(array_keys($tops))->toBe(['provemark-c2pa-check']);
    foreach (['provemark-c2pa-check.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'composer.json', 'src/UploadHook.php', 'trust/C2PA-TRUST-LIST.pem', 'assets/admin.css', 'vendor/autoload.php', 'vendor-prefixed/provemark/c2pa-verifier/src/Verifier/Verifier.php', 'vendor-prefixed/provemark/c2pa-verifier/LICENSE'] as $needed) {
        expect($inside)->toContain($needed);
    }

    $forbidden = '#^(tests|specs|notes|docs|tools|build|\.github)/'
        .'|^(AI-LOG\.md|NOTES\.md|package(-lock)?\.json|composer\.lock|\.wp-env.*\.json|phpstan\.neon|phpunit\.xml|pint\.json)$'
        .'|(^|/)\.[^/]+$|\.key$'
        .'|^vendor/bin/'
        .'|^vendor-prefixed/provemark/c2pa-verifier/(docs|notes|specs|bin|tests)/'
        .'|^vendor-prefixed/provemark/c2pa-verifier/(AI-LOG|NOTES|CHANGELOG|CONTRIBUTING|SECURITY|README)\.md$#';
    expect(array_values(array_filter($inside, fn (string $file): bool => preg_match($forbidden, $file) === 1)))->toBe([]);
})->group('SPEC-006');

it('AC2: installs and works on a clean WordPress', function (string $fixture, string $state): void {
    $entry = releaseEntry(releaseImport($fixture));

    expect($entry['state'] ?? null)->toBe($state)
        ->and(stable((array) $entry))->toBe(expectedEntry(fixturePath($fixture), defaultSettingsFile()))
        ->and(releaseEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Provemark\\C2paCheck\\SettingsPage)->render();"))->toContain('C2PA Check');
})->with([
    ['fixture-signed.jpg', 'Valid'],
    ['google-20250919-pixel10-npld-picnic-table.jpg', 'Trusted'],
])->group('SPEC-006');

it('AC3: passes Plugin Check on the build, with nothing excluded', function (): void {
    $result = wpCli(['plugin', 'check', 'provemark-c2pa-check', '--format=csv', '--fields=type,code,message'], 'release');

    expect(preg_grep('/^(ERROR|WARNING),/m', explode("\n", $result['output'])))->toBe([], $result['output'])
        ->and($result['output'])->toContain('Checks complete');
})->group('SPEC-006');

it('AC4: keeps the shipped verifier within its reviewed WPCS baseline', function (): void {
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__).'/wpcs-verifier-baseline.json'), true);
    $baseline = [];
    foreach (is_array($decoded) && is_array($decoded['counts'] ?? null) ? $decoded['counts'] : [] as $sniff => $count) {
        if (is_string($sniff) && is_int($count)) {
            $baseline[$sniff] = $count;
        }
    }
    $shipped = dirname(__DIR__, 2).'/build/provemark-c2pa-check/vendor-prefixed/provemark/c2pa-verifier/src';

    expect($baseline)->not->toBeEmpty()
        ->and(wpcsFindings($shipped))->not->toBeEmpty()
        ->and(beyondBaseline(wpcsFindings($shipped), $baseline))->toBe([]);

    // A planted unescaped echo in a copy must break the baseline.
    // Outside the repository: only the host needs it, and PHPStan and Pint
    // must not read it.
    $copy = sys_get_temp_dir().'/provemark-c2pa-wpcs-planted';
    exec('rm -rf '.escapeshellarg($copy).' && cp -R '.escapeshellarg($shipped).' '.escapeshellarg($copy));
    file_put_contents($copy.'/Planted.php', "<?php\necho \$_GET['x'];\n");

    expect(beyondBaseline(wpcsFindings($copy), $baseline))->not->toBeEmpty();
})->group('SPEC-006');

it('AC5: stays up and says so when its bundled libraries are missing', function (): void {
    wpCli(['eval', "exec('rm -rf '.escapeshellarg(WP_PLUGIN_DIR.'/provemark-c2pa-check/vendor'));"], 'release');
    try {
        $id = releaseImport('fixture-signed.jpg');

        expect(wpCli(['plugin', 'is-active', 'provemark-c2pa-check'], 'release')['exit'])->toBe(0)
            ->and(releaseEval("do_action('admin_notices');"))->toContain('bundled libraries are missing')
            ->and(releaseEntry($id))->toBeNull();
    } finally {
        installReleaseZip();
    }
})->group('SPEC-006');

it('SPEC-009 AC1: carries only the prefixed verifier', function (): void {
    exec('unzip -Z1 '.escapeshellarg(releaseZip()), $lines);
    $unprefixed = [];
    foreach ($lines as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }
        $code = [];
        exec('unzip -p '.escapeshellarg(releaseZip()).' '.escapeshellarg($file), $code);
        if (preg_match('/^\s*(namespace|use)\s+\\\\?Provemark\\\\C2paVerifier\\\\/m', implode("\n", $code)) === 1) {
            $unprefixed[] = $file;
        }
    }

    expect(array_values(array_filter($lines, fn (string $f): bool => str_starts_with($f, 'provemark-c2pa-check/vendor/provemark/'))))->toBe([])
        ->and($lines)->toContain('provemark-c2pa-check/vendor-prefixed/provemark/c2pa-verifier/src/Verifier/Verifier.php')
        ->and($unprefixed)->toBe([]);
})->group('SPEC-009');

it('SPEC-009 AC3: is not disturbed by another copy of the verifier', function (): void {
    // A must-use plugin loads before the plugin and defines the verifier's
    // unprefixed class, as another plugin bundling it would.
    releaseEval(<<<'PHP'
        if (! is_dir(WPMU_PLUGIN_DIR)) { mkdir(WPMU_PLUGIN_DIR, 0777, true); }
        file_put_contents(WPMU_PLUGIN_DIR.'/other-verifier.php', '<?php namespace Provemark\C2paVerifier\Verifier; final class Verifier { public function verify($stream, $settings = null) { throw new \RuntimeException("another copy"); } }');
        PHP);
    try {
        expect(releaseEval('echo class_exists("Provemark\\\\C2paVerifier\\\\Verifier\\\\Verifier", false) ? "planted" : "missing";'))->toBe('planted');

        $entry = releaseEntry(releaseImport('fixture-signed.jpg'));

        expect($entry['state'] ?? null)->toBe('Valid');
    } finally {
        releaseEval('unlink(WPMU_PLUGIN_DIR."/other-verifier.php");');
    }
})->group('SPEC-009');

it('SPEC-009 AC4: stops the build when strauss.phar is not the pinned one', function (): void {
    $root = dirname(__DIR__, 2);
    $phar = $root.'/build/.tools/strauss-0.30.0.phar';
    $kept = $phar.'.kept';

    expect(is_file($phar))->toBeTrue();
    rename($phar, $kept);
    file_put_contents($phar, 'not strauss');
    try {
        exec('sh '.escapeshellarg($root.'/tools/build.sh').' 2>&1', $lines, $exit);

        expect($exit)->not->toBe(0)
            ->and(implode("\n", $lines))->toContain('SHA-256')
            ->and(is_file(releaseZip()))->toBeFalse();
    } finally {
        rename($kept, $phar);
        exec('sh '.escapeshellarg($root.'/tools/build.sh').' 2>&1');
        installReleaseZip();
    }
})->group('SPEC-009');
