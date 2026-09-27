<?php

declare(strict_types=1);

// The plugin's classes refuse to load without WordPress (SPEC-012); the unit
// tests load them without it. Nothing is read from this path.
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/provemark-c2pa-no-wordpress/');
}

// The integration suite starts from an empty test environment (never the
// development one): no attachments, no plugin data. Otherwise it grows with
// every local run, and `--all` in RecheckTest checks everything left over.
uses()->beforeAll(fn () => emptyTestEnvironment())->in('Integration');
use Provemark\C2paCheck\TrustConfig;

/**
 * The name of this project's running wp-env "cli" container: the
 * development environment (.wp-env.json), or a variant such as `test`
 * (.wp-env.test.json, the integration tests) or `release`
 * (.wp-env.release.json). wp-env names its Compose project
 * "wp-env-<folder>[-<variant>]-<8 hex>" (read in its load-config.js).
 */
function cliContainer(string $variant = ''): string
{
    /** @var array<string, string> $names */
    static $names = [];
    if (isset($names[$variant])) {
        return $names[$variant];
    }

    $project = '/^wp-env-'.preg_quote(strtolower(basename(dirname(__DIR__))), '/').($variant === '' ? '' : '-'.preg_quote($variant, '/')).'-[0-9a-f]{8}$/';
    exec('docker ps --filter label=com.docker.compose.service=cli --format '.escapeshellarg('{{.Label "com.docker.compose.project"}} {{.Names}}'), $lines);
    foreach ($lines as $line) {
        [$name, $container] = explode(' ', $line.' ', 2);
        if (preg_match($project, $name) === 1) {
            return $names[$variant] = trim($container);
        }
    }

    throw new RuntimeException('No running wp-env cli container matching '.$project.'; start it first.');
}

/**
 * Runs a WP-CLI command in the wp-env "cli" container.
 *
 * Straight through `docker exec`, with the container's own user and working
 * directory, as `wp-env run cli` does: 0.23 s a call instead of 1.09 s
 * (measured), because `wp-env run` starts Node first every time.
 *
 * @param  list<string>  $args  WP-CLI arguments, each passed as one shell argument
 * @param  string  $variant  'test' for the integration tests' own environment
 *                           (.wp-env.test.json), 'release' for the clean one; never
 *                           '' (the development environment, which is Maurice's)
 * @return array{exit: int, output: string}
 */
function wpCli(array $args, string $variant = 'test'): array
{
    $command = 'docker exec '.escapeshellarg(cliContainer($variant)).' wp '
        .implode(' ', array_map(escapeshellarg(...), $args))
        .' 2>&1';

    exec($command, $lines, $exit);

    return ['exit' => $exit, 'output' => trim(implode("\n", $lines))];
}

/**
 * Absolute path of a fixture on the host.
 */
function fixturePath(string $name): string
{
    return __DIR__.'/Fixtures/'.$name;
}

/**
 * The same file as the WordPress container sees it: the plugin directory
 * is mapped to wp-content/plugins/provemark-c2pa-check (.wp-env.json).
 */
function containerPath(string $hostPath): string
{
    return '/var/www/html/wp-content/plugins/provemark-c2pa-check/'.substr($hostPath, strlen(dirname(__DIR__)) + 1);
}

/**
 * A scratch directory for files the tests make, inside the plugin directory
 * so that the container sees it too.
 */
function tmpDir(): string
{
    $dir = __DIR__.'/tmp';
    if (! is_dir($dir)) {
        mkdir($dir);
    }

    return $dir;
}

/**
 * A copy of fixture-signed.jpg with one byte of image data changed: 100
 * bytes before the end, inside the entropy-coded scan, after the manifest.
 */
