# M1.0: which file is "the original" at upload

Measured 2026-09-26 on WordPress 7.1.2, PHP 8.3.35 (wp-env via Colima),
Chrome 153, with `provemark/c2pa-verifier` v0.2.3. Input for SPEC-001.

## Question

A C2PA signature covers the file's bytes. The plugin must verify exactly
the file that was uploaded, not `-scaled`, `-rotated` or any image size.
Which hook and which function give that file, on every upload route, and
is it byte-identical to what was uploaded?

## Method

A must-use probe plugin (below; mounted through a local, untracked
`.wp-env.override.json`, never part of the plugin) logged, at each hook,
`get_attached_file()`, `wp_get_original_image_path()` and the SHA-256 of
each file. Each hash was compared with the source fixture's.

Fixtures, from the verifier's `tests/Fixtures/` (with the verdict
of `vendor/bin/c2pa-verify` without trust settings):

| fixture | size | verdict |
|---|---|---|
| `fixture-signed.jpg` / `.png` / `.webp` | small | `Valid` (`signingCredential.untrusted`) |
| `fixture-unsigned.jpg` / `.png` / `.webp` | small | no manifest |
| `writers/google-20250919-pixel10-npld-picnic-table.jpg` | 4000×3000, 5.7 MB | `Invalid` (`signingCredential.expired`, `.untrusted`) |
| `writers/adobe-20260425-lightroom-classic-church.jpg` | 3280×2451, 1.1 MB | `Valid` (`signingCredential.untrusted`) |

The two large files exceed `big_image_size_threshold` (2560, measured), so
WordPress makes a `-scaled` copy. No fixture with an EXIF orientation
other than 1 exists in either fixture tree (searched with `sips`), so the
`-rotated` case is **not measured**.

Routes:

- (a) WP-CLI `wp media import`: all eight fixtures.
- (b) REST `POST /wp/v2/media` with an application password: signed
  JPEG, PNG, WebP and the Pixel file; the JPEG and the Pixel file again
  with `generate_sub_sizes=false`.
- (c) Media → Add New (`media-new.php`, plupload), in Chrome: signed JPEG
  and the Pixel file.
- (d) The block editor's own upload function (`mediaUpload` from the
  block editor settings, as an Image block or a drop uses it), in Chrome,
  with client-side media processing on (the default) and off
  (`add_filter('wp_client_side_media_processing_enabled', '__return_false')`).

## Results (measured)

**At `add_attachment`, `get_attached_file()` is the uploaded file,
byte-identical to the source, on every route and for every fixture (23
uploads).** The same holds at `rest_after_insert_attachment` on the REST
routes (b) and (d).

| route | client-side processing | `-scaled` made by | original byte-identical at `add_attachment` |
|---|---|---|---|
| (a) WP-CLI | n/a | server | yes, 8/8 |
| (b) REST | n/a | server; none with `generate_sub_sizes=false` | yes, 6/6 |
| (c) Media → Add New | not used (page not cross-origin isolated) | server | yes, 2/2 |
| (d) block editor, on | used (`generate_sub_sizes=false`, sizes sideloaded) | browser, sideloaded | yes, 5/5 |
| (d) block editor, off | not used | server | yes, 2/2 |

Traps, all measured:

1. **After `add_attachment`, `get_attached_file()` moves to `-scaled`**
   for large images: it is not "the original" in any later hook.
2. **`wp_get_original_image_path()` is wrong in between.** At the first
   `wp_update_attachment_metadata` of a large image (server routes), and at
   the `wp_generate_attachment_metadata` / `wp_update_attachment_metadata`
   that follow the browser's `-scaled` sideload (route d), it returns the
   `-scaled` path. In the final state it returns the original.
3. **`wp_handle_upload` also fires for every image size** the browser
   sideloads in route (d) (`-150x150`, `-300x225`, …, `-scaled`): 20 extra
   calls for the two large files. Hooking it would "verify" copies that
   carry no credential.
4. The original files were still byte-identical after all image sizes had
   been made, on every route.

Client-side media processing in WordPress 7.1 (read from core,
`wp-includes/media.php` and `wp-includes/js/dist/upload-media.js`):

- New in 7.1.0. `wp_is_client_side_media_processing_enabled()` is true
  when the site is served over HTTPS (or on `localhost`), filterable with
  `wp_client_side_media_processing_enabled`. So it is **on by default on
  practically every production site**.
