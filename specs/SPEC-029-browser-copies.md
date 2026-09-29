# SPEC-029: The browser's copies are not the original

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | SPEC-014 in part: a metadata update from the finalize endpoint does not move the kept path |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A signed JPEG with an EXIF orientation other than 1, uploaded in the block
editor with client-side media processing, is stored as `none` ("No Content
Credentials"). Measured on 2026-09-29 (`notes/exif-rotation.md`, `NOTES.md`
"Open" point 6) with three fixtures of orientation 6: the plugin checked
the browser's `-rotated-1` or `-scaled-1` copy, which has no manifest,
while the upload next to it was byte-identical to the source and the
verifier CLI gives `Invalid` with its codes. WP-CLI and REST uploads, and
block editor uploads without rotation, were correct. Client-side
processing is on by default for HTTPS sites in Chromium
(`notes/m1-original-file.md`), and portrait phone photos often carry an
orientation (reasoned).

How it happens, read in core 7.1.2
(`wp-includes/rest-api/endpoints/class-wp-rest-attachments-controller.php`,
`wp-includes/js/dist/upload-media.js`):

- The browser uploads the file as is (`POST /wp/v2/media`,
  `generate_sub_sizes: false`), sideloads its own copies to
  `POST /wp/v2/media/<id>/sideload` (the rotated image as `image_size:
  original`, a downsized one as `scaled`, then the image sizes), and
  finally `POST /wp/v2/media/<id>/finalize` writes the metadata in one
  `wp_update_attachment_metadata()` call.
- An `original` or `scaled` sideload makes its copy the attached file and
  records the file it replaces as `original_image`. With both, the second
  records the first copy: `original_image` names the `-rotated-1` copy
  (measured), although core's comment calls it "the untouched upload".
  The `-1` suffix comes from the sideload's `wp_unique_filename` filter,
  which strips it only for names built on the attached file's name, by
  then the rotated copy (reasoned from `filter_wp_unique_filename()`).
- Core records each sideloaded name in the post meta `_wp_sideloaded_file`,
  but `finalize_item()` deletes the rows it consumed right after the
  metadata update; after finalize they are gone or partial (measured: none
  left for two attachments, two for the third). They cannot say later
  which file was a copy.

The plugin (SPEC-014) moves its kept path in the `wp_update_attachment_metadata`
filter whenever the attachment's original, as `UploadHook::shownFile()`
reads it, is another file. `shownFile()` recognises WordPress's own copies
only by the exact names `<original_image>-scaled` and `-rotated`, so the
finalize update moves the kept path to the browser's copy.

## Scope

**In scope**

- *The finalize request does not move the kept path.* While WordPress
  serves `POST /wp/v2/media/<id>/finalize` (recognised in
  `rest_request_before_callbacks` by its route), the plugin's metadata
  filter leaves the kept path as it is. That request only records the
  copies the browser made of the upload; the kept path was set to the
  upload at `add_attachment`. An attachment not yet kept is kept as today.
