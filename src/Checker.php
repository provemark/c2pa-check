<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use DateTimeImmutable;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;
use Throwable;

/**
 * Checks one file and always returns an Outcome entry; it never throws.
 */
final class Checker
{
    /** The bundled verifier's version, read once per request. */
    private static ?string $version = null;

    /** @var Closure(resource, ?TrustSettings): VerificationReport */
    private Closure $verify;

    /**
     * @param  (Closure(resource, ?TrustSettings): VerificationReport)|null  $verify  the verifier call; tests replace it
     */
    public function __construct(?Closure $verify = null)
    {
        $this->verify = $verify ?? self::verifyWithBundledVerifier(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $path, ?TrustSettings $settings = null, string $trust = 'none'): array
    {
        // A read-only stream of the local upload, which the verifier needs;
        // WP_Filesystem has no stream API.
        $stream = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the verifier reads a stream; WP_Filesystem has no stream API
        if ($stream === false) {
            return Outcome::error('unreadable', $this->version(), new DateTimeImmutable, $trust);
        }

        try {
            return Outcome::fromReport(($this->verify)($stream, $settings), $this->version(), new DateTimeImmutable, $trust);
        } catch (Throwable) {
            // The message may hold paths or file content; the reason is enough.
            return Outcome::error('exception', $this->version(), new DateTimeImmutable, $trust);
        } finally {
            fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above
        }
    }

    /**
     * The entry to store before verifying: if the request dies inside the
     * verifier (a memory or time limit cannot be caught), this is what stays.
     *
     * @return array<string, mixed>
     */
    public function interrupted(string $trust = 'none'): array
    {
        return Outcome::error('interrupted', $this->version(), new DateTimeImmutable, $trust);
    }

    /**
     * @param  resource  $stream
     */
    private static function verifyWithBundledVerifier($stream, ?TrustSettings $settings): VerificationReport
    {
        return (new Verifier)->verify($stream, $settings);
    }

    /**
     * The bundled verifier's version, from this plugin's own Composer data
     * (SPEC-015): not through Composer\InstalledVersions, a global class
     * another plugin may define first. Never throws.
     */
    private function version(): string
    {
        if (self::$version === null) {
            self::$version = 'unknown';
            try {
                $installed = include dirname(__DIR__).'/vendor/composer/installed.php';
                $pretty = is_array($installed) && is_array($installed['versions'] ?? null) && is_array($installed['versions']['provemark/c2pa-verifier'] ?? null)
                    ? $installed['versions']['provemark/c2pa-verifier']['pretty_version'] ?? null
                    : null;
                self::$version = is_string($pretty) && $pretty !== '' ? $pretty : 'unknown';
            } catch (Throwable) {
                // 'unknown'
            }
        }

        return self::$version;
    }
}
