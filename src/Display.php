<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a stored SPEC-001 entry into safe HTML: the only place wording and
 * escaping live (SPEC-002). It shows what was stored and never makes a
 * verdict of its own; anything that is not a SPEC-001 entry is "Result
 * unreadable".
 */
final class Display
{
    private const array STATES = ['Trusted', 'Valid', 'Invalid', 'none', 'error'];

    private const array REASONS = ['interrupted', 'unreadable', 'exception', 'unsupported'];

    /**
     * C0 and C1 controls and the Unicode direction controls (UAX #9).
     */
    private const string UNSAFE_CHARACTERS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * The column cell: one headline.
     *
     * @param  mixed  $entry  the stored value, or null when there is none
     * @param  int|null  $pendingSince  when the background check was scheduled (SPEC-013)
     */
    public static function headline(mixed $entry, ?int $pendingSince = null, ?int $now = null, bool $changed = false): string
    {
        $read = self::read($entry);

        return is_array($read) && $changed ? self::changedBadge() : self::badges($read, self::pending($entry, $pendingSince, $now ?? time()));
    }

    /**
     * The attachment-details row: the headline and what the entry says.
     *
     * @param  mixed  $entry  the stored value, or null when there is none
     * @param  int|null  $pendingSince  when the background check was scheduled (SPEC-013)
     */
    public static function details(mixed $entry, ?int $pendingSince = null, ?int $now = null, bool $changed = false): string
    {
        $read = self::read($entry);
        if (is_array($read) && $changed) {
            // The verdict describes bytes that are no longer there (SPEC-014):
            // no verdict, no signer, no AI label.
            return '<div class="tracefern tracefern--changed">'."\n".'<p class="tracefern-badges">'.self::changedBadge().'</p>'."\n".'</div>';
        }
        $pending = self::pending($entry, $pendingSince, $now ?? time());
        [$class] = self::headlineOf($read, $pending);

        $html = '<div class="tracefern tracefern--'.esc_attr($class).'">'."\n".'<p class="tracefern-badges">'.self::badges($read, $pending).'</p>';
        $rows = is_array($read) ? self::rows($read) : [];
        if ($rows !== []) {
            $html .= "\n".'<dl class="tracefern-facts">';
            foreach ($rows as [$term, $value]) {
                $html .= "\n".'<dt>'.$term."</dt>\n<dd>".$value.'</dd>';
            }
            $html .= "\n".'</dl>';
        }

        return $html."\n".'</div>';
    }

    /**
     * How an entry sorts and filters (SPEC-007): its state, `unreadable`
     * when it is not a SPEC-001 entry, and whether the AI label shows.
     * Null for no entry. Reads the entry exactly as the display does.
     *
     * @return array{string, bool}|null
     */
    public static function classify(mixed $entry): ?array
    {
        $read = self::read($entry);
        if ($read === null) {
            return null;
        }

        return $read === false ? ['unreadable', false] : [$read['state'], self::showsAiLabel($read)];
    }

    /**
     * Whether the entry describes another file than the one the attachment
     * has now (SPEC-014): its recorded path, size or modification time
     * differ. An entry that records no file is never "changed".
     *
     * @param  array{path: string, size: int|false, modified: int|false}|null  $current  the current original, relative to uploads
     */
    public static function changed(mixed $entry, ?array $current): bool
    {
        if (! is_array($entry) || ! is_string($entry['file'] ?? null) || $current === null) {
            return false;
        }

        return $entry['file'] !== $current['path']
            || (is_int($entry['size'] ?? null) && $entry['size'] !== $current['size'])
            || (is_int($entry['modified'] ?? null) && $entry['modified'] !== $current['modified']);
    }

    /**
     * The badge for an entry whose file has changed since its check.
     */
    private static function changedBadge(): string
    {
        return '<span class="tracefern-badge tracefern-badge--changed"><span class="dashicons dashicons-warning" aria-hidden="true"></span>'
            .esc_html__('Changed since its check', 'tracefern-image-check-for-c2pa').'</span>';
    }

    /**
     * Whether "Check pending" shows (SPEC-013): no entry yet, and a marker
     * younger than UploadHook::PENDING_FOR. An entry always wins; an older
     * marker means the event was lost, so the image counts as not checked.
     */
    private static function pending(mixed $entry, ?int $pendingSince, int $now): bool
    {
        return $entry === null && $pendingSince !== null && $now - $pendingSince < UploadHook::PENDING_FOR;
    }

