<?php

declare(strict_types=1);
use Provemark\C2paCheck\TrustConfig;

/**
 * The name of this project's running wp-env "cli" container: the
 * development environment (.wp-env.json), or a variant such as `release`
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
 * @param  string  $variant  '' for the development environment, 'release' for the clean one
 * @return array{exit: int, output: string}
 */
function wpCli(array $args, string $variant = ''): array
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
    wpEval("update_option('$name', json_decode(base64_decode('$payload'), true));");
}

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
    unset($entry['verifier'], $entry['checked_at'], $entry['remote_manifest_url'], $entry['trust']);

    return $entry;
}

/**
 * Uploads a host file into WordPress with WP-CLI and returns the attachment ID.
 */
function importMedia(string $hostPath): int
{
    $result = wpCli(['media', 'import', containerPath($hostPath), '--porcelain']);
    $id = (int) $result['output'];

    return $id > 0 ? $id : throw new RuntimeException('wp media import failed: '.$result['output']);
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

    return $id > 0 ? $id : throw new RuntimeException('import failed: '.$result['output']);
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
