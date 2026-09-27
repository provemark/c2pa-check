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
 * (notes/m1-original-file.md) and stores the result as post meta.
 */
final class UploadHook
{
    public const string META_KEY = '_provemark_c2pa_result';

    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set while the last check had to run without trust settings (SPEC-004 AC6). */
    public const string TRUST_FAILED_OPTION = 'provemark_c2pa_trust_failed';

    /** The WP-Cron event that checks one attachment (SPEC-013). */
    public const string EVENT = 'provemark_c2pa_check';

    /** When the check of an attachment was scheduled (Unix time), until it runs. */
    public const string PENDING_KEY = '_provemark_c2pa_pending';

    /** After this many seconds a pending marker counts as not checked: its event was lost. */
    public const int PENDING_FOR = 3600;

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
        add_action(self::EVENT, $this->runScheduled(...), 10, 2);
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
            // original (SPEC-013 amendment 1), so the path travels with it.
            $path = get_attached_file($attachmentId);
            $relative = is_string($path) ? _wp_relative_upload_path($path) : '';

            update_post_meta($attachmentId, self::PENDING_KEY, time());
            wp_schedule_single_event(time(), self::EVENT, [$attachmentId, $relative]);
        } catch (Throwable) {
            // The upload always proceeds.
        }
    }

    /**
     * The scheduled check, in a request of its own (WP-Cron): with the
     * admin memory limit, on the original file, not on `-scaled`, which
     * WordPress has usually made by now. An ID that is no longer a JPEG,
     * PNG or WebP attachment is skipped.
     */
    public function runScheduled(mixed $attachmentId, mixed $originalPath = null): void
    {
        try {
            $id = is_int($attachmentId) || (is_string($attachmentId) && ctype_digit($attachmentId)) ? (int) $attachmentId : 0;
            if ($id <= 0 || get_post_type($id) !== 'attachment' || ! in_array(get_post_mime_type($id), self::MIME_TYPES, true)) {
                return;
            }

            // wp-cron.php runs every due check in one request. When less than
            // half of the host's time limit is left, this check waits for the
            // next cron request rather than die halfway (SPEC-013 amendment 1).
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0 && timer_float() > $limit / 2) {
                wp_schedule_single_event(time(), self::EVENT, [$id, $originalPath]);

                return;
            }

            wp_raise_memory_limit('admin');

            $path = self::uploadedFile($originalPath);
            if ($path === null) {
                $original = wp_get_original_image_path($id);
                $path = is_string($original) && $original !== '' ? $original : get_attached_file($id);
            }
            $this->checkAndStore($id, is_string($path) ? $path : '');
        } catch (Throwable) {
            // Whatever was stored last stays.
        }
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
        self::unscheduleCheck($attachmentId);
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

        update_post_meta($attachmentId, self::META_KEY, wp_slash($entry));
        Index::write($attachmentId, $entry);

        return $entry;
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

    /**
     * Removes a scheduled check of an attachment that a check just made
     * unnecessary (SPEC-013 amendment 1).
     */
    private static function unscheduleCheck(int $attachmentId): void
    {
        foreach (_get_cron_array() as $timestamp => $hooks) {
            foreach ($hooks[self::EVENT] ?? [] as $event) {
                if (($event['args'][0] ?? null) === $attachmentId) {
                    wp_unschedule_event($timestamp, self::EVENT, array_values($event['args']));
                }
            }
        }
    }
}
