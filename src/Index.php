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
        $wpdb = self::db();
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT r.post_id FROM %i r LEFT JOIN %i s ON s.post_id = r.post_id AND s.meta_key = %s WHERE r.meta_key = %s AND s.meta_id IS NULL ORDER BY r.post_id ASC LIMIT %d',
            $wpdb->postmeta,
            $wpdb->postmeta,
            self::STATE_KEY,
            UploadHook::META_KEY,
            $limit,
        ));

        $count = 0;
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                self::write((int) $id, get_post_meta((int) $id, UploadHook::META_KEY, true));
                $count++;
            }
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
