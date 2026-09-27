<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

if (! defined('ABSPATH')) {
    exit;
}

use WP_Query;

/**
 * Sorting and filtering the Media Library list by Content Credentials
 * (SPEC-007): a sortable column, a select above the list, the query change
 * behind both.
 */
final class MediaSort
{
    public const string PARAM = 'provemark_c2pa';

    /** Ascending sort order of the index states; no index sorts after all of them. */
    private const array ORDER = ['Trusted', 'Valid', 'Invalid', 'error', 'unreadable', 'none'];

    public function register(): void
    {
        add_filter('manage_upload_sortable_columns', $this->sortable(...));
        add_action('restrict_manage_posts', $this->renderSelect(...), 10, 2);
        add_action('pre_get_posts', $this->onPreGetPosts(...));
        add_filter('posts_clauses', self::orderClauses(...), 10, 2);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    public function sortable(array $columns): array
    {
        $columns[MediaScreens::COLUMN] = self::PARAM;

        return $columns;
    }

    /**
     * The filter options: request value => label (SPEC-002's headlines).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'trusted' => __('Verified: trusted signer', 'provemark-c2pa-check'),
            'valid' => __('Intact: signer not trusted', 'provemark-c2pa-check'),
            'invalid' => __('Does not verify', 'provemark-c2pa-check'),
            'ai' => __('AI-generated (signed)', 'provemark-c2pa-check'),
            'error' => __('Could not be checked', 'provemark-c2pa-check'),
            'none' => __('No Content Credentials', 'provemark-c2pa-check'),
            'pending' => __('Check pending', 'provemark-c2pa-check'),
            'unchecked' => __('Not checked', 'provemark-c2pa-check'),
        ];
    }

    public function renderSelect(mixed $postType = '', mixed $which = ''): void
    {
        if ($postType !== 'attachment') {
            return;
        }

        $chosen = self::chosen(self::request()[self::PARAM]);

        echo '<label for="provemark-c2pa-filter" class="screen-reader-text">'.esc_html__('Filter by Content Credentials', 'provemark-c2pa-check').'</label>';
        echo '<select name="'.esc_attr(self::PARAM).'" id="provemark-c2pa-filter">';
        echo '<option value="">'.esc_html__('All Content Credentials', 'provemark-c2pa-check').'</option>';
        foreach (self::options() as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($chosen, $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }

    public function onPreGetPosts(WP_Query $query): void
    {
        global $pagenow;

        if (! is_admin() || ! $query->is_main_query() || $pagenow !== 'upload.php') {
            return;
        }

        self::apply($query, self::request());
    }

    /**
     * Changes a Media Library query for $request: the filter, when it is one
     * of the options, and the sort, when the column is chosen. Anything else
     * in $request is ignored; nothing from it reaches SQL.
     *
     * @param  array<mixed>  $request
     */
    public static function apply(WP_Query $query, array $request): void
    {
        $filter = self::chosen($request[self::PARAM] ?? null);
        if ($filter !== null) {
            // A pending marker younger than this is "Check pending"; an older
            // one was lost and counts as not checked (SPEC-013).
            // While the queue is scheduled every marker counts (SPEC-017).
            $cutoff = UploadHook::queueIsScheduled() ? 0 : time() - UploadHook::PENDING_FOR;
            $clause = match ($filter) {
                'trusted' => ['key' => Index::STATE_KEY, 'value' => 'Trusted'],
                'valid' => ['key' => Index::STATE_KEY, 'value' => 'Valid'],
                'invalid' => ['key' => Index::STATE_KEY, 'value' => 'Invalid'],
                'error' => ['key' => Index::STATE_KEY, 'value' => 'error'],
                'none' => ['key' => Index::STATE_KEY, 'value' => 'none'],
                'ai' => ['key' => Index::AI_KEY, 'value' => '1'],
                'pending' => [
                    'relation' => 'AND',
                    ['key' => Index::STATE_KEY, 'compare' => 'NOT EXISTS'],
                    ['key' => UploadHook::PENDING_KEY, 'value' => $cutoff, 'compare' => '>', 'type' => 'NUMERIC'],
                ],
                default => [
                    'relation' => 'AND',
                    ['key' => Index::STATE_KEY, 'compare' => 'NOT EXISTS'],
                    [
                        'relation' => 'OR',
                        ['key' => UploadHook::PENDING_KEY, 'compare' => 'NOT EXISTS'],
                        ['key' => UploadHook::PENDING_KEY, 'value' => $cutoff, 'compare' => '<=', 'type' => 'NUMERIC'],
                    ],
                ],
            };
            // Only the formats the plugin checks can be pending or not
            // checked (SPEC-015); the others have no Content Credentials
            // state at all.
            if ($filter === 'pending' || $filter === 'unchecked') {
                $query->set('post_mime_type', UploadHook::MIME_TYPES);
            }
            $existing = $query->get('meta_query');
            $query->set('meta_query', is_array($existing) && $existing !== [] ? ['relation' => 'AND', $existing, $clause] : [$clause]);
        }

        if (($request['orderby'] ?? null) === self::PARAM) {
            $order = is_string($request['order'] ?? null) && strtolower($request['order']) === 'desc' ? 'DESC' : 'ASC';
            $query->set('provemark_c2pa_order', $order);
        }
    }

    /**
     * The ORDER BY for a query that asked for the column: the states in
     * ORDER, no index last, newest first within a state (date, then ID).
     * Built from constants only.
     *
     * @param  array<string, string>  $clauses
     * @return array<string, string>
     */
    public static function orderClauses(array $clauses, WP_Query $query): array
    {
        $order = $query->get('provemark_c2pa_order');
        if ($order !== 'ASC' && $order !== 'DESC') {
            return $clauses;
        }

        $wpdb = Index::db();
        $cases = '';
        foreach (self::ORDER as $rank => $state) {
            $cases .= $wpdb->prepare(' WHEN %s THEN %d', $state, $rank);
        }

        $clauses['join'] = ($clauses['join'] ?? '').$wpdb->prepare(' LEFT JOIN %i AS provemark_c2pa_sort ON provemark_c2pa_sort.post_id = %i.ID AND provemark_c2pa_sort.meta_key = %s', $wpdb->postmeta, $wpdb->posts, Index::STATE_KEY);
        // post_date alone ties for uploads in the same second; ID settles it
        // (a higher ID is newer), so the order is the same on every request.
        $clauses['orderby'] = 'CASE provemark_c2pa_sort.meta_value'.$cases.' ELSE '.count(self::ORDER).' END '.$order.", {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";

        return $clauses;
    }

    /**
     * The three request values this class reads, unslashed and sanitized;
     * apply() and chosen() still check them against fixed lists.
     *
     * @return array{provemark_c2pa: ?string, orderby: ?string, order: ?string}
     */
    private static function request(): array
    {
        $values = [];
        foreach ([self::PARAM, 'orderby', 'order'] as $key) {
            // A read-only list filter and sort, as on WordPress's own list
            // screens, so no nonce. sanitize_text_field keeps the case:
            // `Trusted` must stay unknown (AC4).
            $values[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter, checked against fixed lists
        }

        return ['provemark_c2pa' => $values[self::PARAM], 'orderby' => $values['orderby'], 'order' => $values['order']];
    }

    /**
     * The request value when it is one of the options, else null.
     */
    private static function chosen(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::options()) ? $value : null;
    }
}
