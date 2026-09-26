<?php

/**
 * Removes Provemark C2PA Check's data when the plugin is deleted (SPEC-005):
 * the stored result of every checked image and the plugin's options. The
 * images themselves stay. Deactivating does not run this file.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_post_meta_by_key('_provemark_c2pa_result');
delete_post_meta_by_key('_provemark_c2pa_state');
delete_post_meta_by_key('_provemark_c2pa_ai');
delete_option('provemark_c2pa_digicert');
delete_option('provemark_c2pa_custom_trust');
delete_option('provemark_c2pa_trust_failed');
delete_option('provemark_c2pa_index_done');