function alteredSignedJpeg(): string
{
    $bytes = (string) file_get_contents(fixturePath('fixture-signed.jpg'));
    $offset = strlen($bytes) - 100;
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
    $path = tmpDir().'/altered-signed.jpg';
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * A copy of the OpenAI PNG with one byte of image data changed: the first
 * IDAT chunk's 100th data byte, with that chunk's CRC recomputed, so the
 * PNG stays well formed and only the content differs from what was signed.
 */
function tamperedOpenAiPng(): string
{
    $bytes = (string) file_get_contents(fixturePath('openai-20260826-c2pa_2x.png'));
    $chunk = strpos($bytes, 'IDAT') - 4;
    $unpacked = unpack('N', substr($bytes, $chunk, 4));
    $length = is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : throw new RuntimeException('no IDAT length');
    $data = $chunk + 8;
    $bytes[$data + 100] = chr(ord($bytes[$data + 100]) ^ 0x01);
    $bytes = substr_replace($bytes, pack('N', crc32(substr($bytes, $chunk + 4, 4 + $length))), $data + $length, 4);
    $path = tmpDir().'/tampered-openai.png';
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * A copy of the remote-manifest fixture whose XMP manifest URL holds a
 * backslash: one "/" replaced by "\\", same length. The file has no
 * manifest of its own, so no signature is touched.
 */
function backslashRemoteManifestJpeg(): string
{
    $bytes = (string) file_get_contents(fixturePath('adobe-20260304-photoshop-remote-manifest.jpg'));
    $path = tmpDir().'/backslash-remote-manifest.jpg';
    file_put_contents($path, str_replace('adobe.com/manifests/', 'adobe.com/manifests\\', $bytes));

    return $path;
}

/**
 * The oracle: what the verifier's CLI reports for a file, without settings.
 *
 * @return array<mixed>
 */
function cliReport(string $path, ?string $settingsFile = null): array
{
    $settings = $settingsFile === null ? '' : '--settings '.escapeshellarg($settingsFile).' ';
    exec(escapeshellarg(dirname(__DIR__).'/vendor/bin/c2pa-verify').' '.$settings.escapeshellarg($path).' 2>/dev/null', $lines);
    $report = json_decode(implode("\n", $lines), true);

    return is_array($report) ? $report : throw new RuntimeException('c2pa-verify gave no report for '.$path);
}

/**
 * The stored entry SPEC-001 requires for a file, derived from the CLI's
 * report: the keys that do not depend on when or with which version the
 * check ran.
 *
 * @return array<string, mixed>
 */
function expectedEntry(string $path, ?string $settingsFile = null): array
{
    $report = cliReport($path, $settingsFile);
    $manifests = is_array($report['manifests'] ?? null) ? $report['manifests'] : [];
    $active = $manifests[is_string($report['active_manifest'] ?? null) ? $report['active_manifest'] : ''] ?? [];
    $info = is_array($active) && is_array($active['signature_info'] ?? null) ? $active['signature_info'] : null;
    $state = ($report['has_manifest'] ?? false) === true ? $report['validation_state'] : 'none';
    $failures = is_array($report['validation_status'] ?? null) ? $report['validation_status'] : [];

    return [
        'schema' => 1,
        'state' => $state,
        'format' => $report['format'],
        'signer' => is_array($info) ? ['issuer' => $info['issuer'] ?? null, 'common_name' => $info['common_name']] : null,
        'signed_at' => is_array($info) ? ($info['time'] ?? null) : null,
        'codes' => $state === 'Invalid' ? array_column($failures, 'code') : [],
        'ai' => claimsTrainedAi(is_array($active) ? $active : []),
        'reason' => null,
    ];
}

/**
 * The settings the plugin uses by default (SPEC-004): the bundled lists,
 * with or without DigiCert, as TrustConfig builds them, written to a file
 * so the CLI reads the same bytes.
 */
function defaultSettingsFile(bool $digiCert = true): string
{
    $json = (new TrustConfig(dirname(__DIR__).'/trust', '', $digiCert))->settingsJson();
    $path = tmpDir().'/default'.($digiCert ? '-digicert' : '').'.settings.json';
    file_put_contents($path, (string) $json);

    return $path;
}

/**
 * Custom settings whose only anchor is the public c2pa-rs test root that
 * the fixture-signed.* chain ends in.
 */
function customSettingsJson(): string
{
    return (string) json_encode([
        'verify' => ['verify_trust' => true],
        'trust' => ['anchors' => [[
            'trust_anchors' => (string) file_get_contents(fixturePath('c2pa-rs-test-trust-anchors.pem')),
            'trust_kind' => 'manifest',
        ]]],
    ], JSON_UNESCAPED_SLASHES);
}

function customSettingsFile(): string
{
    $path = tmpDir().'/custom.settings.json';
    file_put_contents($path, customSettingsJson());

    return $path;
}

/**
 * Sets a WordPress option to exactly $value (through base64 JSON).
 */
function setOption(string $name, mixed $value): void
{
    $payload = base64_encode((string) json_encode($value));
    // Deleted and added, not updated: without the settings registered
    // (they are on admin_init, SPEC-012) update_option() to false on a
    // missing option stores nothing. The custom settings keep autoload off,
    // as the plugin adds them.
    $autoload = $name === 'provemark_c2pa_custom_trust' ? 'false' : 'null';
    wpEval("delete_option('$name'); add_option('$name', json_decode(base64_decode('$payload'), true), '', $autoload);");
}

// Finds the plugin's registerSettings callback on a hook, so a test can run
// it the way options.php does (through admin_init) without firing all of
// core's admin_init callbacks in WP-CLI.
const REGISTER_SETTINGS_ON = <<<'PHP'
    $registerSettingsOn = static function (string $hook): ?Closure {
        global $wp_filter;
        foreach (isset($wp_filter[$hook]) ? $wp_filter[$hook]->callbacks : [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if ($function instanceof Closure) {
                    $reflection = new ReflectionFunction($function);
                    if ($reflection->getName() === 'registerSettings' && $reflection->getClosureScopeClass()?->getName() === Provemark\C2paCheck\SettingsPage::class) {
                        return $function;
                    }
                }
            }
        }

        return null;
    };
    PHP;

/**
 * Puts the plugin's options back to their defaults.
 */
function resetTrustOptions(): void
{
    wpEval("delete_option('provemark_c2pa_custom_trust'); delete_option('provemark_c2pa_digicert'); delete_option('provemark_c2pa_trust_failed');");
}

/**
 * Runs PHP in WordPress as the given user.
 */
function wpEvalAs(string $user, string $php): string
{
    return wpCli(['eval', $php, '--user='.$user])['output'];
}

/**
 * The oracle's side of SPEC-003: does an action in this (CLI-printed)
 * manifest's actions assertion carry the IPTC trainedAlgorithmicMedia URI?
 *
 * @param  array<mixed>  $manifest
 */
function claimsTrainedAi(array $manifest): bool
{
    foreach (is_array($manifest['assertions'] ?? null) ? $manifest['assertions'] : [] as $assertion) {
        if (! is_array($assertion) || ! in_array($assertion['label'] ?? null, ['c2pa.actions', 'c2pa.actions.v2'], true)) {
            continue;
        }
        $actions = is_array($assertion['data'] ?? null) && is_array($assertion['data']['actions'] ?? null) ? $assertion['data']['actions'] : [];
        foreach ($actions as $action) {
            if (is_array($action) && ($action['digitalSourceType'] ?? null) === 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia') {
                return true;
            }
        }
    }

    return false;
}

/**
 * A SPEC-001 entry with the given fields replaced, for display tests.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function sampleEntry(array $fields): array
{
    return $fields + [
        'schema' => 1,
        'state' => 'Valid',
        'format' => 'jpeg',
        'signer' => ['issuer' => 'C2PA Test Signing Cert', 'common_name' => 'C2PA Signer'],
        'signed_at' => null,
        'codes' => [],
        'ai' => false,
        'remote_manifest_url' => null,
        'reason' => null,
        'verifier' => 'v0.2.3',
        'checked_at' => '2026-09-26T12:00:00Z',
    ];
}

/**
 * The entry without the keys that vary per run.
 *
 * @param  array<mixed>  $entry
 * @return array<mixed>
 */
function stable(array $entry): array
{
    unset($entry['verifier'], $entry['checked_at'], $entry['remote_manifest_url'], $entry['trust'], $entry['file'], $entry['size'], $entry['modified']);

    return $entry;
}

/**
 * Uploads a host file into WordPress with WP-CLI, runs the background check
 * the upload scheduled (SPEC-013), and returns the attachment ID: what an
 * upload gives once WP-Cron has run.
 */
function importMedia(string $hostPath): int
{
    $id = importWithoutChecking($hostPath);
    runPendingChecks();

    return $id;
}

/**
 * Uploads a host file with WP-CLI and returns the attachment ID, leaving the
 * background check it scheduled (SPEC-013) unrun.
 */
function importWithoutChecking(string $hostPath): int
{
    $result = wpCli(['media', 'import', containerPath($hostPath), '--porcelain']);
    $id = (int) $result['output'];

    return $id > 0 ? $id : throw new RuntimeException('wp media import failed: '.$result['output']);
}

/**
 * Runs the plugin's check queue once (SPEC-017), as wp-cron.php runs its
 * event: unscheduled first, then its action, in a request of its own.
 * Prints `RAN:<checks>`, the pending markers the run cleared, when the
 * request survives.
 */
const RUN_PENDING_CHECKS = <<<'PHP'
    global $wpdb;
    $pending = fn (): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_provemark_c2pa_pending'");
    $before = $pending();
    $queued = false;
    foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
        foreach ($hooks['provemark_c2pa_check'] ?? [] as $event) {
            wp_unschedule_event($timestamp, 'provemark_c2pa_check', $event['args']);
            $queued = true;
        }
    }
    if ($queued) {
        do_action('provemark_c2pa_check');
    }
    echo 'RAN:', $before - $pending();
    PHP;

/**
 * Runs the check queue once in an environment (on the site at $url for the
 * multisite one) and returns what the request printed.
 */
function runPendingChecksOutput(string $variant = 'test', ?string $url = null): string
{
    $args = ['eval', RUN_PENDING_CHECKS, '--user=admin'];
    if ($url !== null) {
        $args[] = '--url='.$url;
    }

    return wpCli($args, $variant)['output'];
}

/**
 * How many checks one run of the queue made; 0 when the request died.
 */
function runPendingChecks(string $variant = 'test', ?string $url = null): int
{
    return preg_match('/RAN:(\d+)/', runPendingChecksOutput($variant, $url), $m) === 1 ? (int) $m[1] : 0;
}

/**
 * 1 when a check of the attachment is coming: it is marked pending and the
 * queue is scheduled (SPEC-017); else 0.
 */
function scheduledChecks(int $id): int
{
    return (int) wpEval("echo get_post_meta($id, '_provemark_c2pa_pending', true) !== '' && wp_next_scheduled('provemark_c2pa_check') !== false ? 1 : 0;");
}

/**
 * How many provemark_c2pa_check events are scheduled, and how many of them
 * carry arguments.
 *
 * @return array{events: int, with_args: int}
 */
function queueEvents(): array
{
    $out = json_decode(wpEval("\$n = 0; \$a = 0; foreach (_get_cron_array() ?: [] as \$hooks) { foreach (\$hooks['provemark_c2pa_check'] ?? [] as \$event) { \$n++; \$a += (\$event['args'] ?? []) === [] ? 0 : 1; } } echo json_encode(['events' => \$n, 'with_args' => \$a]);"), true);

    return ['events' => is_array($out) && is_int($out['events'] ?? null) ? $out['events'] : -1, 'with_args' => is_array($out) && is_int($out['with_args'] ?? null) ? $out['with_args'] : -1];
}

/**
 * The pending marker of an attachment (SPEC-013), or null.
 */
function pendingMarker(int $id): ?int
{
    $value = wpEval("echo get_post_meta($id, '_provemark_c2pa_pending', true);");

    return is_numeric($value) ? (int) $value : null;
}

/**
 * Edits an attachment's image the way WordPress's image editor saves it:
 * rotated a quarter turn, applied to every size (SPEC-014).
 */
function editImage(int $id): void
{
    wpEval("require_once ABSPATH.'wp-admin/includes/image-edit.php'; require_once ABSPATH.'wp-admin/includes/image.php'; \$_REQUEST['history'] = wp_json_encode([['r' => 90]]); \$_REQUEST['target'] = 'all'; \$_REQUEST['context'] = ''; wp_save_image($id);");
}

/**
 * "Restore original image" in WordPress's image editor (SPEC-014).
 */
function restoreImage(int $id): void
{
    wpEval("require_once ABSPATH.'wp-admin/includes/image-edit.php'; require_once ABSPATH.'wp-admin/includes/image.php'; wp_restore_image($id);");
}

/**
 * The attachment's current original (wp_get_original_image_path()),
 * relative to the uploads folder.
 */
function currentOriginal(int $id): string
{
    return wpEval("echo ltrim(substr((string) wp_get_original_image_path($id), strlen(wp_get_upload_dir()['basedir'])), '/');");
}

/**
 * The file an attachment's `_wp_attached_file` names, relative to uploads.
 */
function attachedFile(int $id): string
{
    return wpEval("echo get_post_meta($id, '_wp_attached_file', true);");
}

/**
 * A host copy of a file in the test environment's uploads folder, for the
 * verifier CLI.
 */
function hostCopyOfUpload(string $relative): string
{
    $copy = tmpDir().'/'.basename($relative);
    $uploads = wpEval("echo wp_get_upload_dir()['basedir'];");
    exec('docker cp '.escapeshellarg(cliContainer('test').':'.$uploads.'/'.$relative).' '.escapeshellarg($copy));

    return $copy;
}

/**
 * A host copy of the attachment's current original, for the verifier CLI.
 */
function hostCopyOfOriginal(int $id): string
{
    $relative = currentOriginal($id);
    $copy = tmpDir().'/'.basename($relative);
    $uploads = wpEval("echo wp_get_upload_dir()['basedir'];");
    exec('docker cp '.escapeshellarg(cliContainer('test').':'.$uploads.'/'.$relative).' '.escapeshellarg($copy));

    return $copy;
}

/** Runs the plugin's uninstall.php as deleting the plugin does. */
const UNINSTALL_PLUGIN_PHP = "require_once ABSPATH.'wp-admin/includes/plugin.php'; uninstall_plugin('provemark-c2pa-check/provemark-c2pa-check.php');";

const TEST_SITE = 'http://localhost:8892';

/**
 * Uploads a host file to the test environment over HTTP as its
 * administrator (wp-env's default account), through the route the block
 * editor uses (`rest`, POST /wp/v2/media) or the Media Library's
 * (`async`, async-upload.php). Returns the HTTP status, the body and the
 * attachment ID the response names (0 when it names none).
 *
 * @param  'rest'|'async'  $route
 * @return array{status: int, body: string, id: int}
 */
function httpUpload(string $route, string $hostPath, string $filename): array
{
    $jar = tmpDir().'/admin-cookies.txt';
    $body = tmpDir().'/upload-response.txt';
    @unlink($body);
    exec('curl -s -c '.escapeshellarg($jar).' -b '.escapeshellarg('wordpress_test_cookie=WP Cookie check').' -o /dev/null -d '.escapeshellarg('log=admin&pwd=password&testcookie=1').' '.escapeshellarg(TEST_SITE.'/wp-login.php'));

    if ($route === 'rest') {
        $nonce = trim((string) shell_exec('curl -s -b '.escapeshellarg($jar).' '.escapeshellarg(TEST_SITE.'/wp-admin/admin-ajax.php?action=rest-nonce')));
        $status = (int) shell_exec('curl -s -o '.escapeshellarg($body).' -w "%{http_code}" -b '.escapeshellarg($jar)
            .' -H '.escapeshellarg('X-WP-Nonce: '.$nonce)
            .' -H '.escapeshellarg('Content-Disposition: attachment; filename='.$filename)
            .' -H '.escapeshellarg('Content-Type: image/jpeg')
            .' --data-binary '.escapeshellarg('@'.$hostPath).' '.escapeshellarg(TEST_SITE.'/wp-json/wp/v2/media'));
    } else {
        $page = (string) shell_exec('curl -s -b '.escapeshellarg($jar).' '.escapeshellarg(TEST_SITE.'/wp-admin/upload.php'));
        $nonce = preg_match('/"_wpnonce":"([^"]+)"/', $page, $m) === 1 ? $m[1] : '';
        $status = (int) shell_exec('curl -s -o '.escapeshellarg($body).' -w "%{http_code}" -b '.escapeshellarg($jar)
            .' -F '.escapeshellarg('async-upload=@'.$hostPath.';filename='.$filename.';type=image/jpeg')
            .' -F '.escapeshellarg('name='.$filename).' -F action=upload-attachment -F '.escapeshellarg('_wpnonce='.$nonce)
            .' '.escapeshellarg(TEST_SITE.'/wp-admin/async-upload.php'));
    }

    $text = (string) @file_get_contents($body);
    $json = json_decode($text, true);
    $id = is_array($json) ? ($json['id'] ?? (is_array($json['data'] ?? null) ? ($json['data']['id'] ?? 0) : 0)) : 0;

    return ['status' => $status, 'body' => $text, 'id' => is_int($id) ? $id : 0];
}

/**
 * How many image sizes WordPress made for an attachment; -1 when it has no
 * metadata at all.
 */
function imageSizes(int $id): int
{
    return (int) wpEval("\$m = wp_get_attachment_metadata($id); echo is_array(\$m) && \$m !== [] ? count(\$m['sizes'] ?? []) : -1;");
}

/**
 * Runs $test with a must-use plugin in the test environment that exhausts
 * memory whenever the plugin checks a file (in the upload or in its
 * background check), as a check that dies does; removed afterwards.
 *
 * @template T
 *
 * @param  callable(): T  $test
 * @return T
 */
function withCheckThatDies(callable $test): mixed
{
    wpEval(<<<'PHP'
        if (! is_dir(WPMU_PLUGIN_DIR)) { mkdir(WPMU_PLUGIN_DIR, 0777, true); }
        file_put_contents(WPMU_PLUGIN_DIR.'/provemark-test-check-dies.php', '<?php add_filter("pre_option_provemark_c2pa_digicert", static function ($v) { if (doing_action("add_attachment") || doing_action("provemark_c2pa_check")) { $a = []; while (true) { $a[] = str_repeat("x", 1 << 20); } } return $v; });');
        PHP);
    try {
        return $test();
    } finally {
        wpEval('@unlink(WPMU_PLUGIN_DIR."/provemark-test-check-dies.php");');
    }
}

/**
 * The stored entry of an attachment, or null when there is none.
 *
 * @return array<mixed>|null
 */
function storedEntry(int $id): ?array
{
    $result = wpCli(['post', 'meta', 'get', (string) $id, '_provemark_c2pa_result', '--format=json']);
    $lines = array_values(array_filter(explode("\n", $result['output']), fn (string $l): bool => str_starts_with($l, '{')));
    $entry = $lines === [] ? null : json_decode($lines[0], true);

    return is_array($entry) ? $entry : null;
}

/**
 * Runs PHP in WordPress (wp eval) and returns its output.
 */
function wpEval(string $php): string
{
    return wpCli(['eval', $php, '--user=admin'])['output'];
}

/**
 * An attachment (no file) whose stored entry is exactly $entry, indexed as
 * the plugin indexes a stored entry (SPEC-007); null means no entry and no
 * index at all. The value travels as base64 JSON so no shell quoting can
 * change it.
 */
function attachmentWithEntry(mixed $entry): int
{
    $payload = base64_encode((string) json_encode($entry));
    $out = wpEval(<<<PHP
        \$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'entry', 'post_status' => 'inherit'], '/nonexistent.jpg');
        \$entry = json_decode(base64_decode('$payload'), true);
        // Made here, not uploaded: no pending marker, no scheduled check (SPEC-013).
        delete_post_meta(\$id, '_provemark_c2pa_pending');
        if (\$entry === null) { delete_post_meta(\$id, '_provemark_c2pa_result'); delete_post_meta(\$id, '_provemark_c2pa_state'); delete_post_meta(\$id, '_provemark_c2pa_ai'); } else { update_post_meta(\$id, '_provemark_c2pa_result', wp_slash(\$entry)); if (class_exists('Provemark\\C2paCheck\\Index')) { Provemark\\C2paCheck\\Index::write(\$id, \$entry); } }
        echo 'ID:', \$id, "\n";
        PHP);
    $id = (int) preg_replace('/.*ID:(\d+).*/s', '$1', $out);

    return $id > 0 ? $id : throw new RuntimeException('could not make an attachment: '.$out);
}

