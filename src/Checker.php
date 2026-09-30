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
     * The largest original read from another plugin's storage (SPEC-032).
     */
    public const int MAX_OFFLOADED = 64 * 1024 * 1024;

    /**
     * PHP's own stream wrappers: a path in one of them is never opened
     * (SPEC-032). A kept path is untrusted text; only a wrapper another
     * plugin registered, for files WordPress hands out, is read.
     */
    private const array PHP_WRAPPERS = ['file', 'http', 'https', 'ftp', 'ftps', 'php', 'data', 'glob', 'phar', 'zip', 'zlib', 'rar', 'ogg', 'expect'];

    /**
     * @param  ?string  $sha256  set to the SHA-256 of the bytes verified when they were copied from another plugin's storage (SPEC-032), else null
     * @return array<string, mixed>
     */
    public function check(string $path, ?TrustSettings $settings = null, string $trust = 'none', ?string &$sha256 = null): array
    {
        $sha256 = null;
        $stream = self::streamPath($path) ? self::copyOfOffloaded($path, $sha256) : self::openLocal($path);
        if (is_string($stream)) {
            return Outcome::error($stream, $this->version(), new DateTimeImmutable, $trust);
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
     * Whether a path names a stream wrapper ("scheme://…") rather than a
     * local file.
     */
    public static function streamPath(string $path): bool
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1;
    }

    /**
     * A read-only stream of the local upload, which the verifier needs;
     * WP_Filesystem has no stream API.
     *
     * @return resource|'unreadable'
     */
    private static function openLocal(string $path): mixed
    {
        $stream = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the verifier reads a stream; WP_Filesystem has no stream API

        return $stream === false ? 'unreadable' : $stream;
    }

    /**
     * A copy, in php://temp, of an original another plugin moved to its
     * storage and hands out through a stream wrapper it registered
     * (SPEC-032). At most MAX_OFFLOADED bytes are kept; a stream that fails
     * or ends before its stated size gives no copy.
     *
     * @return resource|'unreadable'|'too_large'
     */
    private static function copyOfOffloaded(string $path, ?string &$sha256): mixed
    {
        $scheme = strtolower(substr($path, 0, (int) strpos($path, '://')));
        if (in_array($scheme, self::PHP_WRAPPERS, true) || str_starts_with($scheme, 'compress.') || str_starts_with($scheme, 'ssh2.')
            || ! in_array($scheme, stream_get_wrappers(), true)) {
            return 'unreadable';
        }

        $source = @fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- another plugin's stream wrapper; WP_Filesystem has no stream API
        if ($source === false) {
            return 'unreadable';
        }
        $copy = false;
        try {
            if (stream_get_meta_data($source)['wrapper_type'] !== 'user-space') {
                return 'unreadable';
            }
            $copy = fopen('php://temp/maxmemory:2097152', 'w+b'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a temporary stream for the verifier
            if ($copy === false) {
                return 'unreadable';
            }
            $copied = stream_copy_to_stream($source, $copy, self::MAX_OFFLOADED + 1);
            if ($copied === false) {
                return 'unreadable';
            }
            if ($copied > self::MAX_OFFLOADED) {
                return 'too_large';
            }
            $stated = fstat($source);
            $size = is_array($stated) ? $stated['size'] : null;
            if (! feof($source) || (is_int($size) && $size !== $copied)) {
                return 'unreadable';
            }
            if (! rewind($copy)) {
                return 'unreadable';
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $copy);
            $sha256 = hash_final($context);
            if (! rewind($copy)) {
                return 'unreadable';
            }
            $result = $copy;
            $copy = false;

            return $result;
        } finally {
            fclose($source); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above
            if ($copy !== false) {
                fclose($copy); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the copy is not handed on
            }
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
