<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use WP_Query;

/**
 * A summary of the Media Library on the dashboard (SPEC-033): per filter of
 * SPEC-007, how many images that filter lists, each a link to that list.
 * It counts what is stored and adds no verdict of its own.
 */
final class DashboardSummary
{
    public const string WIDGET = 'tracefern_summary';

    public function register(): void
    {
        add_action('wp_dashboard_setup', $this->addWidget(...));
    }

    public function addWidget(): void
    {
        if (! current_user_can('upload_files')) {
            return;
        }

        wp_add_dashboard_widget(self::WIDGET, __('Content Credentials', 'tracefern-image-check-for-c2pa'), $this->render(...));
    }

    /**
     * 'total', then one count per key of MediaSort::options(); null when
     * the query failed, never 0 for a count that was not made.
     *
     * @return array<string, int|null>
     */
    public static function counts(): array
    {
        $counts = ['total' => self::count(['post_mime_type' => UploadHook::MIME_TYPES], null)];
        foreach (array_keys(MediaSort::options()) as $key) {
            $counts[$key] = self::count([], $key);
        }

        return $counts;
    }

    public function render(): void
    {
        $counts = self::counts();

        if ($counts['total'] === 0) {
            echo '<p>'.esc_html__('No JPEG, PNG or WebP images yet.', 'tracefern-image-check-for-c2pa').'</p>';

            return;
        }

        echo '<p>'.esc_html(self::totalLine($counts['total'])).'</p><ul>';
        foreach (MediaSort::options() as $key => $label) {
            $count = $counts[$key] ?? null;
            echo '<li>'.esc_html($label).': ';
            if ($count === null) {
                echo '—';
            } elseif ($count === 0) {
                echo esc_html(number_format_i18n(0));
            } else {
                echo '<a href="'.esc_url(admin_url('upload.php?mode=list&'.MediaSort::PARAM.'='.$key)).'">'.esc_html(number_format_i18n($count)).'</a>';
            }
            echo '</li>';
        }
        echo '</ul>';

        if (in_array(null, $counts, true)) {
            echo '<p>'.esc_html__('Some counts could not be read.', 'tracefern-image-check-for-c2pa').'</p>';
        }

        echo '<p class="description">'.esc_html__('The lines need not add up to the total: an image marked AI-generated is also in the line of its state, and a result that cannot be read is in none.', 'tracefern-image-check-for-c2pa').'</p>';

        if (current_user_can('manage_options') && ($counts['unchecked'] ?? 0) > 0) {
            echo '<p><a href="'.esc_url(admin_url('options-general.php?page='.SettingsPage::SLUG)).'">'.esc_html__('Check them', 'tracefern-image-check-for-c2pa').'</a></p>';
        }
    }

    private static function totalLine(?int $total): string
    {
        if ($total === null) {
            /* translators: shown instead of the number of images when it could not be counted. */
            return __('— JPEG, PNG and WebP images', 'tracefern-image-check-for-c2pa');
        }

        /* translators: %s: the number of JPEG, PNG and WebP images in the Media Library. */
        return sprintf(_n('%s JPEG, PNG and WebP image', '%s JPEG, PNG and WebP images', $total, 'tracefern-image-check-for-c2pa'), number_format_i18n($total));
    }

    /**
     * How many attachments the Media Library list shows this user with
     * $vars and, when $filter is a key, that SPEC-007 filter: the list's
     * post statuses (wp_edit_attachments_query_vars()), the filter's own
     * query change (MediaSort::apply()). Null when the database gave an
     * error: a failed query leaves found_posts at 0 and runs no FOUND_ROWS(),
     * so its error is still the last one.
     *
     * @param  array<string, mixed>  $vars
     */
    private static function count(array $vars, ?string $filter): ?int
    {
        $wpdb = Index::db();
        $type = get_post_type_object('attachment');
        $statuses = ['inherit'];
        $readPrivate = $type?->cap->read_private_posts ?? null;
        if (is_string($readPrivate) && current_user_can($readPrivate)) {
            $statuses[] = 'private';
        }

        $query = new WP_Query;
        $apply = static function (WP_Query $q) use ($query, $filter): void {
            if ($q === $query && $filter !== null) {
                MediaSort::apply($q, [MediaSort::PARAM => $filter]);
            }
        };
        add_action('pre_get_posts', $apply);
        try {
            $query->query($vars + [
                'post_type' => 'attachment',
                'post_status' => $statuses,
                'fields' => 'ids',
                'posts_per_page' => 1,
                'ignore_sticky_posts' => true,
            ]);
        } finally {
            remove_action('pre_get_posts', $apply);
        }

        return $wpdb->last_error === '' ? $query->found_posts : null;
    }
}
