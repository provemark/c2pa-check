<?php

declare(strict_types=1);

const PLUGIN_FILE = 'provemark-c2pa-check/provemark-c2pa-check.php';

/**
 * Runs $test with a must-use plugin in the test environment; removed afterwards.
 */
function withMustUsePlugin(string $name, string $code, callable $test): void
{
    $payload = base64_encode($code);
    wpEval("if (! is_dir(WPMU_PLUGIN_DIR)) { mkdir(WPMU_PLUGIN_DIR, 0777, true); } file_put_contents(WPMU_PLUGIN_DIR.'/$name.php', base64_decode('$payload'));");
    try {
        $test();
    } finally {
        wpEval("@unlink(WPMU_PLUGIN_DIR.'/$name.php');");
    }
}

it('AC1: an unknown format is an error, not "no credential"', function (): void {
    $gif = base64_encode((string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    $id = attachmentWithEntry(null);
    wpEval("\$file = wp_get_upload_dir()['basedir'].'/m15-not-a-jpeg.jpg'; file_put_contents(\$file, base64_decode('$gif')); update_attached_file($id, \$file); (new Provemark\\C2paCheck\\UploadHook(new Provemark\\C2paCheck\\Checker))->checkAndStore($id, \$file);");
    $unsigned = importMedia(fixturePath('fixture-unsigned.jpg'));

    expect(storedEntry($id)['state'] ?? null)->toBe('error')
        ->and(storedEntry($id)['reason'] ?? null)->toBe('unsupported')
        ->and(visibleText(detailsHtml($id, false)))->toContain('Could not be checked')
        ->and(visibleText(detailsHtml($id, false)))->toContain('not a JPEG, PNG or WebP file')
        ->and(storedEntry($unsigned)['state'] ?? null)->toBe('none');
})->group('SPEC-015');

/** The bundled verifier's version as vendor/composer/installed.php records it (SPEC-015 amendment 1). */
function bundledVerifierVersion(): string
{
    $installed = require dirname(__DIR__, 2).'/vendor/composer/installed.php';
    $versions = is_array($installed) && is_array($installed['versions'] ?? null) ? $installed['versions'] : [];
    $package = is_array($versions['provemark/c2pa-verifier'] ?? null) ? $versions['provemark/c2pa-verifier'] : [];
    $version = $package['pretty_version'] ?? null;
    if (! is_string($version)) {
        throw new RuntimeException('vendor/composer/installed.php does not record provemark/c2pa-verifier');
    }

    return $version;
}

it('AC2: the version without the global class', function (): void {
    runPendingChecks(); // anything an earlier test left scheduled
    // Loaded before the plugin, as another plugin's older Composer could be;
    // under WP-CLI the class already exists, so the check runs over HTTP.
    $mu = '<?php namespace Composer; if (! class_exists(InstalledVersions::class, false)) { final class InstalledVersions { public static function getPrettyVersion($p) { throw new \OutOfBoundsException("not here"); } public static function isInstalled($p) { return false; } } }';
    withMustUsePlugin('provemark-test-foreign-composer', $mu, function (): void {
        $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
        $key = wpEval('$key = sprintf("%.22F", microtime(true)); set_transient("doing_cron", $key); echo $key;');
        exec('curl -s -o /dev/null --max-time 120 '.escapeshellarg(TEST_SITE.'/wp-cron.php?doing_wp_cron='.$key));

        expect(storedEntry($id)['state'] ?? null)->toBe('Valid')
            ->and(storedEntry($id)['verifier'] ?? null)->toBe(bundledVerifierVersion());
    });
})->group('SPEC-015');

it('AC3: entities are shown as text', function (): void {
    $id = attachmentWithEntry(sampleEntry(['signer' => ['issuer' => null, 'common_name' => 'Reuters&#x202E;gnp.exe']]));
    $html = detailsHtml($id, true);

    expect($html)->toContain('Reuters&amp;#x202E;gnp.exe')
        ->and(visibleText($html))->toContain('Reuters&#x202E;gnp.exe')
        ->and(visibleText($html))->not->toContain("\u{202E}");
})->group('SPEC-015');

it('AC4: non-images get nothing', function (): void {
    $pdf = (int) wpEval("echo wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'a PDF', 'post_status' => 'inherit'], '/nonexistent.pdf');");
    $image = attachmentWithEntry(null);

    expect(trim(columnHtml($pdf)))->toBe('')
        ->and(detailsHtml($pdf, true))->not->toContain('provemark-c2pa')
        ->and(listedIds([$pdf, $image], ['provemark_c2pa' => 'unchecked'])['ids'])->toBe([$image])
        ->and(listedIds([$pdf, $image], ['provemark_c2pa' => 'pending'])['ids'])->toBe([]);
})->group('SPEC-015');

it('AC6: restrict_manage_posts without arguments, or with null', function (): void {
    // Without arguments do_action() passes '' (measured: never fatal); a
    // list table that passes null was a TypeError.
    expect(wpEval("do_action('restrict_manage_posts'); echo 'survived';"))->toBe('survived')
        ->and(wpEval("do_action('restrict_manage_posts', null, null); echo 'survived';"))->toBe('survived');
})->group('SPEC-015');

it('AC7: deactivation cleans up', function (): void {
    runPendingChecks();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    expect(scheduledChecks($id))->toBe(1)
        ->and(pendingMarker($id))->not->toBeNull();

    try {
        wpEval("deactivate_plugins('".PLUGIN_FILE."');");
        expect(wpEval("echo count(array_filter(_get_cron_array() ?: [], fn (\$h) => isset(\$h['provemark_c2pa_check'])));"))->toBe('0')
            ->and(wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_provemark_c2pa_pending'\");"))->toBe('0');
    } finally {
        wpEval("activate_plugin('".PLUGIN_FILE."');");
    }
})->group('SPEC-015');

it('AC8: deleted during its check', function (): void {
    runPendingChecks();
    $id = importWithoutChecking(fixturePath('fixture-signed.jpg'));
    $mu = '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { $id = (int) get_option("provemark_test_delete_id"); if ($id > 0 && doing_action("provemark_c2pa_check")) { delete_option("provemark_test_delete_id"); wp_delete_attachment($id, true); } return $v; });';
    withMustUsePlugin('provemark-test-delete-during-check', $mu, function () use ($id): void {
        wpEval("update_option('provemark_test_delete_id', $id, false);");
        runPendingChecks();
    });

    expect(wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE post_id = $id AND meta_key LIKE '\\\\_provemark\\\\_c2pa\\\\_%'\");"))->toBe('0')
        ->and(wpEval("echo get_post_type($id) === false ? 'gone' : 'there';"))->toBe('gone');
})->group('SPEC-015');

it('AC9: many codes are shown with how many more', function (): void {
    $codes = array_map(fn (int $i): string => 'assertion.dataHash.mismatch.'.$i, range(1, 50));
    $id = attachmentWithEntry(sampleEntry(['state' => 'Invalid', 'codes' => $codes, 'codes_omitted' => 70]));

    expect(visibleText(detailsHtml($id, true)))->toContain('and 70 more');
})->group('SPEC-015');

it('AC10: the command raises memory', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    // WP-CLI runs without a limit (-1) and sets that again while it boots;
    // lower it right before the command, as a host's limit would be.
    $mu = '<?php if (defined("WP_CLI") && WP_CLI) { WP_CLI::add_hook("before_invoke:provemark-c2pa check", static function () { ini_set("memory_limit", "64M"); }); } add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (defined("WP_CLI") && WP_CLI) { update_option("provemark_test_memory", ini_get("memory_limit"), false); } return $v; });';
    withMustUsePlugin('provemark-test-cli-memory', $mu, function () use ($id): void {
        wpEval("delete_option('provemark_test_memory');");
        wpCli(['provemark-c2pa', 'check', (string) $id]);

        expect(wpEval("echo get_option('provemark_test_memory');"))->toBe(wpEval('echo WP_MAX_MEMORY_LIMIT;'));
        wpEval("delete_option('provemark_test_memory');");
    });
})->group('SPEC-015');
