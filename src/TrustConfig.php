<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Throwable;

/**
 * The trust settings for a check (SPEC-004): the administrator's custom
 * settings when there are any, which replace everything, otherwise the
 * bundled C2PA lists, with or without the DigiCert timestamp root. Built
 * the way the verifier's docs/trust-settings.md describes.
 */
final class TrustConfig
{
    public const string LIST_DATE = '2026-08-14';

    public const string LIST_COMMIT = '99927ca';

    public const int STALE_AFTER_DAYS = 183;

    public function __construct(
        private readonly string $trustDir,
        private readonly string $customJson,
        private readonly bool $digiCert,
    ) {}

    /**
     * The settings and the name of their source, or null and `none` when
     * they cannot be built. Never throws.
     *
     * @return array{?TrustSettings, string}
     */
    public function build(): array
    {
        $json = $this->settingsJson();
        if ($json === null) {
            return [null, 'none'];
        }

        try {
            return [TrustSettings::fromJson($json), $this->source()];
        } catch (Throwable) {
            return [null, 'none'];
        }
    }

    /**
     * The settings JSON this configuration stands for, or null when the
     * bundled files cannot be read.
     */
    public function settingsJson(): ?string
    {
        if (trim($this->customJson) !== '') {
            return $this->customJson;
        }

        $files = ['C2PA-TRUST-LIST.pem' => 'manifest', 'C2PA-TSA-TRUST-LIST.pem' => 'tsa'];
        if ($this->digiCert) {
            $files['DigiCertTrustedRootG4.crt.pem'] = 'tsa';
        }

        $anchors = [];
        foreach ($files as $file => $kind) {
            $path = $this->trustDir.'/'.$file;
            $pem = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if ($pem === false || $pem === '') {
                return null;
            }
            $anchors[] = ['trust_anchors' => $pem, 'trust_kind' => $kind];
        }

        // This class is WordPress-free: the tests build the verifier CLI's
        // settings with it outside WordPress (SPEC-015 AC11).
        $json = json_encode(['verify' => ['verify_trust' => true], 'trust' => ['anchors' => $anchors]], JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free class, see above.

        return $json === false ? null : $json;
    }

    /**
     * Whether the bundled list is older than STALE_AFTER_DAYS at $now.
     */
    public static function isStale(DateTimeImmutable $now): bool
    {
        $date = new DateTimeImmutable(self::LIST_DATE.'T00:00:00Z', new DateTimeZone('UTC'));

        return $now >= $date->modify('+'.self::STALE_AFTER_DAYS.' days');
    }

    private function source(): string
    {
        if (trim($this->customJson) !== '') {
            return 'custom';
        }

        return 'c2pa-'.self::LIST_DATE.($this->digiCert ? '+digicert' : '');
    }
}
