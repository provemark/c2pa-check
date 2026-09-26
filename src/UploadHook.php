<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

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

    public function __construct(private readonly Checker $checker) {}

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

            update_post_meta($attachmentId, self::META_KEY, $this->checker->interrupted());

            $path = get_attached_file($attachmentId);
            $entry = $this->checker->check(is_string($path) ? $path : '');

            update_post_meta($attachmentId, self::META_KEY, $entry);
        } catch (Throwable) {
            // The upload always proceeds; whatever was stored last stays.
        }
    }
}
