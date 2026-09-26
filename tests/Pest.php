<?php

declare(strict_types=1);

/**
 * Runs a WP-CLI command in the wp-env "cli" container.
 *
 * @param  list<string>  $args  WP-CLI arguments, each passed as one shell argument
 * @return array{exit: int, output: string}
 */
function wpCli(array $args): array
{
    $command = escapeshellarg(dirname(__DIR__).'/node_modules/.bin/wp-env')
        .' run cli wp '
        .implode(' ', array_map(escapeshellarg(...), $args))
        .' 2>&1';

    exec($command, $lines, $exit);

    // wp-env wraps WP-CLI's output in its own status lines (ℹ, ✔, ✖); when
    // the output does not end in a newline, the status is on the same line.
    $lines = array_map(fn (string $line): string => (string) preg_replace('/(ℹ|✔|✖) (Starting|Ran|Command) .*$/u', '', $line), $lines);
    $lines = array_filter($lines, fn (string $line): bool => $line !== '');

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
 * The oracle: what the verifier's CLI reports for a file, without settings.
 *
 * @return array<mixed>
 */
function cliReport(string $path): array
{
    exec(escapeshellarg(dirname(__DIR__).'/vendor/bin/c2pa-verify').' '.escapeshellarg($path).' 2>/dev/null', $lines);
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
function expectedEntry(string $path): array
{
    $report = cliReport($path);
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
        'reason' => null,
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
    unset($entry['verifier'], $entry['checked_at'], $entry['remote_manifest_url']);

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
 * An attachment (no file) whose stored entry is exactly $entry; null means
 * no entry at all. The value travels as base64 JSON so no shell quoting can
 * change it.
 */
function attachmentWithEntry(mixed $entry): int
{
    $payload = base64_encode((string) json_encode($entry));
    $out = wpEval(<<<PHP
        \$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'entry', 'post_status' => 'inherit'], '/nonexistent.jpg');
        \$entry = json_decode(base64_decode('{$payload}'), true);
        if (\$entry === null) { delete_post_meta(\$id, '_provemark_c2pa_result'); } else { update_post_meta(\$id, '_provemark_c2pa_result', wp_slash(\$entry)); }
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
    return wpEval("do_action('manage_media_custom_column', 'provemark_c2pa', {$id});");
}

/**
 * The attachment-details rows as WordPress renders them: on Edit Media
 * (in_modal false) or in a media modal (in_modal true).
 */
function detailsHtml(int $id, bool $inModal): string
{
    $modal = $inModal ? 'true' : 'false';

    return wpEval("require_once ABSPATH.'wp-admin/includes/media.php'; echo get_compat_media_markup({$id}, ['in_modal' => {$modal}])['item'];");
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
