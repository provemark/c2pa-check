<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The verdict for other plugins (SPEC-026): the `tracefern_verdict` filter.
 *
 *     $verdict = apply_filters( 'tracefern_verdict', null, $attachment_id );
 *
 * Returns the default unchanged for anything but an attachment, so a caller
 * needs no dependency on this plugin. The array is Display::verdict(), fed
 * exactly as the Media Library column is fed.
 */
final class Verdict
{
    public const string FILTER = 'tracefern_verdict';

    public function register(): void
    {
        add_filter(self::FILTER, $this->filter(...), 10, 2);
    }

    /**
     * The filter callback: the default unless $attachmentId is an attachment.
     */
    public function filter(mixed $default, mixed $attachmentId = null): mixed
    {
        if (! is_int($attachmentId) || $attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            return $default;
        }

        return self::forAttachment($attachmentId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAttachment(int $attachmentId): array
    {
        $entry = MediaScreens::entryOf($attachmentId);

        return Display::verdict(
            $entry,
            MediaScreens::pendingSince($attachmentId),
            time(),
            Display::changed($entry, MediaScreens::currentOriginal($attachmentId)),
        );
    }
}
