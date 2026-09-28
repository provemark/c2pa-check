<?php

/**
 * Removes Tracefern Image Check for C2PA's data when the plugin is deleted (SPEC-005):
 * the stored result of every checked image, its index (SPEC-007) and the
 * plugin's options. The images themselves stay. Deactivating does not run
 * this file. On multisite, every site of the network (SPEC-011): this file
 * runs once, in the main site's context.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$tracefern_clean = static function (): void {
    foreach (['_tracefern_result', '_tracefern_state', '_tracefern_ai', '_tracefern_pending', '_tracefern_source'] as $key) {
        delete_post_meta_by_key($key);
    }
    // Background checks not yet run (SPEC-013).
    wp_unschedule_hook('tracefern_check');
    foreach (['tracefern_digicert', 'tracefern_custom_trust', 'tracefern_trust_failed', 'tracefern_index_done'] as $option) {
        delete_option($option);
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $tracefern_site) {
        switch_to_blog((int) $tracefern_site);
        $tracefern_clean();
        restore_current_blog();
    }
} else {
    $tracefern_clean();
}