    /**
     * Untrusted text from the file, safe to put in HTML: controls and
     * direction characters become U+FFFD, then esc_html.
     */
    public static function text(string $untrusted): string
    {
        // htmlspecialchars() with double encoding, not esc_html(): esc_html()
        // keeps an existing entity such as &#x202E;, which the browser then
        // turns into the very character removed above (SPEC-015).
        return htmlspecialchars(self::visible($untrusted), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    /**
     * Untrusted text with controls and direction characters replaced by
     * U+FFFD, not escaped for HTML.
     */
    private static function visible(string $untrusted): string
    {
        return preg_replace(self::UNSAFE_CHARACTERS, "\u{FFFD}", mb_scrub($untrusted, 'UTF-8')) ?? '';
    }

    /**
     * The verdict other plugins read through the `tracefern_verdict` filter
     * (SPEC-026), built from the same reading of the entry as the column,
     * so the two cannot disagree. Text from the file is made visible, not
     * escaped: the caller escapes it for where it puts it.
     *
     * @param  mixed  $entry  the stored value, or null when there is none
     * @param  int|null  $pendingSince  when the background check was scheduled (SPEC-013)
     * @return array{schema: int, status: string, state: ?string, intact: bool, trusted: bool, ai: bool, signer: array{issuer: ?string, common_name: string}|null, signed_at: ?string, checked_at: ?string, codes: list<string>, reason: ?string, verifier: ?string, trust: ?string}
     */
    public static function verdict(mixed $entry, ?int $pendingSince, int $now, bool $changed): array
    {
        $read = self::read($entry);
        $verdict = [
            'schema' => 1,
            'status' => match (true) {
                $read === false => 'unreadable',
                $read === null => self::pending($entry, $pendingSince, $now) ? 'pending' : 'not_checked',
                $changed => 'changed',
                default => 'checked',
            },
            'state' => null,
            'intact' => false,
            'trusted' => false,
            'ai' => false,
            'signer' => null,
            'signed_at' => null,
            'checked_at' => null,
            'codes' => [],
            'reason' => null,
            'verifier' => null,
            'trust' => null,
        ];
        if (! is_array($read) || $changed) {
            // No verdict on bytes that are no longer there (SPEC-014), nor
            // on a value that is not an entry.
            return $verdict;
        }

        $signer = $read['signer'];

        return [
            'state' => $read['state'],
            'intact' => in_array($read['state'], ['Trusted', 'Valid'], true),
            'trusted' => $read['state'] === 'Trusted',
            'ai' => self::showsAiLabel($read),
            'signer' => $signer === null ? null : [
                'issuer' => $signer['issuer'] === null ? null : self::visible($signer['issuer']),
                'common_name' => self::visible($signer['common_name']),
            ],
            'signed_at' => $read['signed_at'],
            'checked_at' => $read['checked_at'],
            'codes' => $read['state'] === 'Invalid' ? $read['codes'] : [],
            'reason' => $read['reason'],
            'verifier' => $read['verifier'],
            'trust' => $read['trust'],
        ] + $verdict;
    }

    /**
     * A SPEC-001 entry, null for no entry, false for anything else.
     *
     * @return array{state: string, signer: array{issuer: ?string, common_name: string}|null, signed_at: ?string, codes: list<string>, codes_omitted: int, ai: bool, trust: ?string, remote_manifest_url: ?string, reason: ?string, verifier: ?string, checked_at: ?string}|false|null
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
        $omitted = $entry['codes_omitted'] ?? 0;
        if (! is_int($omitted) || $omitted < 0) {
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

        $trust = $entry['trust'] ?? null;
        if ($trust !== null && (! is_string($trust) || preg_match('/^(custom|none|c2pa-\d{4}-\d{2}-\d{2}(\+digicert)?)$/', $trust) !== 1)) {
            return false;
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
            'codes_omitted' => $omitted,
            'ai' => $ai,
            'trust' => $trust,
            'remote_manifest_url' => $text['remote_manifest_url'],
            'reason' => $reason,
            'verifier' => $text['verifier'],
            'checked_at' => $text['checked_at'],
        ];
    }

    /**
     * The verdict (and the AI label) as badges: an icon that is decoration
     * only, and the words (SPEC-002 amendment 1).
     *
     * @param  array{state: string, ai: bool}|false|null  $read
     */
    private static function badges(array|false|null $read, bool $pending = false): string
    {
        [$class, $headline] = self::headlineOf($read, $pending);
        $icon = match ($class) {
            'trusted' => 'yes-alt',
            'valid' => 'yes',
            'invalid' => 'dismiss',
            'error', 'unreadable' => 'warning',
            'pending' => 'clock',
            default => 'minus',
        };

        $html = '<span class="tracefern-badge tracefern-badge--'.esc_attr($class).'"><span class="dashicons dashicons-'.esc_attr($icon).'" aria-hidden="true"></span>'.esc_html($headline).'</span>';

        return self::showsAiLabel($read)
            ? $html.' <span class="tracefern-badge tracefern-badge--ai">'.esc_html__('AI-generated (signed)', 'tracefern-image-check-for-c2pa').'</span>'
            : $html;
    }

    /**
     * "2026-09-26 08:04 UTC" for the plugin's own time stamp; anything else
     * is shown as it is (escaped).
     */
    private static function checkedAt(string $checkedAt): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $checkedAt, new DateTimeZone('UTC'));

        return $at === false ? self::text($checkedAt) : esc_html($at->format('Y-m-d H:i').' UTC');
    }

