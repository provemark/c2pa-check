<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

/**
 * Turns a stored SPEC-001 entry into safe HTML: the only place wording and
 * escaping live (SPEC-002). It shows what was stored and never makes a
 * verdict of its own; anything that is not a SPEC-001 entry is "Result
 * unreadable".
 */
final class Display
{
    private const array STATES = ['Trusted', 'Valid', 'Invalid', 'none', 'error'];

    private const array REASONS = ['interrupted', 'unreadable', 'exception'];

    /**
     * C0 and C1 controls and the Unicode direction controls (UAX #9).
     */
    private const string UNSAFE_CHARACTERS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * The column cell: one headline.
     *
     * @param  mixed  $entry  the stored value, or null when there is none
     */
    public static function headline(mixed $entry): string
    {
        $read = self::read($entry);
        [$class, $headline] = self::headlineOf($read);
        $html = '<span class="provemark-c2pa provemark-c2pa--'.esc_attr($class).'">'.esc_html($headline).'</span>';

        return self::showsAiLabel($read)
            ? $html.'<br><span class="provemark-c2pa-ai">'.esc_html__('AI-generated (signed)', 'provemark-c2pa-check').'</span>'
            : $html;
    }

    /**
     * The attachment-details row: the headline and what the entry says.
     *
     * @param  mixed  $entry  the stored value, or null when there is none
     */
    public static function details(mixed $entry): string
    {
        $read = self::read($entry);
        [$class, $headline] = self::headlineOf($read);
        $lines = is_array($read) ? self::lines($read) : [];

        $html = '<div class="provemark-c2pa provemark-c2pa--'.esc_attr($class).'">';
        $html .= '<p><strong>'.esc_html($headline).'</strong></p>';
        if (self::showsAiLabel($read)) {
            $html .= '<p class="provemark-c2pa-ai">'.esc_html__('AI-generated (signed)', 'provemark-c2pa-check').'</p>';
        }
        foreach ($lines as $line) {
            $html .= '<p>'.$line.'</p>';
        }

        return $html.'</div>';
    }

    /**
     * Untrusted text from the file, safe to put in HTML: controls and
     * direction characters become U+FFFD, then esc_html.
     */
    public static function text(string $untrusted): string
    {
        $scrubbed = mb_scrub($untrusted, 'UTF-8');
        $visible = preg_replace(self::UNSAFE_CHARACTERS, "\u{FFFD}", $scrubbed);

        return esc_html($visible ?? '');
    }

    /**
     * A SPEC-001 entry, null for no entry, false for anything else.
     *
     * @return array{state: string, signer: array{issuer: ?string, common_name: string}|null, signed_at: ?string, codes: list<string>, ai: bool, remote_manifest_url: ?string, reason: ?string, verifier: ?string, checked_at: ?string}|false|null
     */
    private static function read(mixed $entry): array|false|null
    {
        if ($entry === null) {
            return null;
        }
        if (! is_array($entry) || ($entry['schema'] ?? null) !== Outcome::SCHEMA || ! in_array($entry['state'] ?? null, self::STATES, true)) {
            return false;
        }

        $codes = $entry['codes'] ?? [];
        if (! is_array($codes) || ! array_is_list($codes) || array_filter($codes, is_string(...)) !== $codes) {
            return false;
        }

        $signer = $entry['signer'] ?? null;
        if ($signer !== null) {
            $issuer = is_array($signer) ? ($signer['issuer'] ?? null) : false;
            $commonName = is_array($signer) ? ($signer['common_name'] ?? null) : null;
            if (! is_string($commonName) || ($issuer !== null && ! is_string($issuer))) {
                return false;
            }
            $signer = ['issuer' => $issuer, 'common_name' => $commonName];
        }

        $text = [];
        foreach (['signed_at', 'remote_manifest_url', 'verifier', 'checked_at'] as $key) {
            $value = $entry[$key] ?? null;
            if ($value !== null && ! is_string($value)) {
                return false;
            }
            $text[$key] = $value;
        }

        $ai = $entry['ai'] ?? false;
        if (! is_bool($ai)) {
            return false;
        }

        $reason = $entry['reason'] ?? null;
        if ($entry['state'] === 'error' ? ! in_array($reason, self::REASONS, true) : $reason !== null) {
            return false;
        }

        return [
            'state' => $entry['state'],
            'signer' => $signer,
            'signed_at' => $text['signed_at'],
            'codes' => $codes,
            'ai' => $ai,
            'remote_manifest_url' => $text['remote_manifest_url'],
            'reason' => $reason,
            'verifier' => $text['verifier'],
            'checked_at' => $text['checked_at'],
        ];
    }