- It needs cross-origin isolation, which core sends as
  `Document-Isolation-Policy: isolate-and-credentialless` only to
  Chromium 137 and later, and only on the block editor screens (measured:
  `crossOriginIsolated` true in `post-new.php`, false in `media-new.php`
  and with the filter off). Other browsers use the server route.
- For JPEG, PNG and WebP it uploads the original file as is and makes the
  image sizes in the browser (measured above). HEIC is converted to JPEG
  in the browser **before** upload (read in `prepareItem`, not measured;
  HEIC is out of scope, and such a file arrives without its original
  bytes).

Cost: `(new Verifier)->verify()` inside the container took 40 ms on the
5.7 MB Pixel file, 18 ms and 12 ms on the others, 6 MB peak memory; the
verdicts equal the CLI's on the sources.

## Conclusion for SPEC-001 (reasoned from the measurements)

Verify in `add_attachment`, on `get_attached_file( $id )`: on every
measured route it is the uploaded original, before WordPress or the
browser makes any image size. Do not use `wp_handle_upload` (fires per
image size in route d) and do not rely on `wp_get_original_image_path()`
in a metadata hook (wrong in between). A later check of an existing
attachment (out of scope now) would use `wp_get_original_image_path()`
in the final state.

Not measured: the `-rotated` case; the Media Library modal opened from
inside the block editor; a mime check before verifying; another plugin
changing the file in `wp_handle_upload_prefilter` or `wp_handle_upload`.

## The probe

```php
/**
 * M1.0 measurement probe (not plugin code). Logs, at each hook, which file
 * WordPress points at for an attachment and the SHA-256 of that file.
 */
function m1_probe_log(string $hook, array $data): void
{
    $data = ['hook' => $hook, 't' => microtime(true)] + $data;
    file_put_contents(WP_CONTENT_DIR.'/m1-probe.log', json_encode($data, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

function m1_probe_file(?string $path): ?array
{
    if ($path === null || $path === '' || ! is_file($path)) {
        return $path ? ['path' => $path, 'exists' => false] : null;
    }

    return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'size' => filesize($path)];
}

function m1_probe_attachment(string $hook, int $id, array $extra = []): void
{
    $attached = get_attached_file($id);
    $unfiltered = get_attached_file($id, true);
    $original = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($id) : null;
    $dir = $attached ? dirname($attached) : null;
    $siblings = $dir ? array_map('basename', glob($dir.'/'.preg_replace('/(-scaled|-rotated)?\.[^.]+$/', '', basename($attached)).'*') ?: []) : [];

    m1_probe_log($hook, [
        'id' => $id,
        'mime' => get_post_mime_type($id),
        'get_attached_file' => m1_probe_file($attached ?: null),
        'get_attached_file_unfiltered' => m1_probe_file($unfiltered ?: null),
        'wp_get_original_image_path' => m1_probe_file($original ?: null),
        'files_in_dir' => $siblings,
    ] + $extra);
}

add_filter('wp_handle_upload', function (array $upload, string $context) {
    m1_probe_log('wp_handle_upload', ['context' => $context, 'file' => m1_probe_file($upload['file'] ?? null), 'type' => $upload['type'] ?? null]);

    return $upload;
}, PHP_INT_MAX, 2);

add_action('add_attachment', function (int $id): void {
    m1_probe_attachment('add_attachment', $id);
}, PHP_INT_MAX);

add_filter('wp_generate_attachment_metadata', function (array $meta, int $id, string $context) {
    m1_probe_attachment('wp_generate_attachment_metadata', $id, ['context' => $context, 'original_image' => $meta['original_image'] ?? null, 'sizes' => array_keys($meta['sizes'] ?? [])]);

    return $meta;
}, PHP_INT_MAX, 3);

add_filter('wp_update_attachment_metadata', function ($data, int $id) {
    m1_probe_attachment('wp_update_attachment_metadata', $id);

    return $data;
}, PHP_INT_MAX, 2);

add_action('rest_after_insert_attachment', function (WP_Post $attachment, WP_REST_Request $request, bool $creating): void {
    m1_probe_attachment('rest_after_insert_attachment', $attachment->ID, ['creating' => $creating, 'generate_sub_sizes' => $request['generate_sub_sizes'] ?? null]);
}, PHP_INT_MAX, 3);
```
