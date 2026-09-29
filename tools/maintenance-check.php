<?php

declare(strict_types=1);

/**
 * SPEC-030: compares what the plugin was released against (Tested up to,
 * the bundled C2PA trust lists, the locked verifier) with what is current,
 * and prints a JSON report `{"title": …, "body": …}` when something is
 * behind, nothing when all are current. It changes nothing. Run weekly by
 * `.github/workflows/maintenance.yml`; not part of the release zip.
 *
 * A value it cannot read is an error (exit 1, no report): a check that
 * cannot find out is never "all current".
 */

/** `Tested up to` in readme.txt, as major.minor. */
function testedUpTo(string $readme): string
{
    return preg_match('/^Tested up to:\s*(\d+\.\d+)\s*$/m', $readme, $m) === 1 ? $m[1] : throw new RuntimeException('readme.txt has no "Tested up to: <major>.<minor>" line');
}

/** The source commit of the bundled C2PA trust lists, from trust/README.md. */
function bundledTrustCommit(string $trustReadme): string
{
    return preg_match('/commit `([0-9a-f]{7,40})`/', $trustReadme, $m) === 1 ? $m[1] : throw new RuntimeException('trust/README.md names no source commit');
}

/** The locked version of provemark/c2pa-verifier, from composer.lock. */
function lockedVerifier(string $composerLock): string
{
    $lock = json_decode($composerLock, true);
    foreach (is_array($lock) && is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
        if (is_array($package) && ($package['name'] ?? null) === 'provemark/c2pa-verifier' && is_string($package['version'] ?? null)) {
            return $package['version'];
        }
    }

    throw new RuntimeException('composer.lock does not lock provemark/c2pa-verifier');
}

/** The latest WordPress release as major.minor, from api.wordpress.org's version check. */
function wordpressMajorMinor(string $versionCheck): string
{
    $data = json_decode($versionCheck, true);
    $offers = is_array($data) && is_array($data['offers'] ?? null) ? $data['offers'] : [];
    $current = is_array($offers[0] ?? null) ? ($offers[0]['current'] ?? null) : null;

    return is_string($current) && preg_match('/^(\d+\.\d+)(\.\d+)?$/', $current, $m) === 1 ? $m[1] : throw new RuntimeException('the WordPress version check gave no current release');
}

/**
 * The latest commit touching trust-list/, from GitHub's list of commits.
 *
 * @return array{sha: string, date: string}
 */
function latestTrustCommit(string $commits): array
{
    $list = json_decode($commits, true);
    $first = is_array($list) && is_array($list[0] ?? null) ? $list[0] : null;
    $sha = $first['sha'] ?? null;
    $commit = is_array($first['commit'] ?? null) ? $first['commit'] : [];
    $committer = is_array($commit['committer'] ?? null) ? $commit['committer'] : [];
    $date = $committer['date'] ?? null;

    return is_string($sha) && preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 && is_string($date)
        ? ['sha' => $sha, 'date' => substr($date, 0, 10)]
        : throw new RuntimeException('GitHub gave no commit for the C2PA trust lists');
}

/**
 * The tag names from GitHub's list of tags.
 *
 * @return list<string>
 */
function tagNames(string $tags): array
{
    $list = json_decode($tags, true);
    if (! is_array($list)) {
        throw new RuntimeException('GitHub gave no tags for the verifier');
    }

    return array_values(array_filter(array_map(static fn (mixed $tag): mixed => is_array($tag) ? ($tag['name'] ?? null) : null, $list), is_string(...)));
}

/**
 * The highest `v<major>.<minor>.<patch>` tag, compared as a version; null when there is none.
 *
 * @param  list<string>  $tags
 */
function newestVersionTag(array $tags): ?string
{
    $versions = array_values(array_filter($tags, static fn (string $tag): bool => preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1));
    usort($versions, static fn (string $a, string $b): int => version_compare(substr($b, 1), substr($a, 1)));

    return $versions[0] ?? null;
}

/**
 * One entry per item that is behind, in the order WordPress, trust lists,
 * verifier; empty when all are current.
 *
 * @param  array{wordpress: string, trust: string, verifier: string}  $local
 * @param  array{wordpress: string, trust: array{sha: string, date: string}, verifier: string}  $current
 * @return list<array{title: string, line: string}>
 */
