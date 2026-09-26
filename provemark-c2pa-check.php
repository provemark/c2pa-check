<?php
/**
 * Plugin Name:       Provemark C2PA Check
 * Description:       Verifies the Content Credentials (C2PA) of uploaded images and shows the result in the Media Library.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Maurice van Loon
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       provemark-c2pa-check
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$provemark_c2pa_autoload = __DIR__ . '/vendor/autoload.php';

if (! is_readable($provemark_c2pa_autoload)) {
    // Fail closed without breaking the site: no verifier, no checks, one notice.
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Provemark C2PA Check cannot run: its bundled libraries are missing. Reinstall the plugin.', 'provemark-c2pa-check')
            . '</p></div>';
    });

    return;
}

require_once $provemark_c2pa_autoload;
unset($provemark_c2pa_autoload);
