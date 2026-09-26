<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

use RuntimeException;
use wpdb;

/**
 * The small keys the Media Library sorts and filters on (SPEC-007): the
 * stored entry is one serialized array that MySQL cannot order by. Derived
 * only from the entry, the way the display reads it.
 */
final class Index
{
    public const string STATE_KEY = '_provemark_c2pa_state';

    public const string AI_KEY = '_provemark_c2pa_ai';

    public const string DONE_OPTION = 'provemark_c2pa_index_done';

    public static function write(int $attachmentId, mixed $entry): void
    {
        $class = Display::classify($entry);
        if ($class === null) {
            delete_post_meta($attachmentId, self::STATE_KEY);
            delete_post_meta($attachmentId, self::AI_KEY);

            return;
        }

        [$state, $ai] = $class;
        update_post_meta($attachmentId, self::STATE_KEY, $state);
        if ($ai) {
            update_post_meta($attachmentId, self::AI_KEY, '1');
        } else {
            delete_post_meta($attachmentId, self::AI_KEY);
        }
    }

    /**
     * Indexes up to $limit entries that have no index yet, lowest ID first,
     * and returns how many it indexed.
     */
    public static function backfill(int $limit = 500): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            // One-time, in batches of $limit: the only way to find entries
            // without an index.
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                ['key' => UploadHook::META_KEY, 'compare' => 'EXISTS'],
                ['key' => self::STATE_KEY, 'compare' => 'NOT EXISTS'],
            ],
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $count = 0;
        foreach ($ids as $id) {
            self::write($id, get_post_meta($id, UploadHook::META_KEY, true));
            $count++;
        }

        return $count;
    }

    /**
     * WordPress's database object.
     */
    public static function db(): wpdb
    {
        global $wpdb;

        return $wpdb instanceof wpdb ? $wpdb : throw new RuntimeException('WordPress database not loaded');
    }
}
