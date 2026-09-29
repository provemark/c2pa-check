<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use Throwable;
use WP_REST_Request;

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
    public const string META_KEY = '_tracefern_result';

    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set while the last check had to run without trust settings (SPEC-004 AC6). */
    public const string TRUST_FAILED_OPTION = 'tracefern_trust_failed';

    /** The WP-Cron event of the check queue, without arguments (SPEC-017). */
    public const string EVENT = 'tracefern_check';

    /** The most images one run of the queue checks. */
    public const int BATCH = 20;

    /** When the safety run follows a run that may die, in seconds. */
    public const int SAFETY_DELAY = 60;

    /** When the check of an attachment was scheduled (Unix time), until it runs. */
    public const string PENDING_KEY = '_tracefern_pending';

    /** After this many seconds a pending marker counts as not checked: its event was lost. */
    public const int PENDING_FOR = 3600;

    /** The file to check: the original, relative to the uploads folder (SPEC-014). */
    public const string SOURCE_KEY = '_tracefern_source';

    /** The copies the block editor's browser made of an upload, relative to the uploads folder (SPEC-029). */
    public const string COPIES_KEY = '_tracefern_browser_copies';

    /** The upload's fingerprint: its path relative to the uploads folder, SHA-256 and size (SPEC-028). */
    public const string UPLOAD_KEY = '_tracefern_upload';

    /** @var array<string, array{sha256: string, size: int}> fingerprints taken in this request, by relative path */
    private static array $fingerprints = [];

    /** The block editor's media request WordPress now serves: 'sideload', 'finalize' or null (SPEC-029). */
    private static ?string $browserRequest = null;

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
        add_filter('wp_handle_upload', self::fingerprint(...), PHP_INT_MIN);
        add_action('add_attachment', $this->onAddAttachment(...));
        add_filter('wp_update_attachment_metadata', $this->onMetadataUpdate(...), 10, 2);
        add_filter('rest_request_before_callbacks', self::noteBrowserRequest(...), 10, 3);
        add_filter('rest_request_after_callbacks', self::endBrowserRequest(...));
        add_filter('update_attached_file', self::onAttachedFileUpdate(...), 10, 2);
        add_action(self::EVENT, $this->runQueue(...));
    }

    /**
     * Takes the SHA-256 and size of an uploaded JPEG, PNG or WebP as it
     * arrives, before any other plugin on this filter changes it (SPEC-028):
     * kept for this request, and stored when the upload becomes an
     * attachment. A file that cannot be read gets none. Returns the upload
     * unchanged.
     */
    public static function fingerprint(mixed $upload): mixed
    {
        try {
            $file = is_array($upload) ? ($upload['file'] ?? null) : null;
            $type = is_array($upload) ? ($upload['type'] ?? null) : null;
            if (is_string($file) && $file !== '' && in_array($type, self::MIME_TYPES, true) && is_file($file) && is_readable($file)) {
                $sha256 = hash_file('sha256', $file);
                $size = filesize($file);
                if (is_string($sha256) && $size !== false) {
                    self::$fingerprints[self::relativeToUploads($file)] = ['sha256' => $sha256, 'size' => $size];
                }
            }
        } catch (Throwable) {
            // The upload always proceeds.
        }

        return $upload;
    }

    /**
     * Notes whether WordPress now serves `POST /wp/v2/media/<id>/sideload`
     * or `/finalize`: with client-side media processing, the requests in
     * which the block editor stores the rotated and scaled copies its browser
     * made of an upload, and records them (SPEC-029). Returns the response
     * unchanged.
     */
    public static function noteBrowserRequest(mixed $response, mixed $handler, mixed $request): mixed
    {
        self::$browserRequest = $request instanceof WP_REST_Request
            && $request->get_method() === 'POST'
            && preg_match('#^/wp/v2/media/\d+/(sideload|finalize)$#', $request->get_route(), $match) === 1
            ? $match[1] : null;

        return $response;
    }

    /** The REST request is served. Returns the response unchanged. */
    public static function endBrowserRequest(mixed $response): mixed
    {
        self::$browserRequest = null;

        return $response;
    }

    /**
     * A sideload that makes its copy the attached file (as the browser's
     * `original` and `scaled` copies do, with or without a finalize after
     * them, notes/exif-rotation.md) records that copy (SPEC-029 amendment
     * 2). Returns the file unchanged.
     */
    public static function onAttachedFileUpdate(mixed $file, mixed $attachmentId): mixed
    {
        try {
            $id = is_int($attachmentId) ? $attachmentId : 0;
            $kept = $id > 0 ? get_post_meta($id, self::SOURCE_KEY, true) : '';
            if (self::$browserRequest === 'sideload' && is_string($file) && $file !== '' && is_string($kept) && $kept !== '') {
                self::recordCopies($id, $kept, [$file]);
            }
        } catch (Throwable) {
            // The sideload always proceeds.
        }

        return $file;
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
            $relative = is_string($path) ? self::relativeToUploads($path) : '';
            update_post_meta($attachmentId, self::SOURCE_KEY, $relative);

            // The fingerprint taken as this file arrived (SPEC-028).
            if (isset(self::$fingerprints[$relative])) {
                update_post_meta($attachmentId, self::UPLOAD_KEY, wp_slash(['file' => $relative] + self::$fingerprints[$relative]));
                unset(self::$fingerprints[$relative]);
            }

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
            $checked = 0;
            foreach (self::pending(self::BATCH) as $id) {
                // At least one per run; after that, stop at half the limit.
                if ($checked > 0 && $limit > 0 && timer_float() > $limit / 2) {
                    break;
                }
                // Claimed by removing its marker: a run that overlaps this
                // one finds it gone and skips it (SPEC-018).
                if (! delete_post_meta($id, self::PENDING_KEY)) {
                    continue;
                }
                $checked++;
                $this->runScheduled($id);
            }

            // Then the images of a run over existing ones (SPEC-031), each
            // claimed before its check, with what is left of the batch.
            while ($checked < self::BATCH && ($checked === 0 || $limit <= 0 || timer_float() <= $limit / 2)) {
                $id = ExistingImages::claim();
                if ($id === null) {
                    break;
                }
                $checked++;
                $this->runExisting($id);
            }

            wp_clear_scheduled_hook(self::EVENT);
            if (self::pending(1) !== [] || ExistingImages::isActive()) {
                wp_schedule_single_event(time(), self::EVENT);
            }
        } catch (Throwable) {
            // The safety run stays.
        }
    }

    /**
     * Checks an image of a run over existing ones (SPEC-031) as
     * `wp tracefern check` does (SPEC-008): on the file the verdict
     * describes now. An ID that is no longer a JPEG, PNG or WebP attachment
     * is skipped.
     */
    private function runExisting(int $id): void
    {
        try {
            if (get_post_type($id) !== 'attachment' || ! in_array(get_post_mime_type($id), self::MIME_TYPES, true)) {
                return;
            }
            wp_raise_memory_limit('admin');
            $this->checkAndStore($id, self::fileToCheck($id));
        } catch (Throwable) {
            // Whatever was stored last stays.
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
            'meta_key' => self::PENDING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- in the background, at most BATCH rows
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
            // The finalize request names the browser's copies of the upload,
            // which was kept at add_attachment: they are recorded, and the
            // kept path stays (SPEC-029).
            $kept = get_post_meta($id, self::SOURCE_KEY, true);
            if (self::$browserRequest === 'finalize' && is_string($kept) && $kept !== '') {
                self::recordCopies($id, $kept, [$attached, is_array($data) ? ($data['original_image'] ?? null) : null]);

                return $data;
            }
            $relative = self::relativeToUploads(self::shownFile($attached, is_array($data) ? ($data['original_image'] ?? null) : null));
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
     * The file to check for an attachment: the kept one when it is a file
     * inside the uploads folder, else the one visitors see now.
     */
    public static function fileOf(int $attachmentId): string
    {
        $kept = self::uploadedFile(get_post_meta($attachmentId, self::SOURCE_KEY, true));

        return $kept ?? self::fileToCheck($attachmentId);
    }

    /**
     * The file a verdict describes, from the attachment as it is now
     * (SPEC-018, decision A): the file visitors see, except WordPress's own
     * copy made at upload, whose original holds the Content Credentials.
     */
    public static function fileToCheck(int $attachmentId): string
    {
        $attached = get_attached_file($attachmentId);
        if (! is_string($attached) || $attached === '') {
            return '';
        }
        $metadata = wp_get_attachment_metadata($attachmentId);
        $shown = self::shownFile($attached, is_array($metadata) ? ($metadata['original_image'] ?? null) : null);

        // A copy the browser made of the upload is not what a verdict
        // describes; the kept upload is (SPEC-029).
        $recorded = get_post_meta($attachmentId, self::COPIES_KEY, true);
        $copies = is_array($recorded) ? array_filter($recorded, is_string(...)) : [];
        if (array_intersect([self::relativeToUploads($attached), self::relativeToUploads($shown)], $copies) !== []) {
            $kept = self::uploadedFile(get_post_meta($attachmentId, self::SOURCE_KEY, true));
            if ($kept !== null) {
                return $kept;
            }
        }

        return $shown;
    }

    /**
     * Adds the files a finalize request names for an attachment, other than
     * its kept upload, to the attachment's recorded browser copies.
     *
     * @param  list<mixed>  $files  absolute paths, or names next to the first
     */
    private static function recordCopies(int $attachmentId, string $kept, array $files): void
    {
        $recorded = get_post_meta($attachmentId, self::COPIES_KEY, true);
        $copies = is_array($recorded) ? array_values(array_filter($recorded, is_string(...))) : [];
        $first = is_string($files[0] ?? null) ? $files[0] : '';
        foreach ($files as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }
            $path = str_contains($file, '/') ? $file : dirname($first).'/'.$file;
            $relative = self::relativeToUploads($path);
            if ($relative !== $kept && ! in_array($relative, $copies, true)) {
                $copies[] = $relative;
            }
        }
        update_post_meta($attachmentId, self::COPIES_KEY, $copies);
    }

    /**
     * The attached file, or `original_image` next to it when the attached
     * file is exactly the `-scaled` or `-rotated` copy WordPress made of it
     * at upload. After an edit the attached file is a new one
     * (`…-scaled-e<time>.jpg`), and that is what visitors see.
     */
    public static function shownFile(string $attached, mixed $originalImage): string
    {
        if (! is_string($originalImage) || $originalImage === '') {
            return $attached;
        }
        // Without the extension: a site may have WordPress save the copy in
        // another format (`name-scaled.webp` for `name.jpg`, SPEC-018
        // amendment 1).
        $name = pathinfo($originalImage, PATHINFO_FILENAME);
        $copies = [$name.'-scaled', $name.'-rotated'];

        return in_array(pathinfo($attached, PATHINFO_FILENAME), $copies, true) ? dirname($attached).'/'.$originalImage : $attached;
    }

    /**
     * A path relative to the uploads folder, as WordPress keeps
     * `_wp_attached_file`; the path itself when it lies outside. Compared
     * as given and with symlinks resolved, so a symlinked uploads folder
     * gives the same answer for either form (SPEC-018).
     */
    public static function relativeToUploads(string $path): string
    {
        $normal = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');
        $base = wp_get_upload_dir()['basedir'];
        $realPath = realpath($path);
        $realBase = realpath($base);
        $pairs = [[$path, $base]];
        if ($realPath !== false && $realBase !== false) {
            $pairs[] = [$realPath, $realBase];
        }
        foreach ($pairs as [$file, $folder]) {
            $prefix = $normal($folder).'/';
            $file = $normal($file);
            if (str_starts_with($file, $prefix)) {
                return substr($file, strlen($prefix));
            }
        }

        return $normal($path);
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
        $source = get_post_meta($attachmentId, self::SOURCE_KEY, true);
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

        // The file at the uploaded path is not the file that was uploaded
        // (SPEC-028): an optimizer or another plugin rewrote it.
        $upload = get_post_meta($attachmentId, self::UPLOAD_KEY, true);
        if (is_array($upload) && $relative !== null && ($upload['file'] ?? null) === $relative && is_string($upload['sha256'] ?? null)
            && is_file($path) && hash_file('sha256', $path) !== $upload['sha256']) {
            $entry['changed_after_upload'] = true;
        }

        // Deleted while it was being checked (SPEC-015): leave no rows behind.
        if (get_post_type($attachmentId) !== 'attachment') {
            foreach ([self::META_KEY, self::PENDING_KEY, self::SOURCE_KEY, Index::STATE_KEY, Index::AI_KEY] as $key) {
                delete_post_meta($attachmentId, $key);
            }

            return $entry;
        }

        // Changed while it was being checked (SPEC-018): the metadata filter
        // has already queued the file it changed to; keep nothing of this.
        if (get_post_meta($attachmentId, self::SOURCE_KEY, true) !== $source) {
            return $entry;
        }

        if ($relative !== null) {
            update_post_meta($attachmentId, self::SOURCE_KEY, $relative);
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