/**
 * The Media Library list-mode cell for an attachment, as HTML.
 */
function columnHtml(int $id): string
{
    return wpEval("do_action('manage_media_custom_column', 'provemark_c2pa', $id);");
}

/**
 * The attachment-details rows as WordPress renders them: on Edit Media
 * (in_modal false) or in a media modal (in_modal true).
 */
function detailsHtml(int $id, bool $inModal): string
{
    $modal = $inModal ? 'true' : 'false';

    return wpEval("require_once ABSPATH.'wp-admin/includes/media.php'; echo get_compat_media_markup($id, ['in_modal' => $modal])['item'];");
}

/**
 * What a reader sees: the text of some HTML, entities decoded.
 */
function visibleText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * Elements and event-handler attributes that HTML would create.
 *
 * @return list<string>
 */
function activeMarkup(string $html): array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR);
    $found = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if (in_array(strtolower($el->nodeName), ['script', 'img', 'a', 'iframe', 'svg'], true)) {
            $found[] = '<'.$el->nodeName.'>';
        }
        foreach ($el->attributes ?? [] as $attr) {
            if (str_starts_with(strtolower($attr->nodeName), 'on') || strtolower($attr->nodeName) === 'href') {
                $found[] = $attr->nodeName.'=';
            }
        }
    }

    return $found;
}

