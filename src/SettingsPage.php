<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Throwable;

/**
 * Settings → C2PA Check (SPEC-004): the bundled list's date, the DigiCert
 * option, and custom trust settings that replace the bundled lists. Also
 * the notice shown while the last check ran without trust settings.
 */
final class SettingsPage
{
    public const string SLUG = 'tracefern-image-check-for-c2pa';

    public const string GROUP = 'tracefern_check';

    public const string DIGICERT_OPTION = 'tracefern_digicert';

    public const string CUSTOM_OPTION = 'tracefern_custom_trust';

    public function register(): void
    {
        add_action('admin_menu', $this->addPage(...));
        // The Settings API on admin_init, as options.php runs it before it
        // saves: nothing of this is read on the front end (SPEC-012).
        add_action('admin_init', $this->registerSettings(...));
        add_action('admin_notices', $this->trustNotice(...));
    }

    /**
     * The trust configuration the saved options stand for.
     */
    public static function trustConfig(): TrustConfig
    {
        $custom = get_option(self::CUSTOM_OPTION, '');

        return new TrustConfig(
            dirname(__DIR__).'/trust',
            is_string($custom) ? $custom : '',
            (bool) get_option(self::DIGICERT_OPTION, true),
        );
    }

    public function addPage(): void
    {
        add_options_page(
            __('C2PA Check', 'tracefern-image-check-for-c2pa'),
            __('C2PA Check', 'tracefern-image-check-for-c2pa'),
            'manage_options',
            self::SLUG,
            $this->render(...),
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::GROUP, self::DIGICERT_OPTION, [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        // No registered default: with one, update_option() takes a value equal
        // to it for a missing option and re-adds the option with autoload
        // `auto`, i.e. loaded on every request (WordPress 7.1.2, SPEC-012).
        // Every get_option() here passes its own default.
        register_setting(self::GROUP, self::CUSTOM_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeCustom'],
        ]);

        // Up to about 70 KB: never loaded on every request.
        if (get_option(self::CUSTOM_OPTION, null) === null) {
            add_option(self::CUSTOM_OPTION, '', '', false);
        }
    }

    /**
     * Custom settings are kept only when the verifier accepts them;
     * otherwise the previous value stays and the reason is shown.
     */
    public static function sanitizeCustom(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        try {
            TrustSettings::fromJson($value);

            return $value;
        } catch (Throwable $e) {
            add_settings_error(
                self::CUSTOM_OPTION,
                'invalid',
                /* translators: %s: the verifier's reason */
                sprintf(esc_html__('These are not trust settings; the previous settings are kept. %s', 'tracefern-image-check-for-c2pa'), esc_html($e->getMessage())),
            );
            $previous = get_option(self::CUSTOM_OPTION, '');

            return is_string($previous) ? $previous : '';
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $custom = get_option(self::CUSTOM_OPTION, '');
        $digiCert = (bool) get_option(self::DIGICERT_OPTION, true);

        echo '<div class="wrap"><h1>'.esc_html__('C2PA Check', 'tracefern-image-check-for-c2pa').'</h1>';

        if (TrustConfig::isStale(new DateTimeImmutable)) {
            echo '<div class="notice notice-warning inline"><p>'
                /* translators: %s: date of the bundled trust list */
                .sprintf(esc_html__('The bundled C2PA trust list is from %s, older than six months. A plugin update brings a newer copy.', 'tracefern-image-check-for-c2pa'), esc_html(TrustConfig::LIST_DATE))
                .'</p></div>';
        }

        echo '<p>'
            /* translators: 1: list date, 2: commit */
            .sprintf(esc_html__('Bundled C2PA trust list: %1$s (commit %2$s of c2pa-org/conformance-public, CC BY 4.0).', 'tracefern-image-check-for-c2pa'), esc_html(TrustConfig::LIST_DATE), esc_html(TrustConfig::LIST_COMMIT))
            .'</p><p>'
            .esc_html__('Settings apply to new uploads only; images already in the Media Library keep the result of their check.', 'tracefern-image-check-for-c2pa')
            .'</p>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tr><th scope="row">'
            .esc_html__('DigiCert timestamps', 'tracefern-image-check-for-c2pa')
            .'</th><td><label><input type="checkbox" name="'.esc_attr(self::DIGICERT_OPTION).'" value="1"'.checked($digiCert, true, false).' /> '
            .esc_html__('Accept the time DigiCert\'s timestamp authorities put on a signature (Adobe Firefly, Microsoft Bing and Amazon Titan use them).', 'tracefern-image-check-for-c2pa')
            .'</label></td></tr><tr><th scope="row"><label for="'.esc_attr(self::CUSTOM_OPTION).'">'
            .esc_html__('Custom trust settings', 'tracefern-image-check-for-c2pa')
            .'</label></th><td><textarea id="'.esc_attr(self::CUSTOM_OPTION).'" name="'.esc_attr(self::CUSTOM_OPTION).'" rows="12" class="large-text code">'
            .esc_textarea(is_string($custom) ? $custom : '')
            .'</textarea><p class="description">'
            .esc_html__('Trust settings JSON in the format c2patool and c2pa-verifier read. When set, they replace the bundled lists and the DigiCert option entirely. Leave empty to use the bundled lists.', 'tracefern-image-check-for-c2pa')
            .'</p></td></tr></table>';
        submit_button();
        echo '</form></div>';
    }

    public function trustNotice(): void
    {
        if (! get_option(UploadHook::TRUST_FAILED_OPTION) || ! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            .esc_html__('Tracefern Image Check for C2PA: the last image was checked without trust settings, because they could not be read. See Settings → C2PA Check.', 'tracefern-image-check-for-c2pa')
            .'</p></div>';
    }
}
