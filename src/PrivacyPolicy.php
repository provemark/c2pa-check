<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

/**
 * Suggested text for the site's privacy policy (SPEC-010), shown in
 * WordPress's Privacy Policy Guide. WordPress accepts it only from
 * admin_init in the admin; anywhere else this adds nothing.
 */
final class PrivacyPolicy
{
    public const string PLUGIN_NAME = 'Provemark C2PA Check';

    public function register(): void
    {
        add_action('admin_init', [$this, 'addText']);
    }

    public function addText(): void
    {
        if (! is_admin() || ! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(self::PLUGIN_NAME, wp_kses_post(self::text()));
    }

    /**
     * The guidance for the site owner (left out when the text is copied)
     * and the suggested text itself.
     */
    public static function text(): string
    {
        return '<p class="privacy-policy-tutorial">'
            .esc_html__('Provemark C2PA Check reads the Content Credentials (C2PA) of uploaded images and stores the result with each image. It sends nothing to anyone.', 'provemark-c2pa-check')
            .'</p><p>'
            .esc_html__('When an image is uploaded, this site checks the Content Credentials in the file and stores the result with the image: whether the credentials verify, the name of the signer and of its certificate\'s issuer as the file states them, the signing time, and whether the file says it was made by generative AI. The signer\'s name can be a person\'s name. This information is stored in this site\'s database, shown to users who can manage media, and not sent to anyone. It is deleted when the image is deleted, or when the plugin is removed.', 'provemark-c2pa-check')
            .'</p>';
    }
}
