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

    // wp-env wraps WP-CLI's output in its own status lines (ℹ, ✔, ✖).
    $lines = array_filter($lines, fn (string $line): bool => ! preg_match('/^(ℹ|✔|✖) /u', $line));

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