/**
 * The built zip (composer build).
 */
function releaseZip(): string
{
    return dirname(__DIR__).'/build/provemark-c2pa-check.zip';
}

/**
 * Installs and activates the built zip in the clean release environment,
 * replacing whatever was there.
 */
function installReleaseZip(): void
{
    $result = wpCli(['plugin', 'install', '/var/www/html/release/provemark-c2pa-check.zip', '--force', '--activate'], 'release');
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not install the release zip: '.$result['output']);
    }
}

/**
 * Runs PHP in the release environment as its administrator.
 */
function releaseEval(string $php): string
{
    return wpCli(['eval', $php, '--user=admin'], 'release')['output'];
}

/**
 * Uploads a fixture into the release environment (tests/Fixtures is
 * mapped to /var/www/html/fixtures there) and returns the attachment ID.
 */
function releaseImport(string $fixture): int
{
    $result = wpCli(['media', 'import', '/var/www/html/fixtures/'.$fixture, '--porcelain'], 'release');
    $id = (int) $result['output'];
    if ($id <= 0) {
        throw new RuntimeException('import failed: '.$result['output']);
    }
    ReleaseUploads::$ids[] = $id;
    runPendingChecks('release');

    return $id;
}

/**
 * The attachments the release tests uploaded in this run, so they can be
 * removed afterwards (and nothing else in that environment).
 */
