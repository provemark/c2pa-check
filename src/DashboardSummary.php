<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_Query;

/**
 * A summary of the Media Library on the dashboard (SPEC-033): per filter of
 * SPEC-007, how many images that filter lists, each a link to that list.
 * It counts what is stored and adds no verdict of its own.
 */
final class DashboardSummary
{
    public const string WIDGET = 'tracefern_summary';

    /** The transient that holds the last counts that were all made (amendment 1). */
    public const string CACHE = 'tracefern_summary';

    /** The longest a count can lag a change the plugin cannot see. */
    private const int CACHE_FOR = 3600;

    /** The states the grouped query counts: stored state, lower case => filter key. */
    private const array STATES = ['trusted' => 'trusted', 'valid' => 'valid', 'invalid' => 'invalid', 'error' => 'error', 'none' => 'none'];

    /** The meta keys the counts read; a change to one empties the cache. */
    private const array KEYS = [Index::STATE_KEY, Index::AI_KEY, UploadHook::PENDING_KEY];

    public function register(): void
    {
        add_action('wp_dashboard_setup', $this->addWidget(...));
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, self::onMeta(...), 10, 3);
        }
        add_action('clean_post_cache', self::onPost(...), 10, 2);
    }

    /** Empties the cache: the next view counts again. */
    public static function forget(): void
    {
        delete_transient(self::CACHE);
    }

    public static function onMeta(mixed $metaIds, mixed $objectId, mixed $metaKey): void
    {
        if (in_array($metaKey, self::KEYS, true)) {
            self::forget();
        }
    }

    public static function onPost(mixed $postId, mixed $post): void
    {
        if ($post instanceof WP_Post && $post->post_type === 'attachment') {
            self::forget();
        }
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
        $statuses = self::statuses();
        $variant = implode(',', $statuses);
        $queued = UploadHook::queueIsScheduled();
        $now = time();

        $cache = get_transient(self::CACHE);
        $valid = is_array($cache) && ($cache['queued'] ?? null) === $queued && is_int($cache['until'] ?? null) && $now < $cache['until'] && is_array($cache['counts'] ?? null);
        if ($valid && is_array($cache['counts'][$variant] ?? null)) {
            $cached = self::cached($cache['counts'][$variant]);
            if ($cached !== null) {
                return $cached;
            }
        }

        $states = self::stateCounts($statuses);
        $counts = ['total' => self::count($statuses, ['post_mime_type' => UploadHook::MIME_TYPES], null)];
        foreach (array_keys(MediaSort::options()) as $key) {
            $counts[$key] = array_key_exists($key, $states) ? $states[$key] : self::count($statuses, [], $key);
        }

        // Only counts that were all made are kept (amendment 1).
        $until = self::staleAt($queued, $now);
        if (! in_array(null, $counts, true) && $until !== null) {
            $keep = $valid && $cache['until'] === $until ? $cache['counts'] : [];
            set_transient(self::CACHE, ['queued' => $queued, 'until' => $until, 'counts' => [$variant => $counts] + $keep], self::CACHE_FOR);
        }

        return $counts;
    }

    /**
     * Cached counts, when every one is an int under a known key.
     *
     * @param  array<mixed>  $stored
     * @return array<string, int>|null
     */
    private static function cached(array $stored): ?array
    {
        $counts = [];
        foreach (['total', ...array_keys(MediaSort::options())] as $key) {
            if (! is_int($stored[$key] ?? null)) {
                return null;
            }
            $counts[$key] = $stored[$key];
        }

        return $counts;
    }

    /**
     * When cached counts stop matching the filters by time alone: the
     * earliest moment a fresh pending marker becomes "Not checked" (the
     * oldest fresh marker's time plus PENDING_FOR), and at the latest
     * CACHE_FOR from now. While the queue is scheduled every marker is
     * pending (SPEC-017), so only the queue's state can change that, and
     * the cache stores it. Null when the markers could not be read.
     */
    private static function staleAt(bool $queued, int $now): ?int
    {
        $latest = $now + self::CACHE_FOR;
        if ($queued) {
            return $latest;
        }

        $wpdb = Index::db();
        // Once per dashboard view that counts; the meta_key index finds the few markers.
        $oldest = $wpdb->get_var($wpdb->prepare('SELECT MIN(CAST(meta_value AS SIGNED)) FROM %i WHERE meta_key = %s AND CAST(meta_value AS SIGNED) > %d', $wpdb->postmeta, UploadHook::PENDING_KEY, $now - UploadHook::PENDING_FOR)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- computes when the transient goes stale
        if ($wpdb->last_error !== '') {
            return null;
        }

        return is_numeric($oldest) ? min($latest, (int) $oldest + UploadHook::PENDING_FOR) : $latest;
    }

    /**
     * The five state counts in one query (amendment 1): attachments in the
     * list's post statuses, per stored state. A state with no row is 0;
     * `unreadable` is read and has no line. All five null on a database
     * error.
     *
     * @param  list<string>  $statuses
     * @return array<string, int|null>
     */
    private static function stateCounts(array $statuses): array
    {
        $wpdb = Index::db();
        // One query per dashboard view that counts, instead of five scans of the meta table.
        $rows = count($statuses) > 1
            ? $wpdb->get_results($wpdb->prepare("SELECT m.meta_value AS state, COUNT(*) AS n FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status IN (%s, %s) GROUP BY m.meta_value", $wpdb->postmeta, $wpdb->posts, Index::STATE_KEY, $statuses[0], $statuses[1]), ARRAY_A) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- kept in the transient
            : $wpdb->get_results($wpdb->prepare("SELECT m.meta_value AS state, COUNT(*) AS n FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status = %s GROUP BY m.meta_value", $wpdb->postmeta, $wpdb->posts, Index::STATE_KEY, $statuses[0]), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- kept in the transient

        if ($wpdb->last_error !== '' || ! is_array($rows)) {
            return array_fill_keys(array_values(self::STATES), null);
        }

        $counts = array_fill_keys(array_values(self::STATES), 0);
        foreach ($rows as $row) {
            // The filter compares meta values with the column's collation,
            // which ignores case; so does this.
            $state = is_string($row['state'] ?? null) ? strtolower($row['state']) : '';
            $key = self::STATES[$state] ?? null;
            if ($key !== null && is_numeric($row['n'] ?? null)) {
                $counts[$key] += (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * The post statuses the Media Library list shows this user
     * (wp_edit_attachments_query_vars()).
     *
     * @return list<string>
     */
    private static function statuses(): array
    {
        $type = get_post_type_object('attachment');
        $readPrivate = $type?->cap->read_private_posts ?? null;

        return is_string($readPrivate) && current_user_can($readPrivate) ? ['inherit', 'private'] : ['inherit'];
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
     * @param  list<string>  $statuses
     * @param  array<string, mixed>  $vars
     */
    private static function count(array $statuses, array $vars, ?string $filter): ?int
    {
        $wpdb = Index::db();

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
