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
use Provemark\C2paCheck\Checker;
use Provemark\C2paCheck\MediaScreens;
use Provemark\C2paCheck\MediaSort;
use Provemark\C2paCheck\PrivacyPolicy;
use Provemark\C2paCheck\RecheckCommand;
use Provemark\C2paCheck\SettingsPage;
use Provemark\C2paCheck\UploadHook;

if (! defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    // The bundled verifier needs PHP 8.3: without it, stay out of the way
    // rather than let Composer's platform check stop every request.
    add_action('admin_notices', static function (): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            .esc_html__('Provemark C2PA Check needs PHP 8.3 or later and is not running.', 'provemark-c2pa-check')
            .'</p></div>';
    });

    return;
}

if (! is_readable(__DIR__.'/vendor/autoload.php')) {
    // Fail closed without breaking the site: no verifier, no checks, one notice.
    add_action('admin_notices', static function (): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            .esc_html__('Provemark C2PA Check cannot run: its bundled libraries are missing. Reinstall the plugin.', 'provemark-c2pa-check')
            .'</p></div>';
    });

    return;
}

require_once __DIR__.'/vendor/autoload.php';

// Not first-class callable syntax: this file must parse before PHP 8.1,
// so the version check above can run (SPEC-018).
register_deactivation_hook(__FILE__, [UploadHook::class, 'deactivate']);

// In a function, so the plugin adds no global variables.
(static function (): void {
    $hook = new UploadHook(new Checker);
    $hook->register();
    (new MediaScreens)->register();
    (new MediaSort)->register();
    (new SettingsPage)->register();
    (new PrivacyPolicy)->register();

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('provemark-c2pa', new RecheckCommand($hook));
    }
})();
