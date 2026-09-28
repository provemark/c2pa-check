<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use wpdb;

/**
 * The small keys the Media Library sorts and filters on (SPEC-007): the
 * stored entry is one serialized array that MySQL cannot order by. Derived
 * only from the entry, the way the display reads it.
 */
final class Index
{
    public const string STATE_KEY = '_tracefern_state';

    public const string AI_KEY = '_tracefern_ai';

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
     * WordPress's database object.
     */
    public static function db(): wpdb
    {
        global $wpdb;

        return $wpdb instanceof wpdb ? $wpdb : throw new RuntimeException('WordPress database not loaded');
    }
}
