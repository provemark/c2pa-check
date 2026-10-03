<?php

declare(strict_types=1);

beforeEach(function (): void {
    emptyTestEnvironment();
});

afterEach(fn () => resetTrustOptions());

/**
 * The oracle: how many rows upload.php?mode=list&tracefern=<key> lists for
 * $user, per key of MediaSort::options(). The list's own query vars
 * (wp_edit_attachments_query_vars(), the post statuses included) and the
 * plugin's filter on the main query, as on the Media Library screen.
 *
 * @return array<string, int>
 */
function listedCounts(string $user = 'admin'): array
{
    $out = wpCli(['eval', <<<'PHP'
        require_once ABSPATH.'wp-admin/includes/post.php';
        global $pagenow;
        $pagenow = 'upload.php';
        $screen = 'upload';
        require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH.'wp-admin/includes/screen.php';
        set_current_screen($screen);
        $counts = [];
        foreach (array_keys(Tracefern\ImageCheck\MediaSort::options()) as $key) {
            $_GET = ['mode' => 'list', 'tracefern' => $key];
            $vars = wp_edit_attachments_query_vars($_GET);
            $query = new WP_Query;
            $GLOBALS['wp_the_query'] = $query;
            $GLOBALS['wp_query'] = $query;
            $query->query($vars);
            $counts[$key] = (int) $query->found_posts;
        }
        echo json_encode($counts);
        PHP, '--user='.$user])['output'];
    $decoded = json_decode($out, true);

    $counts = [];
    foreach (is_array($decoded) ? $decoded : throw new RuntimeException('no counts: '.$out) as $key => $count) {
        if (is_string($key) && is_int($count)) {
            $counts[$key] = $count;
        }
    }

    return $counts;
}

/**
 * An image attachment (no file) with a pending marker set $age seconds ago
 * and no entry. Whether it is "Check pending" or "Not checked" depends on
 * its age only once no queue is scheduled (SPEC-013, SPEC-017).
 */
function pendingImage(int $age): int
{
    $id = attachmentWithEntry(null);
    wpEval("update_post_meta($id, '_tracefern_pending', time() - $age);");

    return $id;
}

/**
 * The library of AC1: eight images, one of them in the trash.
 *
 * @return array<string, int>
 */
function summaryLibrary(): array
{
    $ids = [];
    $ids['openai'] = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));
    setOption('tracefern_digicert', false);
    $ids['amazon'] = importMedia(fixturePath('amazon-20240925-titan-g1.png'));
    $ids['unsigned'] = importMedia(fixturePath('fixture-unsigned.jpg'));
    // Imported before the pending markers are set: an import runs the
    // pending checks.
    $ids['trashed'] = importMedia(fixturePath('fixture-signed.jpg'));
    wpEval("wp_trash_post({$ids['trashed']});");
    $ids['error'] = attachmentWithEntry(sampleEntry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => 'exception']));
    $ids['fresh'] = pendingImage(60);
    $ids['old'] = pendingImage(3600 + 600);
    $ids['never'] = attachmentWithEntry(null);
    // Each new attachment schedules the queue, and while it is scheduled
    // every marker counts as pending (SPEC-017): none is, here.
    wpEval("wp_unschedule_hook('tracefern_check');");

    return $ids;
}

it('AC1: shows for every filter the number of images that filter lists', function (): void {
    summaryLibrary();

    $html = (string) dashboardWidget();
    $lines = summaryLines($html);
    $listed = listedCounts();

    expect(array_keys($lines))->toBe(array_keys(SUMMARY_LABELS))
        ->and(array_map(fn (array $line): int|string|null => $line['count'], $lines))->toBe($listed)
        ->and($listed)->toBe([
            'trusted' => 1,
            'valid' => 0,
            'invalid' => 1,
            'ai' => 1,
            'error' => 1,
            'none' => 1,
            'pending' => 1,
            'unchecked' => 2,
        ])
        ->and(summaryTotal($html))->toBe(7);

    foreach ($lines as $key => $line) {
        expect($line['href'])->toBe($line['count'] === 0 ? null : 'http://localhost:8892/wp-admin/upload.php?mode=list&tracefern='.$key);
    }
})->group('SPEC-033');

it('AC1: says the lines need not add up to the total', function (): void {
    summaryLibrary();

    $text = visibleText((string) dashboardWidget());

    expect($text)->toContain('AI-generated (signed)')
        ->and(strtolower($text))->toMatch('/add up/');
})->group('SPEC-033');

