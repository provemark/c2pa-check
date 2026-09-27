<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use Throwable;

/**
 * Checks an uploaded image in the background (SPEC-013): the upload only
 * marks it pending and schedules one WP-Cron event, so a check that dies
 * cannot break the upload; the event checks the original file
 * (notes/m1-original-file.md) and stores the result as post meta. When
 * WordPress changes the original (the image editor, "Restore original"),
 * it is checked again (SPEC-014).
 */
final class UploadHook
{
    public const string META_KEY = '_provemark_c2pa_result';

    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set while the last check had to run without trust settings (SPEC-004 AC6). */
    public const string TRUST_FAILED_OPTION = 'provemark_c2pa_trust_failed';

    /** The WP-Cron event of the check queue, without arguments (SPEC-017). */
    public const string EVENT = 'provemark_c2pa_check';

    /** The most images one run of the queue checks. */
    public const int BATCH = 20;

    /** When the safety run follows a run that may die, in seconds. */
    public const int SAFETY_DELAY = 60;

    /** When the check of an attachment was scheduled (Unix time), until it runs. */
    public const string PENDING_KEY = '_provemark_c2pa_pending';

    /** After this many seconds a pending marker counts as not checked: its event was lost. */
    public const int PENDING_FOR = 3600;

    /** The file to check: the original, relative to the uploads folder (SPEC-014). */
    public const string SOURCE_KEY = '_provemark_c2pa_source';

    /** @var Closure(): TrustConfig */
    private readonly Closure $trustConfig;

    /**
     * @param  (Closure(): TrustConfig)|null  $trustConfig  the trust settings for a check; defaults to the saved options
     */
    public function __construct(private readonly Checker $checker, ?Closure $trustConfig = null)
    {
        $this->trustConfig = $trustConfig ?? SettingsPage::trustConfig(...);
    }

    public function register(): void
    {
        add_action('add_attachment', $this->onAddAttachment(...));
        add_filter('wp_update_attachment_metadata', $this->onMetadataUpdate(...), 10, 2);
        add_action(self::EVENT, $this->runQueue(...));
    }

    /**
     * Marks a JPEG, PNG or WebP upload pending and schedules its check;
     * nothing is verified in the upload request.
     */
    public function onAddAttachment(int $attachmentId): void
    {
        try {
            if (! in_array(get_post_mime_type($attachmentId), self::MIME_TYPES, true)) {
                return;
            }

            // Here get_attached_file() is still the uploaded original on every
            // route (notes/m1-original-file.md); by the time the event runs it
            // may point at -scaled with the metadata not yet naming the
            // original (SPEC-013 amendment 1), so the path is kept now.
            $path = get_attached_file($attachmentId);
            update_post_meta($attachmentId, self::SOURCE_KEY, is_string($path) ? self::relativeToUploads($path) : '');

            update_post_meta($attachmentId, self::PENDING_KEY, time());
            self::scheduleQueue();
        } catch (Throwable) {
            // The upload always proceeds.
        }
    }

    /**
     * The check queue (SPEC-017), in a request of its own (WP-Cron): a safety
     * run is scheduled first, so a run that dies is followed a minute later;
     * then up to BATCH pending images are checked, oldest first, while more
     * than half of the host's time limit is left; at the end the queue is
     * scheduled again only when images are still pending.
     */
    public function runQueue(): void
    {
        try {
            wp_clear_scheduled_hook(self::EVENT);
            wp_schedule_single_event(time() + self::SAFETY_DELAY, self::EVENT);

            $limit = (int) ini_get('max_execution_time');
            foreach (self::pending(self::BATCH) as $id) {
                if ($limit > 0 && timer_float() > $limit / 2) {
                    break;
                }
                $this->runScheduled($id);
            }

            wp_clear_scheduled_hook(self::EVENT);
            if (self::pending(1) !== []) {
                wp_schedule_single_event(time(), self::EVENT);
            }
        } catch (Throwable) {
            // The safety run stays.
        }
    }

    /**
     * Schedules the check queue now, unless it is due already. A later run
     * (the safety run after a run that died) is brought forward, so a new
     * upload is not kept waiting for it.
     */
    public static function scheduleQueue(): void
    {
        $next = wp_next_scheduled(self::EVENT);
        if ($next !== false && $next <= time()) {
            return;
        }
        if ($next !== false) {
            wp_clear_scheduled_hook(self::EVENT);
        }
        wp_schedule_single_event(time(), self::EVENT);
    }

    /**
     * Whether the check queue is scheduled: then every pending image is
     * still to be checked, however long it has waited (SPEC-017).
     */
    public static function queueIsScheduled(): bool
    {
        return wp_next_scheduled(self::EVENT) !== false;
    }

    /**
     * Checks one attachment now: with the admin memory limit, on the kept
     * original, not on `-scaled`. An ID that is no longer a JPEG, PNG or
     * WebP attachment is skipped.
     */
    public function runScheduled(mixed $attachmentId): void
    {
        try {
            $id = is_int($attachmentId) || (is_string($attachmentId) && ctype_digit($attachmentId)) ? (int) $attachmentId : 0;
            if ($id <= 0 || get_post_type($id) !== 'attachment' || ! in_array(get_post_mime_type($id), self::MIME_TYPES, true)) {
                return;
            }

            wp_raise_memory_limit('admin');
            $this->checkAndStore($id, self::fileOf($id));
        } catch (Throwable) {
            // Whatever was stored last stays.
        }
    }