- *Images already stored wrongly*: when a check reads a kept path whose
  file name ends in `-rotated-<n>` or `-scaled-<n>` (the browser's form;
  WordPress's own copies have no number), and the name without those
  suffixes (repeatedly) exists in the same folder as a JPEG, PNG or WebP,
  it checks that file and keeps it. So `wp tracefern check` fixes them.
  The changelog says: images with EXIF rotation uploaded in the block
  editor may show "No Content Credentials" wrongly; check them again with
  `wp tracefern check --state=none`.
- *Edits stay as they are* (SPEC-014 AC1–AC3): `wp_save_image()` and
  `wp_restore_image()` do not go through the finalize route.
- Fixtures: `no_alg.jpg` (480×270, orientation 6; from `c2pa-rs`, MIT or
  Apache-2.0, via the verifier) and `truepic-20230212-camera.jpg`
  (4032×3024, orientation 6, 2.2 MB; C2PA public test files, CC BY-SA 4.0,
  via the verifier), each with its row in `tests/Fixtures/README.md`.

**Out of scope** (each needs its own spec before it may be built)

- Reporting core's `original_image` for a rotated and scaled upload to
  WordPress (a Trac ticket is Maurice's call, not code).
- A later edit in the block editor's own image editor over REST
  (`/wp/v2/media/<id>/edit`) on a browser-route upload: measured only as
  far as SPEC-014 did (`wp_save_image()`).
- HEIC and other formats the browser converts before upload.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-029')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

The integration tests replay the browser's requests over REST as
`upload-media.js` sends them (reasoned): the upload with
`generate_sub_sizes: false`; `/sideload` with the rotated copy as
`original` and, for the large image, a downsized copy as `scaled` (both
made in the test with WordPress's image editor, so without a manifest);
`/finalize` with the collected `sub_sizes`.

- **AC1 — a rotated upload on the browser route is checked on the upload**
  - Given `no_alg.jpg` uploaded that way, with its rotated copy sideloaded
    as `original`, and finalized
  - When the check has run
  - Then the kept path is the upload, and the entry equals the CLI on the
    source: `Invalid` with the same failure codes, in order

- **AC2 — a rotated and scaled upload**
  - Given `truepic-20230212-camera.jpg` uploaded that way, with `original`
    and `scaled` copies, and finalized (so `original_image` names the
    rotated copy)
  - When the check has run
  - Then the kept path is the upload and the entry equals the CLI on the
    source

- **AC3 — the browser route without rotation is unchanged**
  - Given the Lightroom church JPEG uploaded that way with only `scaled`
  - Then the entry is `Valid`, as today

- **AC4 — an edit still moves the kept path**
  - Given the image of AC1
  - When it is rotated with `wp_save_image()` (as SPEC-014 AC1)
  - Then the entry is the CLI's verdict on the edited file

- **AC5 — a numbered copy without its upload** *(error path)*
  - Given a kept path `x-scaled-1.jpg` whose stripped name `x.jpg` does not
    exist, or is not a JPEG, PNG or WebP
  - When the check runs
  - Then it checks the kept file as today (no other file is guessed)

- **AC6 — an image stored wrongly before this version**
  - Given the attachment of AC2 with its kept path set to the `-scaled-1`
    copy and entry `none`, as the old code left it
  - When `wp tracefern check <id>` runs
  - Then the entry equals the CLI on the upload, and the kept path is the
    upload

- **AC7 — the name rule** (unit): `a-rotated-1.jpg` → `a.jpg`,
  `a-scaled-1.jpg` → `a.jpg`, `a-rotated-1-scaled-2.jpg` → `a.jpg`;
  unchanged: `a-scaled.jpg`, `a-rotated.jpg` (WordPress's own, handled by
  `shownFile()`), `a-1.jpg`, `a-150x150-1.jpg`

## References

- WordPress 7.1.2 (read): the REST attachments controller's
  `sideload_item()`, `finalize_item()` (metadata update, then the
  `_wp_sideloaded_file` cleanup) and `filter_wp_unique_filename()`;
  `upload-media.js` (the `original` and `scaled` sideloads);
  [`rest_request_before_callbacks`](https://developer.wordpress.org/reference/hooks/rest_request_before_callbacks/);
  `wp_save_image()`, `wp_restore_image()`.
- Oracle: `vendor/bin/c2pa-verify <file>` (verifier v0.2.6) on the source
  fixture; the verdicts and codes in `notes/exif-rotation.md`.
- Measured: `notes/exif-rotation.md` (Chrome 153, the real browser
  route); the `_wp_sideloaded_file` rows left after finalize.
- Reasoned: that the REST replay in the tests matches what the browser
  sends; to be checked once by hand on the browser route after the fix.

## API sketch

Illustrative only.

```php
final class UploadHook
{
    /** Set while WordPress serves the finalize route (SPEC-029). */
    private static bool $finalizing = false;

    /** `a-rotated-1-scaled-2` → `a`; other names unchanged (SPEC-029 AC7). */
    public static function withoutBrowserCopySuffix(string $name): string;
}
```

## Open questions

- Whether to report core's `original_image` for a rotated and scaled
  upload on WordPress Trac. Non-blocker; not code.
- The changelog advice with `--state=none` rechecks every image without
  credentials, not only the affected ones. Acceptable, or a narrower
  option? Non-blocker.

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