final class ReleaseUploads
{
    /** @var list<int> */
    public static array $ids = [];
}

/**
 * Deletes, with their files, the attachments this run uploaded to the
 * release environment.
 */
function removeReleaseUploads(): void
{
    if (ReleaseUploads::$ids !== []) {
        releaseEval('foreach (['.implode(',', ReleaseUploads::$ids).'] as $id) { wp_delete_attachment($id, true); }');
    }
    ReleaseUploads::$ids = [];
}

/**
 * @return array<mixed>|null
 */
function releaseEntry(int $id): ?array
{
    $out = wpCli(['post', 'meta', 'get', (string) $id, '_provemark_c2pa_result', '--format=json'], 'release')['output'];
    $entry = json_decode($out, true);

    return is_array($entry) ? $entry : null;
}

/**
 * The WordPress Coding Standards security sniffs run on the shipped
 * verifier (SPEC-006 AC4; the set measured in notes/m5-packaging.md).
 */
const WPCS_SNIFFS = [
    'WordPress.Security.EscapeOutput', 'WordPress.Security.ValidatedSanitizedInput',
    'WordPress.Security.NonceVerification', 'WordPress.WP.AlternativeFunctions',
    'WordPress.PHP.DiscouragedPHPFunctions', 'WordPress.PHP.DevelopmentFunctions',
    'WordPress.DB.RestrictedFunctions', 'WordPress.WP.DiscouragedFunctions',
];

