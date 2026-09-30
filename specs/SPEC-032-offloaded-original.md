# SPEC-032: Check an original that another plugin moved to cloud storage

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Offload plugins copy each upload to cloud storage and can remove the local
files. Measured on 2026-09-30 with WP Offload Media Lite 3.4.3
(`notes/offload-media.md`, `NOTES.md` "Open" 3): with "Remove Local
Media" on, the local original is gone before the background check runs
(SPEC-013), and the plugin stores `error` for (nearly) every upload,
shown as "Could not be checked":

- with delivery from the bucket off: `unreadable`, since the local path
  no longer exists;
- with delivery on, the realistic setting (without it the site's images
  break): `exception`. WP Offload Media then returns its own stream
  wrapper path (`s3useast1://bucket/…`) from `get_attached_file()` and
  `wp_get_original_image_path()`, the plugin keeps that path and passes
  it to the verifier, and the verifier refuses the stream as not
  seekable.

The offloaded original was byte-identical to the upload in every case. A
copy of that stream into `php://temp` verified exactly as the CLI does
(`Valid` for both test files). So the plugin fails closed, as its rules
ask, but on such a site it is of no use. The readme's FAQ says only that
such images may show "Changed since its check".

Opening the wrapper with its own `seekable` option instead gave a wrong
`Invalid`; that was a verifier fault, fixed in verifier 0.2.7 (its
SPEC-050), which this plugin bundles since 0.1.6. A copy stays the safer
way: it does not depend on how well another plugin's stream seeks.

## Scope

**In scope**

1. **A copy of a stream WordPress hands out.** When the file to check is
   not a local file but a path in a stream wrapper that a plugin
   registered (PHP reports it as `user-space`), `Checker::check()` copies
   it into `php://temp` and verifies the copy. The path comes from
   WordPress itself, from `get_attached_file()` or
   `wp_get_original_image_path()` as another plugin filters them; this
   plugin builds no URL and holds no credentials.
2. **Only such wrappers.** PHP's own wrappers are never opened this way:
   `http`, `https`, `ftp`, `ftps`, `php`, `data`, `phar`, `glob`,
   `compress.*`, `zip` and any other that is not `user-space`. A stored
   path is untrusted text (another plugin or a database edit can write
   post meta); a path the rule does not allow is `unreadable`, and it is
   not opened.
3. **A size limit** (open question 1): a file larger than the limit is
   not copied beyond it and ends as `error` with a new reason,
   `too_large`, shown as "the file is larger than the plugin reads
   from external storage".
4. **Fail closed.** A stream that cannot be opened, stops, or ends early
   gives `unreadable`; never a verdict on part of a file.
5. **What is recorded.** The verdict's `file`, `size` and `modified`
   are those of the path, as today (SPEC-014); the fingerprint check of
   SPEC-028 hashes the copy, so "Changed after upload" keeps working.
6. **The readme** (open question 2): the line "It makes no network
   calls" and the FAQ on external storage say what now happens.

**Out of scope** (each needs its own spec before it may be built)

- Offload setups that give WordPress no path at all (only a URL in the
  attachment metadata, no stream wrapper). Not measured.
- The cost of displaying such images: `Display` and `MediaScreens` call
  `is_file()`, `filesize()` and `filemtime()` on the path to decide
  "Changed since its check", and through a stream wrapper each of those
  may be a request to the storage (reasoned, not measured). That happens
  today already, whenever an offload plugin hands out such a path; it is
  for a measurement and a spec of its own.
- WP Offload Media's own "copy back to local" features, and any
  integration with a specific offload plugin's API. The rule above is
  generic: any plugin that registers a stream wrapper and filters those
  two functions is covered.
- Images already stored as `error`: they are checked again with
  "Check all images again" (SPEC-031) or `wp tracefern check --all`.

## Behavior

Acceptance criteria as Given/When/Then, each covered by a Pest test tagged
`->group('SPEC-032')`. The integration tests stand in for an offload
plugin with a stream wrapper registered in the test (served from a folder
outside `uploads/`) and filters on `get_attached_file` and
`wp_get_original_image_path` that return its path once the local file is
gone. The real plugin is measured once by hand (AC7).