    /**
     * The AI label is a signed statement worth showing only when the
     * manifest verifies (SPEC-003): never on Invalid, none or error.
     *
     * @param  array{state: string, ai: bool}|false|null  $read
     */
    private static function showsAiLabel(array|false|null $read): bool
    {
        return is_array($read) && $read['ai'] && in_array($read['state'], ['Trusted', 'Valid'], true);
    }

    /**
     * @param  array{state: string}|false|null  $read
     * @return array{string, string} CSS modifier and headline (both plain text)
     */
    private static function headlineOf(array|false|null $read): array
    {
        if ($read === null) {
            return ['unchecked', __('Not checked', 'provemark-c2pa-check')];
        }
        if ($read === false) {
            return ['unreadable', __('Result unreadable', 'provemark-c2pa-check')];
        }

        return match ($read['state']) {
            'Trusted' => ['trusted', __('Verified: trusted signer', 'provemark-c2pa-check')],
            'Valid' => ['valid', __('Intact: signer not trusted', 'provemark-c2pa-check')],
            'Invalid' => ['invalid', __('Does not verify', 'provemark-c2pa-check')],
            'none' => ['none', __('No Content Credentials', 'provemark-c2pa-check')],
            default => ['error', __('Could not be checked', 'provemark-c2pa-check')],
        };
    }

    /**
     * The detail lines, each already safe HTML.
     *
     * @param  array{state: string, signer: array{issuer: ?string, common_name: string}|null, signed_at: ?string, codes: list<string>, ai: bool, remote_manifest_url: ?string, reason: ?string, verifier: ?string, checked_at: ?string}  $entry
     * @return list<string>
     */
    private static function lines(array $entry): array
    {
        $lines = [];

        if ($entry['state'] === 'Invalid' && $entry['codes'] !== []) {
            /* translators: %s: comma-separated C2PA status codes */
            $lines[] = sprintf(esc_html__('Codes: %s', 'provemark-c2pa-check'), implode(', ', array_map(self::text(...), $entry['codes'])));
        }

        $signer = $entry['signer'];
        if ($signer !== null && in_array($entry['state'], ['Trusted', 'Valid', 'Invalid'], true)) {
            $lines[] = $signer['issuer'] === null
                /* translators: %s: the signer's common name */
                ? sprintf(esc_html__('Signed by %s', 'provemark-c2pa-check'), self::text($signer['common_name']))
                /* translators: 1: the signer's common name, 2: its certificate issuer */
                : sprintf(esc_html__('Signed by %1$s (%2$s)', 'provemark-c2pa-check'), self::text($signer['common_name']), self::text($signer['issuer']));
            if ($entry['signed_at'] !== null) {
                /* translators: %s: the signing time as the file states it */
                $lines[] = sprintf(esc_html__('Signed at %s', 'provemark-c2pa-check'), self::text($entry['signed_at']));
            }
        }

        if ($entry['state'] === 'none' && $entry['remote_manifest_url'] !== null) {
            /* translators: %s: a URL from the file, shown as text and never fetched */
            $lines[] = sprintf(esc_html__('Refers to Content Credentials elsewhere (not checked): %s', 'provemark-c2pa-check'), self::text($entry['remote_manifest_url']));
        }

        if ($entry['state'] === 'error') {
            $words = match ($entry['reason']) {
                'interrupted' => __('the check did not finish', 'provemark-c2pa-check'),
                'unreadable' => __('the file could not be read', 'provemark-c2pa-check'),
                default => __('the verifier failed', 'provemark-c2pa-check'),
            };
            /* translators: %s: why the file could not be checked */
            $lines[] = sprintf(esc_html__('Reason: %s', 'provemark-c2pa-check'), esc_html($words));
        }

        if ($entry['checked_at'] !== null && $entry['verifier'] !== null) {
            /* translators: 1: time of the check (UTC), 2: verifier version */
            $lines[] = sprintf(esc_html__('Checked %1$s with c2pa-verifier %2$s', 'provemark-c2pa-check'), self::text($entry['checked_at']), self::text($entry['verifier']));
        }

        return $lines;
    }
}