/**
 * Findings per sniff code for the PHP files under $dir.
 *
 * @return array<string, int>
 */
function wpcsFindings(string $dir): array
{
    exec(escapeshellarg(dirname(__DIR__).'/vendor/bin/phpcs').' --standard=WordPress --sniffs='.implode(',', WPCS_SNIFFS).' --report=json -q '.escapeshellarg($dir).' 2>/dev/null', $lines);
    $report = json_decode(implode("\n", $lines), true);
    $counts = [];
    foreach (is_array($report) && is_array($report['files'] ?? null) ? $report['files'] : [] as $file) {
        foreach (is_array($file) && is_array($file['messages'] ?? null) ? $file['messages'] : [] as $message) {
            $source = is_array($message) && is_string($message['source'] ?? null) ? $message['source'] : '?';
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }
    }
    ksort($counts);

    return $counts;
}

/**
 * Where $findings go beyond the reviewed baseline: a sniff the baseline
 * does not have, or a higher count.
 *
 * @param  array<string, int>  $findings
 * @param  array<string, int>  $baseline
 * @return list<string>
 */
function beyondBaseline(array $findings, array $baseline): array
{
    $beyond = [];
    foreach ($findings as $sniff => $count) {
        if ($count > ($baseline[$sniff] ?? 0)) {
            $beyond[] = $sniff.': '.$count.' (baseline '.($baseline[$sniff] ?? 0).')';
        }
    }

    return $beyond;
}

