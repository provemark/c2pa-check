<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use cli\progress\Bar;
use WP_CLI;
use WP_Query;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\make_progress_bar;

/**
 * Checks existing images again (SPEC-008).
 */
final class RecheckCommand
{
    private const array STATES = ['Trusted', 'Valid', 'Invalid', 'error', 'none', 'unreadable'];

    private const int PAGE = 500;

    public function __construct(private readonly UploadHook $hook) {}

    /**
     * Checks the Content Credentials of existing images again, with the
     * trust settings in force now.
     *
     * Choose the images with exactly one of: attachment IDs, --all,
     * --unchecked or --state.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Attachment IDs to check.
     *
     * [--all]
     * : Every JPEG, PNG and WebP attachment.
     *
     * [--unchecked]
     * : The JPEG, PNG and WebP attachments that were never checked.
     *
     * [--state=<states>]
     * : Attachments whose last result is one of these, comma-separated:
     * Trusted, Valid, Invalid, error, none, unreadable.
     *
     * [--dry-run]
     * : List what would be checked, and change nothing.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp tracefern check 123 456
     *     wp tracefern check --state=Invalid,error
     *     wp tracefern check --all --dry-run
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assocArgs
     */
    public function check(array $args, array $assocArgs): void
    {
        wp_raise_memory_limit('admin');
        $ids = $this->select($args, $assocArgs);
        $dryRun = (bool) ($assocArgs['dry-run'] ?? false);
        $format = ($assocArgs['format'] ?? 'table') === 'json' ? 'json' : 'table';

        $rows = [];
        $progress = $format === 'table' && ! $dryRun && count($ids) > 20 ? make_progress_bar('Checking', count($ids)) : null;
        foreach ($ids as $id) {
            $path = UploadHook::fileToCheck($id);
            $before = self::state($id);

            if ($dryRun) {
                $rows[] = ['id' => $id, 'file' => basename($path), 'state' => $before];

                continue;
            }

            $entry = $this->hook->checkAndStore($id, $path);
            $class = Display::classify($entry);
            $rows[] = ['id' => $id, 'file' => basename($path), 'before' => $before, 'after' => $class === null ? null : $class[0]];
            if ($progress instanceof Bar) {
                $progress->tick();
            }
        }
        if ($progress instanceof Bar) {
            $progress->finish();
        }

        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($rows));

            return;
        }

        format_items('table', $rows, $dryRun ? ['id', 'file', 'state'] : ['id', 'file', 'before', 'after']);
        if (! $dryRun) {
            WP_CLI::success(self::summary($rows));
        }
    }

    /**
     * The attachment IDs of exactly one selection; anything else stops
     * with an error before anything is checked.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assocArgs
     * @return list<int>
     */
    private function select(array $args, array $assocArgs): array
    {
        $chosen = ($args !== [] ? 1 : 0) + (isset($assocArgs['all']) ? 1 : 0) + (isset($assocArgs['unchecked']) ? 1 : 0) + (isset($assocArgs['state']) ? 1 : 0);
        if ($chosen !== 1) {
            WP_CLI::error('Choose the images with exactly one of: attachment IDs, --all, --unchecked or --state=<states>.');
        }

        if ($args !== []) {
            return $this->explicit($args);
        }

        $query = ['post_mime_type' => UploadHook::MIME_TYPES];
        if (isset($assocArgs['unchecked'])) {
            // On demand in WP-CLI, in pages of 500: no page request waits on it.
            $query['meta_query'] = [['key' => UploadHook::META_KEY, 'compare' => 'NOT EXISTS']]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WP-CLI on demand, in pages of 500
        }
        if (isset($assocArgs['state'])) {
            $states = array_map('trim', explode(',', is_string($assocArgs['state']) ? $assocArgs['state'] : ''));
            $unknown = array_diff($states, self::STATES);
            if ($unknown !== [] || $states === ['']) {
                WP_CLI::error('Unknown state: '.implode(', ', $unknown).'. Use --all, --unchecked or --state= with '.implode(', ', self::STATES).'.');
            }
            unset($query['post_mime_type']);
            $query['meta_query'] = [['key' => Index::STATE_KEY, 'value' => $states, 'compare' => 'IN']]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WP-CLI on demand, in pages of 500
        }

        return $this->all($query);
    }

    /**
     * The given IDs that are JPEG, PNG or WebP attachments; the others are
     * skipped with a warning.
     *
     * @param  list<string>  $args
     * @return list<int>
     */
    private function explicit(array $args): array
    {
        $ids = [];
        foreach ($args as $arg) {
            $id = (int) $arg;
            if ($id > 0 && get_post_type($id) === 'attachment' && in_array(get_post_mime_type($id), UploadHook::MIME_TYPES, true)) {
                $ids[] = $id;
            } else {
                WP_CLI::warning("Skipped {$arg}: not a JPEG, PNG or WebP attachment.");
            }
        }

        return $ids;
    }

    /**
     * Every attachment ID for $query, lowest first, fetched in pages;
     * collected before checking, so the checks cannot move the pages.
     *
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function all(array $query): array
    {
        $ids = [];
        for ($page = 1; ; $page++) {
            $found = (new WP_Query($query + [
                'post_type' => 'attachment',
                'post_status' => 'any',
                'orderby' => 'ID',
                'order' => 'ASC',
                'posts_per_page' => self::PAGE,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]))->posts;
            $batch = array_values(array_filter($found ?? [], is_int(...)));
            $ids = [...$ids, ...$batch];
            if (count($batch) < self::PAGE) {
                return $ids;
            }
        }
    }

    private static function state(int $id): ?string
    {
        $state = get_post_meta($id, Index::STATE_KEY, true);

        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * "Checked 3: 1 Trusted, 1 Valid, 1 none".
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function summary(array $rows): string
    {
        $counts = [];
        foreach ($rows as $row) {
            $after = is_string($row['after'] ?? null) ? $row['after'] : 'not stored';
            $counts[$after] = ($counts[$after] ?? 0) + 1;
        }
        $parts = [];
        foreach ([...self::STATES, 'not stored'] as $state) {
            if (isset($counts[$state])) {
                $parts[] = $counts[$state].' '.$state;
            }
        }

        return 'Checked '.count($rows).($parts === [] ? '' : ': '.implode(', ', $parts));
    }
}