it('AC2: is there for those who can upload, and nobody else', function (): void {
    wpCli(['user', 'create', 'm33-subscriber', 'm33-subscriber@example.test', '--role=subscriber']);
    wpCli(['user', 'create', 'm33-author', 'm33-author@example.test', '--role=author']);
    attachmentWithEntry(null);

    $author = dashboardWidget('m33-author');
    $admin = dashboardWidget('admin');

    expect(dashboardWidget('m33-subscriber'))->toBeNull()
        ->and($author)->not->toBeNull()
        ->and($admin)->not->toBeNull()
        ->and(summaryLines((string) $author)['unchecked']['count'] ?? null)->toBe(1)
        ->and(visibleText((string) $author))->not->toContain('Check them')
        ->and(visibleText((string) $admin))->toContain('Check them')
        ->and(array_filter(hrefs((string) $admin), fn (string $h): bool => str_contains($h, 'options-general.php?page=tracefern-image-check-for-c2pa')))->not->toBeEmpty();
})->group('SPEC-033');

it('AC2: offers no "Check them" when every image was checked', function (): void {
    attachmentWithEntry(sampleEntry(['state' => 'Valid']));

    $admin = (string) dashboardWidget('admin');

    expect(summaryLines($admin)['unchecked']['count'] ?? null)->toBe(0)
        ->and(visibleText($admin))->not->toContain('Check them');
})->group('SPEC-033');

it('AC3: says so when there are no images, and shows no lines', function (): void {
    wpEval("wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'a PDF', 'post_status' => 'inherit'], '/nonexistent.pdf');");

    $html = (string) dashboardWidget();

    expect(visibleText($html))->toBe('No JPEG, PNG and WebP images yet.')
        ->and(summaryLines($html))->toBe([])
        ->and(summaryTotal($html))->toBeNull();
})->group('SPEC-033');

// A `query` filter that breaks the one counting query it recognises: the
// error line's (its meta value) or the total's (the MIME types without any
// plugin meta key).
const BREAK_ERROR_LINE = <<<'PHP'
    add_filter('query', function (string $sql): string {
        return str_contains($sql, 'SQL_CALC_FOUND_ROWS') && str_contains($sql, '_tracefern_state') && str_contains($sql, "'error'") ? 'SELECT broken FROM nowhere' : $sql;
    });
    PHP;

const BREAK_TOTAL = <<<'PHP'
    add_filter('query', function (string $sql): string {
        return str_contains($sql, 'SQL_CALC_FOUND_ROWS') && str_contains($sql, 'image/jpeg') && ! str_contains($sql, '_tracefern_') ? 'SELECT broken FROM nowhere' : $sql;
    });
    PHP;

it('AC4: shows "—" for a line whose count failed, never 0', function (): void {
    attachmentWithEntry(sampleEntry(['state' => 'Valid']));

    $html = (string) dashboardWidget('admin', '$GLOBALS["wpdb"]->suppress_errors(true); '.BREAK_ERROR_LINE);
    $lines = summaryLines($html);

    expect($lines['error']['count'] ?? null)->toBe('—')
        ->and($lines['error']['href'] ?? 'a link')->toBeNull()
        ->and($lines['valid']['count'] ?? null)->toBe(1)
        ->and(summaryTotal($html))->toBe(1)
        ->and(strtolower(visibleText($html)))->toContain('could not be read');
})->group('SPEC-033');

it('AC4: shows "—" for a total whose count failed, never 0', function (): void {
    attachmentWithEntry(sampleEntry(['state' => 'Valid']));

    $html = (string) dashboardWidget('admin', '$GLOBALS["wpdb"]->suppress_errors(true); '.BREAK_TOTAL);

    expect(summaryTotal($html))->toBe('—')
        ->and(visibleText($html))->not->toContain('No JPEG, PNG and WebP images yet.')
        ->and(summaryLines($html)['valid']['count'] ?? null)->toBe(1)
        ->and(strtolower(visibleText($html)))->toContain('could not be read');
})->group('SPEC-033');

it('AC6: escapes its labels and links, links only into the admin, and warns about nothing', function (): void {
    summaryLibrary();
    $hostile = <<<'PHP'
        add_filter('gettext', function (string $translation, string $text, string $domain): string {
            return $domain === 'tracefern-image-check-for-c2pa' && $text === 'Does not verify' ? '<img src=x onerror=alert(1)>Does not verify' : $translation;
        }, 10, 3);
        add_filter('admin_url', fn (string $url): string => $url.'"onmouseover="alert(1)', 10, 1);
        PHP;

    $html = (string) dashboardWidget('admin', $hostile);
    $plain = (string) dashboardWidget('admin');
    $classes = preg_match_all('/class="([^"]*)"/', $plain, $m) > 0 ? implode(' ', $m[1]) : '';

    expect(array_diff(activeMarkup($html), ['<a>', 'href=']))->toBe([])
        ->and($html)->not->toContain('<img')
        ->and(visibleText($html))->toContain('<img src=x onerror=alert(1)>Does not verify')
        ->and(hrefs($plain))->not->toBeEmpty()
        ->and(array_filter(hrefs($plain), fn (string $h): bool => ! str_starts_with($h, 'http://localhost:8892/wp-admin/')))->toBe([])
        ->and($classes)->not->toMatch('/(^|\s)(notice\S*|error|warning|alert)(\s|$)/');
})->group('SPEC-033');
