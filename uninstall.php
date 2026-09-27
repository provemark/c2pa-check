<?php

/**
 * Removes Provemark C2PA Check's data when the plugin is deleted (SPEC-005):
 * the stored result of every checked image, its index (SPEC-007) and the
 * plugin's options. The images themselves stay. Deactivating does not run
 * this file. On multisite, every site of the network (SPEC-011): this file
 * runs once, in the main site's context.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$provemark_c2pa_clean = static function (): void {
    foreach (['_provemark_c2pa_result', '_provemark_c2pa_state', '_provemark_c2pa_ai', '_provemark_c2pa_pending'] as $key) {
        delete_post_meta_by_key($key);
    }
    // Background checks not yet run (SPEC-013).
    wp_unschedule_hook('provemark_c2pa_check');
    foreach (['provemark_c2pa_digicert', 'provemark_c2pa_custom_trust', 'provemark_c2pa_trust_failed', 'provemark_c2pa_index_done'] as $option) {
        delete_option($option);
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $provemark_c2pa_site) {
        switch_to_blog((int) $provemark_c2pa_site);
        $provemark_c2pa_clean();
        restore_current_blog();
    }
} else {
    $provemark_c2pa_clean();
}