    /**
     * Up to $limit pending JPEG, PNG and WebP attachments, oldest marker first.
     *
     * @return list<int>
     */
    private static function pending(int $limit): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'post_mime_type' => implode(',', self::MIME_TYPES),
            // In batches, in the background: the marker is how pending images are found.
            'meta_key' => self::PENDING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return array_values(array_filter($ids, is_int(...)));
    }

    /**
     * The filter every change WordPress makes to an attachment's file passes
     * (the image editor, "Restore original"), with the new file already
     * attached: when the original is now another file, that file is kept as
     * the one to check, and an image that was checked is checked again.
     * Returns the metadata unchanged.
     */
    public function onMetadataUpdate(mixed $data, mixed $attachmentId): mixed
    {
        try {
            $id = is_int($attachmentId) ? $attachmentId : 0;
            if ($id <= 0 || ! in_array(get_post_mime_type($id), self::MIME_TYPES, true)) {
                return $data;
            }

            $attached = get_attached_file($id);
            if (! is_string($attached) || $attached === '') {
                return $data;
            }
            $original = is_array($data) && is_string($data['original_image'] ?? null) && $data['original_image'] !== ''
                ? dirname($attached).'/'.$data['original_image']
                : $attached;
            $relative = self::relativeToUploads($original);
            if ($relative === get_post_meta($id, self::SOURCE_KEY, true)) {
                return $data;
            }

            update_post_meta($id, self::SOURCE_KEY, $relative);
            if (metadata_exists('post', $id, self::META_KEY)) {
                delete_post_meta($id, self::META_KEY);
                Index::write($id, null);
                update_post_meta($id, self::PENDING_KEY, time());
                self::scheduleQueue();
            }
        } catch (Throwable) {
            // The edit always proceeds.
        }

        return $data;
    }

    /**
     * The file to check for an attachment: the kept original when it is a
     * file inside the uploads folder, else the current original.
     */
    public static function fileOf(int $attachmentId): string
    {
        $kept = self::uploadedFile(get_post_meta($attachmentId, self::SOURCE_KEY, true));
        if ($kept !== null) {
            return $kept;
        }

        $original = wp_get_original_image_path($attachmentId);
        $path = is_string($original) && $original !== '' ? $original : get_attached_file($attachmentId);

        return is_string($path) ? $path : '';
    }

    /**
     * A path relative to the uploads folder, as WordPress keeps
     * `_wp_attached_file`; the path itself when it lies outside.
     */
    public static function relativeToUploads(string $path): string
    {
        $base = trailingslashit(wp_get_upload_dir()['basedir']);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Checks one file and stores the result for the attachment: the path an
     * upload takes and a re-check takes (SPEC-008), so both give the same
     * verdict. Returns the stored entry.
     *
     * @return array<string, mixed>
     */
    public function checkAndStore(int $attachmentId, string $path): array
    {
        // update_post_meta() unslashes its value; wp_slash() keeps
        // backslashes in text from the file (SPEC-001 amendment 2). The
        // provisional entry goes first, before anything that could stop
        // the request, reading the trust lists included.
        delete_post_meta($attachmentId, self::PENDING_KEY);
        $provisional = $this->checker->interrupted();
        update_post_meta($attachmentId, self::META_KEY, wp_slash($provisional));
        Index::write($attachmentId, $provisional);

        [$settings, $trust] = ($this->trustConfig)()->build();
        if ($trust === 'none') {
            update_option(self::TRUST_FAILED_OPTION, true, false);
        } else {
            delete_option(self::TRUST_FAILED_OPTION);
        }

        $entry = $this->checker->check($path, $settings, $trust);
        // Which file this verdict describes (SPEC-014): a later change to it
        // shows as "Changed since its check".
        $relative = $path === '' ? null : self::relativeToUploads($path);
        clearstatcache(true, $path);
        $size = $path !== '' && is_file($path) ? filesize($path) : false;
        $modified = $path !== '' && is_file($path) ? filemtime($path) : false;
        $entry += ['file' => $relative, 'size' => $size === false ? null : $size, 'modified' => $modified === false ? null : $modified];
        if ($relative !== null) {
            update_post_meta($attachmentId, self::SOURCE_KEY, $relative);
        }

        // Deleted while it was being checked (SPEC-015): leave no rows behind.
        if (get_post_type($attachmentId) !== 'attachment') {
            foreach ([self::META_KEY, self::PENDING_KEY, self::SOURCE_KEY, Index::STATE_KEY, Index::AI_KEY] as $key) {
                delete_post_meta($attachmentId, $key);
            }

            return $entry;
        }

        update_post_meta($attachmentId, self::META_KEY, wp_slash($entry));
        Index::write($attachmentId, $entry);

        return $entry;
    }

    /**
     * On deactivation (SPEC-015): no scheduled checks and no pending markers
     * left, on every site when the plugin was network-deactivated.
     */
    public static function deactivate(mixed $networkWide = false): void
    {
        $clean = static function (): void {
            wp_unschedule_hook(self::EVENT);
            delete_post_meta_by_key(self::PENDING_KEY);
        };

        if ($networkWide === true && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site) {
                switch_to_blog((int) $site);
                $clean();
                restore_current_blog();
            }
        } else {
            $clean();
        }
    }

    /**
     * The file a path relative to the uploads folder names, when it is a
     * file inside that folder; null otherwise.
     */
    private static function uploadedFile(mixed $relative): ?string
    {
        if (! is_string($relative) || $relative === '') {
            return null;
        }

        $base = realpath(wp_get_upload_dir()['basedir']);
        $file = $base === false ? false : realpath($base.'/'.$relative);

        return $base !== false && $file !== false && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }
}