    /**
     * Which trust the check used (SPEC-004), in words.
     */
    private static function trustList(string $trust): string
    {
        if ($trust === 'custom') {
            return esc_html__('Custom trust settings', 'tracefern-image-check-for-c2pa');
        }
        if ($trust === 'none') {
            return esc_html__('None', 'tracefern-image-check-for-c2pa');
        }

        $date = self::text(substr($trust, 5, 10));

        return str_ends_with($trust, '+digicert')
            /* translators: %s: date of the C2PA trust list */
            ? sprintf(esc_html__('C2PA, %s (with DigiCert timestamps)', 'tracefern-image-check-for-c2pa'), $date)
            /* translators: %s: date of the C2PA trust list */
            : sprintf(esc_html__('C2PA, %s', 'tracefern-image-check-for-c2pa'), $date);
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
    private static function headlineOf(array|false|null $read, bool $pending = false): array
    {
        if ($read === null) {
            return $pending
                ? ['pending', __('Check pending', 'tracefern-image-check-for-c2pa')]
                : ['unchecked', __('Not checked', 'tracefern-image-check-for-c2pa')];
        }
        if ($read === false) {
            return ['unreadable', __('Result unreadable', 'tracefern-image-check-for-c2pa')];
        }

        return match ($read['state']) {
            'Trusted' => ['trusted', __('Verified: trusted signer', 'tracefern-image-check-for-c2pa')],
            'Valid' => ['valid', __('Intact: signer not trusted', 'tracefern-image-check-for-c2pa')],
            'Invalid' => ['invalid', __('Does not verify', 'tracefern-image-check-for-c2pa')],
            'none' => ['none', __('No Content Credentials', 'tracefern-image-check-for-c2pa')],
            default => ['error', __('Could not be checked', 'tracefern-image-check-for-c2pa')],
        };
    }

    /**
     * The facts under the badges: term and value, each already safe HTML.
     *
     * @param  array{state: string, signer: array{issuer: ?string, common_name: string}|null, signed_at: ?string, codes: list<string>, codes_omitted: int, ai: bool, trust: ?string, remote_manifest_url: ?string, reason: ?string, verifier: ?string, checked_at: ?string}  $entry
     * @return list<array{string, string}>
     */
    private static function rows(array $entry): array
    {
        $rows = [];

        $signer = $entry['signer'];
        if ($signer !== null && in_array($entry['state'], ['Trusted', 'Valid', 'Invalid'], true)) {
            $rows[] = [
                esc_html__('Signer', 'tracefern-image-check-for-c2pa'),
                $signer['issuer'] === null ? self::text($signer['common_name']) : self::text($signer['common_name']).' ('.self::text($signer['issuer']).')',
            ];
            if ($entry['signed_at'] !== null) {
                $rows[] = [esc_html__('Signed at', 'tracefern-image-check-for-c2pa'), self::text($entry['signed_at'])];
            }
        }

        if ($entry['state'] === 'Invalid' && $entry['codes'] !== []) {
            $rows[] = [
                esc_html__('Codes', 'tracefern-image-check-for-c2pa'),
                implode("<br>\n", array_map(static fn (string $code): string => '<code>'.self::text($code).'</code>', $entry['codes']))
                    .($entry['codes_omitted'] > 0
                        /* translators: %d: how many more status codes the file has */
                        ? "<br>\n".esc_html(sprintf(__('and %d more', 'tracefern-image-check-for-c2pa'), $entry['codes_omitted']))
                        : ''),
            ];
        }

        if ($entry['state'] === 'none' && $entry['remote_manifest_url'] !== null) {
            /* translators: %s: a URL from the file, shown as text and never fetched */
            $rows[] = [esc_html__('Refers to', 'tracefern-image-check-for-c2pa'), sprintf(esc_html__('%s (not checked)', 'tracefern-image-check-for-c2pa'), self::text($entry['remote_manifest_url']))];
        }

        if ($entry['state'] === 'error') {
            $words = match ($entry['reason']) {
                'interrupted' => __('the check did not finish', 'tracefern-image-check-for-c2pa'),
                'unreadable' => __('the file could not be read', 'tracefern-image-check-for-c2pa'),
                'unsupported' => __('not a JPEG, PNG or WebP file', 'tracefern-image-check-for-c2pa'),
                default => __('the verifier failed', 'tracefern-image-check-for-c2pa'),
            };
            $rows[] = [esc_html__('Reason', 'tracefern-image-check-for-c2pa'), esc_html($words)];
        }

        if ($entry['checked_at'] !== null && $entry['verifier'] !== null) {
            /* translators: 1: time of the check, 2: verifier version */
            $rows[] = [esc_html__('Checked', 'tracefern-image-check-for-c2pa'), sprintf(esc_html__('%1$s, c2pa-verifier %2$s', 'tracefern-image-check-for-c2pa'), self::checkedAt($entry['checked_at']), self::text($entry['verifier']))];
        }

        if ($entry['trust'] !== null) {
            $rows[] = [esc_html__('Trust list', 'tracefern-image-check-for-c2pa'), self::trustList($entry['trust'])];
        }

        return $rows;
    }
}
