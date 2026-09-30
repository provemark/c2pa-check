<?php

/**
 * A stand-in for an offload plugin, for SPEC-032's integration tests only.
 * OffloadTest copies it into mu-plugins and removes it afterwards.
 *
 * Like WP Offload Media with "Remove Local Media" and delivery on
 * (notes/offload-media.md): on the metadata update of an upload it copies
 * the attachment's files to a "bucket" folder outside uploads/ and deletes
 * them locally, at priority 110; get_attached_file() and
 * wp_get_original_image_path() then return a path in its own stream
 * wrapper, tfoffload://bucket/<path relative to uploads>. Its stream does
 * not seek, as the AWS SDK's S3 wrapper opened without its `seekable`
 * option does not (measured: rewind() fails, so the verifier refuses it).
 *
 * Options that steer it:
 * - tracefern_test_offload: 1 to offload new uploads.
 * - tracefern_test_offload_mode: '' | 'fail_open' | 'stop_half'.
 * - tracefern_test_offload_paths: [attachment ID => path] returned as is.
 * - tracefern_test_offload_trap: 1 to replace PHP's own http, https and ftp
 *   wrappers with one that says every path is a file and counts opens (phar
 *   and data cannot be replaced: WP-CLI itself runs from a phar).
 * Counters: tracefern_test_offload_served (bytes), tracefern_test_trap_opens.
 */

declare(strict_types=1);

const TRACEFERN_TEST_OFFLOAD_SCHEME = 'tfoffload';

function tracefern_test_offload_bucket(): string
{
    return WP_CONTENT_DIR.'/tf-offload-bucket';
}

/** The path relative to uploads/, or null for a file outside it. */
function tracefern_test_offload_relative(string $file): ?string
{
    $base = rtrim(wp_get_upload_dir()['basedir'], '/').'/';

    return str_starts_with($file, $base) ? substr($file, strlen($base)) : null;
}

final class Tracefern_Test_Offload_Stream
{
    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    private int $size = 0;

    private int $served = 0;

    private static function local(string $url): string
    {
        return tracefern_test_offload_bucket().'/'.substr($url, strlen(TRACEFERN_TEST_OFFLOAD_SCHEME.'://bucket/'));
    }

    public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool
    {
        if (get_option('tracefern_test_offload_mode') === 'fail_open' || ! str_contains($mode, 'r')) {
            return false;
        }
        $path = self::local($url);
        $file = is_file($path) ? fopen($path, 'rb') : false;
        if ($file === false) {
            return false;
        }
        $this->file = $file;
        $this->size = (int) filesize($path);

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (! is_resource($this->file) || $count < 1) {
            return false;
        }
        if (get_option('tracefern_test_offload_mode') === 'stop_half' && (int) ftell($this->file) >= intdiv($this->size, 2)) {
            return '';
        }
        $bytes = fread($this->file, $count);
        $this->served += is_string($bytes) ? strlen($bytes) : 0;

        return $bytes;
    }

    public function stream_eof(): bool
    {
        if (! is_resource($this->file)) {
            return true;
        }
        if (get_option('tracefern_test_offload_mode') === 'stop_half' && (int) ftell($this->file) >= intdiv($this->size, 2)) {
            return true;
        }

        return feof($this->file);
    }

    /** No seek succeeds, not even rewind() at the start (measured for the AWS wrapper). */
    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return is_resource($this->file) ? (int) ftell($this->file) : 0;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return is_resource($this->file) ? fstat($this->file) : false;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $url, int $flags): array|false
    {
        $path = self::local($url);

        return is_file($path) ? stat($path) : false;
    }

    public function stream_close(): void
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
        $this->file = null;
        $before = get_option('tracefern_test_offload_served', 0);
        update_option('tracefern_test_offload_served', (is_numeric($before) ? (int) $before : 0) + $this->served, false);
    }
}

/** Replaces PHP's own wrappers when the trap is set: every open is counted, and fails. */
final class Tracefern_Test_Trap_Stream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool
    {
        $before = get_option('tracefern_test_trap_opens', 0);
        update_option('tracefern_test_trap_opens', (is_numeric($before) ? (int) $before : 0) + 1, false);

        return false;
    }

    /** @return array<string, int> */
    public function url_stat(string $url, int $flags): array
    {
        return ['mode' => 0100644, 'size' => 1000, 'mtime' => 1];
    }
}

stream_wrapper_register(TRACEFERN_TEST_OFFLOAD_SCHEME, Tracefern_Test_Offload_Stream::class);

if (get_option('tracefern_test_offload_trap')) {
    foreach (['http', 'https', 'ftp'] as $scheme) {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }
        stream_wrapper_register($scheme, Tracefern_Test_Trap_Stream::class);
    }
}

// Offload after WordPress and every other filter have written the metadata.
add_filter('wp_update_attachment_metadata', static function (mixed $data, mixed $id): mixed {
    if (! get_option('tracefern_test_offload') || ! is_array($data) || ! is_int($id)) {
        return $data;
    }
    $attached = get_attached_file($id, true);
    if (! is_string($attached) || $attached === '' || str_starts_with($attached, TRACEFERN_TEST_OFFLOAD_SCHEME.'://')) {
        return $data;
    }
    $dir = dirname($attached);
    $files = [$attached];
    if (is_string($data['original_image'] ?? null)) {
        $files[] = $dir.'/'.$data['original_image'];
    }
    foreach (is_array($data['sizes'] ?? null) ? $data['sizes'] : [] as $size) {
        if (is_array($size) && is_string($size['file'] ?? null)) {
            $files[] = $dir.'/'.$size['file'];
        }
    }
    foreach ($files as $file) {
        $relative = tracefern_test_offload_relative($file);
        if ($relative === null || ! is_file($file)) {
            continue;
        }
        $target = tracefern_test_offload_bucket().'/'.$relative;
        wp_mkdir_p(dirname($target));
        if (copy($file, $target)) {
            unlink($file);
        }
    }

    return $data;
}, 110, 2);

$tracefern_test_offload_path = static function (mixed $file, mixed $id): mixed {
    $forced = get_option('tracefern_test_offload_paths');
    if (is_array($forced) && is_int($id) && is_string($forced[$id] ?? null)) {
        return $forced[$id];
    }
    if (! is_string($file) || $file === '' || file_exists($file)) {
        return $file;
    }
    $relative = tracefern_test_offload_relative($file);

    return $relative !== null && is_file(tracefern_test_offload_bucket().'/'.$relative)
        ? TRACEFERN_TEST_OFFLOAD_SCHEME.'://bucket/'.$relative
        : $file;
};
add_filter('get_attached_file', $tracefern_test_offload_path, 10, 2);
add_filter('wp_get_original_image_path', $tracefern_test_offload_path, 10, 2);
