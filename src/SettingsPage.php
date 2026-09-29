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
 * Settings → Tracefern (SPEC-004): the bundled list's date, the DigiCert
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
        // The "Existing images" buttons (SPEC-031).
        add_action('admin_post_tracefern_existing_start', $this->startExistingRun(...));
        add_action('admin_post_tracefern_existing_stop', $this->stopExistingRun(...));
        add_action('wp_ajax_tracefern_existing_progress', $this->existingRunProgress(...));
    }

    /**
     * The progress of a run, for the section's script (SPEC-031 amendment
     * 2): `done`, `total`, whether it has `finished`, and the progress line
     * as the page shows it. Administrators only, with the section's nonce.
     */
    public function existingRunProgress(): void
    {
        if (! current_user_can('manage_options') || check_ajax_referer('tracefern_existing_progress', 'nonce', false) === false) {
            wp_send_json_error(null, 403);
        }
        $run = ExistingImages::progress();
        if ($run === null || $run['finished'] !== null) {
            wp_send_json_success(['done' => $run['done'] ?? 0, 'total' => $run['total'] ?? 0, 'finished' => true, 'text' => '']);
        }
        wp_send_json_success(['done' => $run['done'], 'total' => $run['total'], 'finished' => false, 'text' => self::progressLine($run['done'], $run['total'])]);
    }

    /** "Checking existing images: N of M done. …", translated, numbers localised. */
    private static function progressLine(int $done, int $total): string
    {
        /* translators: 1: images done, 2: images in the run */
        return sprintf(__('Checking existing images: %1$s of %2$s done. The checks run in the background.', 'tracefern-image-check-for-c2pa'), number_format_i18n($done), number_format_i18n($total));
    }

    /**
     * "Check images that were never checked" / "Check all images again":
     * stores a run and schedules the queue, then back to the settings page.
     * Administrators only, with the section's nonce.
     */
    public function startExistingRun(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'tracefern-image-check-for-c2pa'), '', ['response' => 403]);
        }
        check_admin_referer('tracefern_existing_images');
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        ExistingImages::start($mode);
        wp_safe_redirect(admin_url('options-general.php?page='.self::SLUG));
        exit;
    }

    /** "Stop": deletes the run. Administrators only, with the section's nonce. */
    public function stopExistingRun(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'tracefern-image-check-for-c2pa'), '', ['response' => 403]);
        }
        check_admin_referer('tracefern_existing_images');
        ExistingImages::stop();
        wp_safe_redirect(admin_url('options-general.php?page='.self::SLUG));
        exit;
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
            __('Tracefern Image Check for C2PA', 'tracefern-image-check-for-c2pa'),
            __('Tracefern', 'tracefern-image-check-for-c2pa'),
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

        echo '<div class="wrap"><h1>'.esc_html__('Tracefern Image Check for C2PA', 'tracefern-image-check-for-c2pa').'</h1>';

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
            .esc_html__('Settings apply to new uploads. To apply them to the images already in the Media Library, use "Check all images again" below.', 'tracefern-image-check-for-c2pa')
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
        echo '</form>';
        $this->existingImages();
        echo '</div>';
    }

    /**
     * The "Existing images" section (SPEC-031): how many images there are
     * and how many were never checked; while a run goes, its progress and a
     * Stop button; otherwise the two buttons.
     */
    private function existingImages(): void
    {
        $run = ExistingImages::progress();

        echo '<h2>'.esc_html__('Existing images', 'tracefern-image-check-for-c2pa').'</h2><p>'
            /* translators: 1: number of JPEG, PNG and WebP images, 2: how many of them were never checked */
            .sprintf(esc_html__('%1$s JPEG, PNG and WebP images; %2$s never checked.', 'tracefern-image-check-for-c2pa'), esc_html(number_format_i18n(ExistingImages::count('all'))), esc_html(number_format_i18n(ExistingImages::count('unchecked'))))
            .'</p>';

        if ($run !== null && $run['finished'] === null) {
            echo '<p id="tracefern-existing-progress" aria-live="polite">'.esc_html(self::progressLine($run['done'], $run['total']))
                .'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="tracefern_existing_stop" />';
            wp_nonce_field('tracefern_existing_images');
            echo '<button type="submit" class="button">'.esc_html__('Stop', 'tracefern-image-check-for-c2pa').'</button></form>';
            // The line updates itself every 3 seconds; when the run has
            // finished the page reloads once (SPEC-031 amendment 2).
            wp_print_inline_script_tag(
                '(function () {'
                .'var url = '.wp_json_encode(admin_url('admin-ajax.php')).', nonce = '.wp_json_encode(wp_create_nonce('tracefern_existing_progress')).';'
                .'var line = document.getElementById("tracefern-existing-progress");'
                .'function tick() {'
                .'var body = new FormData(); body.append("action", "tracefern_existing_progress"); body.append("nonce", nonce);'
                .'fetch(url, { method: "POST", body: body, credentials: "same-origin" })'
                .'.then(function (r) { return r.json(); })'
                .'.then(function (r) { if (!r || !r.success) { return; } if (r.data.finished) { window.location.reload(); return; } line.textContent = r.data.text; setTimeout(tick, 3000); })'
                .'.catch(function () { setTimeout(tick, 3000); });'
                .'}'
                .'setTimeout(tick, 3000);'
                .'})();'
            );

            return;
        }

        if ($run !== null) {
            $format = get_option('date_format');
            $time = get_option('time_format');
            echo '<p>'
                /* translators: 1: images checked, 2: date and time */
                .sprintf(esc_html__('Finished: %1$s images checked, %2$s.', 'tracefern-image-check-for-c2pa'), esc_html(number_format_i18n($run['done'])), esc_html(wp_date((is_string($format) ? $format : 'Y-m-d').' '.(is_string($time) ? $time : 'H:i'), (int) $run['finished']) ?: ''))
                .'</p>';
        }

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="tracefern_existing_start" />';
        wp_nonce_field('tracefern_existing_images');
        echo '<p><button type="submit" name="mode" value="unchecked" class="button button-primary">'.esc_html__('Check images that were never checked', 'tracefern-image-check-for-c2pa').'</button> '
            .'<button type="submit" name="mode" value="all" class="button">'.esc_html__('Check all images again', 'tracefern-image-check-for-c2pa').'</button></p>'
            .'<p class="description">'.esc_html__('The checks run in the background, a few images at a time; new uploads go first. "Check all images again" applies the current trust settings and verifier to every image.', 'tracefern-image-check-for-c2pa').'</p></form>';
    }

    /**
     * Only on the screens where images are uploaded, shown or the trust
     * settings are changed (guideline 11, SPEC-022).
     */
    public function trustNotice(): void
    {
        $screen = get_current_screen();
        $screens = ['upload', 'media', 'attachment', 'settings_page_'.self::SLUG];
        if ($screen === null || ! in_array($screen->id, $screens, true)) {
            return;
        }
        if (! get_option(UploadHook::TRUST_FAILED_OPTION) || ! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            .esc_html__('Tracefern Image Check for C2PA: the last image was checked without trust settings, because they could not be read. See Settings → Tracefern.', 'tracefern-image-check-for-c2pa')
            .'</p></div>';
    }
}