function maintenanceReport(array $local, array $current): array
{
    $report = [];
    if (version_compare($current['wordpress'], $local['wordpress'], '>')) {
        $report[] = [
            'title' => 'WordPress '.$current['wordpress'],
            'line' => "- WordPress {$current['wordpress']} is out; `readme.txt` says `Tested up to: {$local['wordpress']}`. Run the tests on {$current['wordpress']} first, then change the line (no new plugin version is needed for that alone).",
        ];
    }
    if (! str_starts_with($current['trust']['sha'], $local['trust'])) {
        $short = substr($current['trust']['sha'], 0, 7);
        $report[] = [
            'title' => 'trust lists',
            'line' => "- The C2PA trust lists changed: `c2pa-org/conformance-public` commit `{$short}` ({$current['trust']['date']}); the bundled lists are from `{$local['trust']}` (`trust/README.md`).",
        ];
    }
    if (version_compare(ltrim($current['verifier'], 'v'), ltrim($local['verifier'], 'v'), '>')) {
        $report[] = [
            'title' => 'verifier '.$current['verifier'],
            'line' => "- `provemark/c2pa-verifier` {$current['verifier']} is out; the plugin bundles {$local['verifier']} (`composer.lock`).",
        ];
    }

    return $report;
}

/**
 * The issue title for a report.
 *
 * @param  list<array{title: string, line: string}>  $report
 */
function maintenanceTitle(array $report): string
{
    return 'Maintenance: '.implode(', ', array_column($report, 'title'));
}

/** One HTTPS GET; an answer that is not 200 is an error. */
function fetchUrl(string $url): string
{
    if (getenv('MAINTENANCE_CHECK_OFFLINE') === '1') {
        throw new RuntimeException("offline: not fetching $url");
    }
    $headers = ['User-Agent: tracefern-maintenance-check', 'Accept: application/vnd.github+json'];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '' && str_starts_with($url, 'https://api.github.com/')) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $stream = @fopen($url, 'r', false, stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30]]));
    if ($stream === false) {
        throw new RuntimeException("could not read $url");
    }
    // The response headers from the stream itself: the same on PHP 8.3 to 8.5.
    $meta = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $wrapperData = $meta['wrapper_data'] ?? null;
    $statusLine = is_array($wrapperData) && is_string($wrapperData[0] ?? null) ? $wrapperData[0] : '';
    $status = preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $m) === 1 ? (int) $m[1] : 0;

    return is_string($body) && $status === 200 ? $body : throw new RuntimeException("could not read $url (HTTP $status)");
}

/** Reads a file of the repository. */
function repositoryFile(string $path): string
{
    $content = @file_get_contents(dirname(__DIR__).'/'.$path);

    return is_string($content) ? $content : throw new RuntimeException("could not read $path");
}

function main(): int
{
    try {
        $local = [
            'wordpress' => testedUpTo(repositoryFile('readme.txt')),
            'trust' => bundledTrustCommit(repositoryFile('trust/README.md')),
            'verifier' => lockedVerifier(repositoryFile('composer.lock')),
        ];
        $newest = newestVersionTag(tagNames(fetchUrl('https://api.github.com/repos/provemark/c2pa-verifier/tags?per_page=100')));
        $current = [
            'wordpress' => wordpressMajorMinor(fetchUrl('https://api.wordpress.org/core/version-check/1.7/')),
            'trust' => latestTrustCommit(fetchUrl('https://api.github.com/repos/c2pa-org/conformance-public/commits?path=trust-list&per_page=1')),
            'verifier' => $newest ?? throw new RuntimeException('the verifier has no version tag'),
        ];
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'maintenance check: '.$e->getMessage().PHP_EOL);

        return 1;
    }

    $report = maintenanceReport($local, $current);
    if ($report !== []) {
        echo json_encode(['title' => maintenanceTitle($report), 'body' => implode("\n", array_column($report, 'line'))."\n\nOpened by the weekly maintenance check (SPEC-030). It changes nothing; what to do is a decision for the maintainer.\n"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    return 0;
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($script) && realpath($script) === __FILE__) {
    exit(main());
}
