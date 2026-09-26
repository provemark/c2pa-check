<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

use Closure;
use Throwable;

/**
 * Verifies an uploaded image in add_attachment, where get_attached_file()
 * is still the uploaded original on every route (notes/m1-original-file.md),
 * and stores the result as post meta.
 */
final class UploadHook
{
    public const string META_KEY = '_provemark_c2pa_result';

    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Set while the last check had to run without trust settings (SPEC-004 AC6). */
    public const string TRUST_FAILED_OPTION = 'provemark_c2pa_trust_failed';

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
    }

    public function onAddAttachment(int $attachmentId): void
    {
        try {
            if (! in_array(get_post_mime_type($attachmentId), self::MIME_TYPES, true)) {
                return;
            }

            // update_post_meta() unslashes its value; wp_slash() keeps
            // backslashes in text from the file (SPEC-001 amendment 2). The
            // provisional entry goes first, before anything that could stop
            // the request, reading the trust lists included.
            $provisional = $this->checker->interrupted();
            update_post_meta($attachmentId, self::META_KEY, wp_slash($provisional));
            Index::write($attachmentId, $provisional);

            [$settings, $trust] = ($this->trustConfig)()->build();
            if ($trust === 'none') {
                update_option(self::TRUST_FAILED_OPTION, true, false);
            } else {
                delete_option(self::TRUST_FAILED_OPTION);
            }

            $path = get_attached_file($attachmentId);
            $entry = $this->checker->check(is_string($path) ? $path : '', $settings, $trust);

            update_post_meta($attachmentId, self::META_KEY, wp_slash($entry));
            Index::write($attachmentId, $entry);
        } catch (Throwable) {
            // The upload always proceeds; whatever was stored last stays.
        }
    }
}
