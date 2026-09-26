<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

use WP_Post;

/**
 * Shows the stored result: a list-mode column in the Media Library and a
 * row in the attachment details (Edit Media and the media modals), as
 * measured in notes/m2-where-to-show.md. WordPress inserts that row
 * unescaped; Display escapes everything.
 */
final class MediaScreens
{
    public const string COLUMN = 'provemark_c2pa';

    public function register(): void
    {
        add_filter('manage_media_columns', $this->addColumn(...));
        add_action('manage_media_custom_column', $this->renderColumn(...), 10, 2);
        add_filter('attachment_fields_to_edit', $this->addDetails(...), 10, 2);
        add_action('admin_enqueue_scripts', $this->enqueueStyle(...));
    }

    /**
     * The badges and rows (SPEC-002 amendment 1). Small, so on every admin
     * screen: the media modal can open anywhere.
     */
    public function enqueueStyle(): void
    {
        wp_enqueue_style(
            'provemark-c2pa-check',
            plugins_url('assets/admin.css', dirname(__DIR__).'/provemark-c2pa-check.php'),
            ['dashicons'],
            (string) filemtime(dirname(__DIR__).'/assets/admin.css'),
        );
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        $columns[self::COLUMN] = esc_html__('Content Credentials', 'provemark-c2pa-check');

        return $columns;
    }

    public function renderColumn(string $column, int $attachmentId): void
    {
        if ($column === self::COLUMN) {
            echo wp_kses_post(Display::headline(self::entryOf($attachmentId)));
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function addDetails(array $fields, WP_Post $post): array
    {
        $fields[self::COLUMN] = [
            'label' => esc_html__('Content Credentials', 'provemark-c2pa-check'),
            'input' => 'html',
            'html' => wp_kses_post(Display::details(self::entryOf($post->ID))),
        ];

        return $fields;
    }

    /**
     * The stored value, or null when the attachment has none.
     */
    private static function entryOf(int $attachmentId): mixed
    {
        return metadata_exists('post', $attachmentId, UploadHook::META_KEY)
            ? get_post_meta($attachmentId, UploadHook::META_KEY, true)
            : null;
    }
}