/**
 * The SPEC-007 index keys of an attachment in the development environment.
 *
 * @return array{state: string, ai: string}
 */
function indexOf(int $id): array
{
    $out = wpEval("echo json_encode(['state' => get_post_meta($id, '_provemark_c2pa_state', true), 'ai' => get_post_meta($id, '_provemark_c2pa_ai', true)]);");
    $decoded = json_decode($out, true);

    return [
        'state' => is_array($decoded) && is_string($decoded['state'] ?? null) ? $decoded['state'] : '',
        'ai' => is_array($decoded) && is_string($decoded['ai'] ?? null) ? $decoded['ai'] : '',
    ];
}

/**
 * The attachment IDs a Media Library list query returns when the plugin's
 * query change sees $request, limited to $ids; and the SQL it ran.
 *
 * @param  list<int>  $ids
 * @param  array<string, mixed>  $request
 * @return array{ids: list<int>, sql: string}
 */
function listedIds(array $ids, array $request): array
{
    $in = implode(',', $ids);
    $payload = base64_encode((string) json_encode($request));
    $out = wpEval(<<<PHP
        \$request = json_decode(base64_decode('$payload'), true);
        add_action('pre_get_posts', function (WP_Query \$q) use (\$request): void { Provemark\\C2paCheck\\MediaSort::apply(\$q, \$request); });
        \$q = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'post__in' => [$in], 'fields' => 'ids']);
        echo json_encode(['ids' => array_map('intval', \$q->posts), 'sql' => \$q->request]);
        PHP);
    $decoded = json_decode($out, true);
    $found = is_array($decoded) && is_array($decoded['ids'] ?? null) ? array_values(array_filter($decoded['ids'], is_int(...))) : [];

    return ['ids' => $found, 'sql' => is_array($decoded) && is_string($decoded['sql'] ?? null) ? $decoded['sql'] : $out];
}