- **AC1 — an offloaded original is checked**
  - Given a signed JPEG uploaded and then offloaded (local original
    removed, the filters return the wrapper path)
  - When the queue runs the check
  - Then the stored state equals the verifier CLI on the fixture
    (`Valid` with the bundled settings for `fixture-signed.jpg`), and
    `file` is the wrapper path

- **AC2 — a large image's original, not its copy**
  - Given the Lightroom church JPEG (3280×2451), offloaded after WordPress
    made its `-scaled` copy
  - When the queue runs the check
  - Then the checked path is the original's (from
    `wp_get_original_image_path()`), and the state equals the CLI

- **AC3 — PHP's own wrappers are not opened** *(error path)*
  - Given an attachment whose kept path and filtered path are
    `http://…`, `php://filter/…`, `data:…` or `phar://…`
  - When the check runs
  - Then the state is `error`, reason `unreadable`, and nothing was
    opened (the test's wrapper for those schemes records no open)

- **AC4 — larger than the limit** *(error path)*
  - Given an offloaded file one byte over the limit
  - When the check runs
  - Then the state is `error`, reason `too_large`; the copy stopped at
    the limit plus one byte; the Media Library shows "Could not be
    checked" with the reason's words

- **AC5 — a stream that breaks** *(error path)*
  - Given a wrapper that fails the open, and one that stops part-way
    without an error
  - When the check runs
  - Then the state is `error`, reason `unreadable`, and no verdict is
    stored

- **AC6 — "Changed after upload" still works**
  - Given an upload whose fingerprint was taken at `wp_handle_upload`
    (SPEC-028), offloaded, and the object then changed in the storage
  - When the check runs
  - Then `changed_after_upload` is true; and for an unchanged object it is
    absent

- **AC7 — measured with the real plugin (not in CI)**
  - Given WP Offload Media Lite (the version of the day) against a local
    S3-compatible gateway, "Remove Local Media" and delivery on
  - When `fixture-signed.jpg` and the church JPEG are imported with
    WP-CLI and the queue runs
  - Then both are `Valid`, equal to the CLI, recorded in
    `notes/offload-media.md`

- **AC8 — the readme says it** (wording per open question 2)

## References

- Measured: `notes/offload-media.md` (2026-09-30), WP Offload Media Lite
  3.4.3, AWS SDK stream wrapper, local Versity S3 gateway; the verifier's
  SPEC-050 and its release 0.2.7.
- WordPress: `get_attached_file()` and `wp_get_original_image_path()`
  pass their result through filters of the same name; offload plugins use
  them (read in WP Offload Media's `classes/integrations/media-library.php`).
- PHP: `stream_get_meta_data()['wrapper_type']` is `user-space` for a
  wrapper registered with `stream_wrapper_register()`; measured
  `user-space` for WP Offload Media's.
- Governing rules in this plugin: fail closed, never block; everything
  from the file is untrusted text; no network calls (the readme,
  open question 2).

## API sketch

Illustrative only.

```php
// Checker::check(string $path, ...): array
//   local file          -> as today
//   user-space wrapper  -> Checker::copyOf($path, self::MAX_OFFLOADED)
//                          -> php://temp stream, or null (too large / broken)
//   anything else       -> Outcome::error('unreadable', ...)
// Outcome::error() gains the reason 'too_large'; Display::REASONS too.
```

## Open questions

1. **The size limit** *(blocking)*. Proposal: a fixed 64 MiB. A 50 MP
   JPEG from a camera is about 20–30 MB (reasoned); the copy spills to a
   temporary file beyond 2 MB, so memory is not the limit, the download is.
   Alternative: `wp_max_upload_size()`, the largest file the site accepts,
   which can be 1 GB or more on some hosts.
2. **The readme's "It makes no network calls"** *(blocking)*. Proposal:
   "It makes no network calls of its own. When another plugin has moved
   the original image to cloud storage, the image is read through that
   plugin, once per check." And the FAQ on external storage: images there
   are checked; "Changed since its check" may still show after a move.
   Alternative: keep the promise absolute and make this behaviour an
   option on the settings page, off by default.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
