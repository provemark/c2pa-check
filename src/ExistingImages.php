<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A run over existing images (SPEC-031), started from the settings page:
 * the images that were never checked, or all of them again. One option
 * holds the run; the check queue (SPEC-017) takes its images, after the
 * pending uploads, one at a time by ascending ID. Each image is claimed
 * (the cursor moves past it) before it is checked, so one whose check dies
 * does not stop the run.
 */
final class ExistingImages
{
    public const string OPTION = 'tracefern_existing_images';

    public const array MODES = ['unchecked', 'all'];

    /**
     * Starts a run and schedules the queue. An unknown mode starts nothing.
     */
    public static function start(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            return;
        }
        update_option(self::OPTION, ['mode' => $mode, 'after' => 0, 'done' => 0, 'total' => self::count($mode), 'started' => time(), 'finished' => null], false);
        UploadHook::scheduleQueue();
    }

    public static function stop(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * The run, or null when there is none.
     *
     * @return array{mode: string, after: int, done: int, total: int, started: int, finished: int|null}|null
     */
    public static function progress(): ?array
    {
        $run = get_option(self::OPTION, null);
        if (! is_array($run) || ! in_array($run['mode'] ?? null, self::MODES, true)) {
            return null;
        }
        $int = static fn (mixed $v): int => is_int($v) ? $v : 0;

        return [
            'mode' => $run['mode'],
            'after' => $int($run['after'] ?? 0),
            'done' => $int($run['done'] ?? 0),
            'total' => $int($run['total'] ?? 0),
            'started' => $int($run['started'] ?? 0),
            'finished' => is_int($run['finished'] ?? null) ? $run['finished'] : null,
        ];
    }

    /** Whether a run has images left to take. */
    public static function isActive(): bool
    {
        $run = self::progress();

        return $run !== null && $run['finished'] === null;
    }

    /**
     * The next image of the run, claimed: the cursor moves past it before
     * it is checked. Null, and the run marked finished, when none is left.
     */
    public static function claim(): ?int
    {
        $run = self::progress();
        if ($run === null || $run['finished'] !== null) {
            return null;
        }
        $next = self::next($run['mode'], $run['after']);
        if ($next === null) {
            $run['finished'] = time();
            update_option(self::OPTION, $run, false);

            return null;
        }
        $run['after'] = $next;
        $run['done']++;
        update_option(self::OPTION, $run, false);

        return $next;
    }

    /** How many JPEG, PNG and WebP images a mode covers. */
    public static function count(string $mode): int
    {
        $wpdb = Index::db();
        [$jpeg, $png, $webp] = UploadHook::MIME_TYPES;

        // Once per button press or settings page view: a count, not a loop.
        $count = $mode === 'unchecked'
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s) AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key IN (%s, %s))", $wpdb->posts, $jpeg, $png, $webp, $wpdb->postmeta, UploadHook::META_KEY, UploadHook::PENDING_KEY)) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- once per press or page view
            : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s)", $wpdb->posts, $jpeg, $png, $webp)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- once per press or page view

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The first image ID after $after that a mode covers, or null. For
     * 'unchecked': without an entry and not waiting for its upload's own
     * check.
     */
    private static function next(string $mode, int $after): ?int
    {
        $wpdb = Index::db();
        [$jpeg, $png, $webp] = UploadHook::MIME_TYPES;

        // In the background queue, one row at a time; an index scan on ID.
        $id = $mode === 'unchecked'
            ? $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM %i p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s) AND p.ID > %d AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key IN (%s, %s)) ORDER BY p.ID ASC LIMIT 1", $wpdb->posts, $jpeg, $png, $webp, $after, $wpdb->postmeta, UploadHook::META_KEY, UploadHook::PENDING_KEY)) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one row per image, in the background
            : $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM %i p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s) AND p.ID > %d ORDER BY p.ID ASC LIMIT 1", $wpdb->posts, $jpeg, $png, $webp, $after)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one row per image, in the background

        return is_numeric($id) ? (int) $id : null;
    }
}