/**
 * Removes every attachment, its files, the plugin's meta and options from
 * the test environment (.wp-env.test.json) in one request. Never runs
 * against the development environment: wpEval() defaults to `test`.
 */
function emptyTestEnvironment(): void
{
    wpEval(<<<'PHP'
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($in)");
        }
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_provemark\_c2pa\_%'");
        foreach (['provemark_c2pa_digicert', 'provemark_c2pa_custom_trust', 'provemark_c2pa_trust_failed', 'provemark_c2pa_index_done'] as $option) {
            delete_option($option);
        }
        wp_unschedule_hook('provemark_c2pa_check');
        @unlink(WPMU_PLUGIN_DIR.'/provemark-test-check-dies.php');
        $uploads = wp_get_upload_dir()['basedir'];
        if (is_dir($uploads)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        PHP);
}

const NETWORK_MAIN = 'http://localhost:8894/';

const NETWORK_SITE2 = 'http://localhost:8894/site2/';

/**
 * WP-CLI in the multisite environment, on the site at $url.
 *
 * @param  list<string>  $args
 * @return array{exit: int, output: string}
 */
function networkCli(array $args, string $url = NETWORK_MAIN): array
{
    return wpCli([...$args, '--url='.$url], 'multisite');
}

/**
 * PHP in the multisite environment, as the network's administrator, on the
 * site at $url.
 */
function networkEval(string $php, string $url = NETWORK_MAIN): string
{
    return networkCli(['eval', $php, '--user=admin'], $url)['output'];
}

/**
 * Uploads a fixture on the site at $url (tests/Fixtures is mapped to
 * /var/www/html/fixtures) and returns its attachment ID on that site.
 */
function networkImport(string $fixture, string $url = NETWORK_MAIN): int
{
    $result = networkCli(['media', 'import', '/var/www/html/fixtures/'.$fixture, '--porcelain'], $url);
    $id = (int) $result['output'];
    if ($id <= 0) {
        throw new RuntimeException('import failed: '.$result['output']);
    }
    runPendingChecks('multisite', $url);

    return $id;
}

/**
 * The stored entry of an attachment on the site at $url.
 *
 * @return array<mixed>|null
 */
function networkEntry(int $id, string $url = NETWORK_MAIN): ?array
{
    $entry = json_decode(networkCli(['post', 'meta', 'get', (string) $id, '_provemark_c2pa_result', '--format=json'], $url)['output'], true);

    return is_array($entry) ? $entry : null;
}
