<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

use Closure;
use Composer\InstalledVersions;
use DateTimeImmutable;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;
use Throwable;

/**
 * Checks one file and always returns an Outcome entry; it never throws.
 */
final class Checker
{
    /** @var Closure(resource): VerificationReport */
    private Closure $verify;

    /**
     * @param  (Closure(resource): VerificationReport)|null  $verify  the verifier call; tests replace it
     */
    public function __construct(?Closure $verify = null)
    {
        $this->verify = $verify ?? self::verifyWithBundledVerifier(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $path): array
    {
        // A read-only stream of the local upload, which the verifier needs;
        // WP_Filesystem has no stream API.
        $stream = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ($stream === false) {
            return Outcome::error('unreadable', $this->version(), new DateTimeImmutable);
        }

        try {
            return Outcome::fromReport(($this->verify)($stream), $this->version(), new DateTimeImmutable);
        } catch (Throwable) {
            // The message may hold paths or file content; the reason is enough.
            return Outcome::error('exception', $this->version(), new DateTimeImmutable);
        } finally {
            fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    /**
     * The entry to store before verifying: if the request dies inside the
     * verifier (a memory or time limit cannot be caught), this is what stays.
     *
     * @return array<string, mixed>
     */
    public function interrupted(): array
    {
        return Outcome::error('interrupted', $this->version(), new DateTimeImmutable);
    }

    /**
     * @param  resource  $stream
     */
    private static function verifyWithBundledVerifier($stream): VerificationReport
    {
        return (new Verifier)->verify($stream);
    }

    private function version(): string
    {
        return InstalledVersions::getPrettyVersion('provemark/c2pa-verifier') ?? 'unknown';
    }
}
