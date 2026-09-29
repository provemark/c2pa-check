# SPEC-028: Say when a file changed after upload

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

Image optimizers rewrite the original file on the server, and the plugin
cannot tell that apart from a file changed after signing. Measured on
2026-09-29 with EWWW Image Optimizer 8.8.0 at its defaults
(`notes/image-optimizer.md`, `NOTES.md` "Open" point 5):

- Its upload resize replaces a large original in the `wp_handle_upload`
  filter, before the attachment exists. The plugin checks what is left
  and says "No Content Credentials" about a signed upload.
- Its lossless PNG optimization, in the background, keeps the manifest and
  rewrites the bytes it covers. Checked again, a Trusted OpenAI image
  says "Does not verify" (`assertion.dataHash.mismatch`).

Both verdicts are the verifier's own for the file on disk, so they stay.
What is missing is one fact only the upload request can see: whether the
file on disk is still the file that was uploaded. Without it, a site owner
reads "Does not verify" as tampering by whoever signed or sent the image,
when the change was made on their own server.

WordPress: `_wp_handle_upload()` ends with the `wp_handle_upload` filter,
for uploads and sideloads alike, with the file already in the uploads
folder and no attachment yet ([`wp_handle_upload`](https://developer.wordpress.org/reference/hooks/wp_handle_upload/));
`add_attachment` follows with the attachment ID
([`add_attachment`](https://developer.wordpress.org/reference/hooks/add_attachment/)).
`notes/m1-original-file.md` measured that the file at `add_attachment` is
the upload, byte-identical, on every route with no optimizer; it also
measured that `wp_handle_upload` fires for every image size the browser
sideloads with client-side media processing on.

## Scope

**In scope**

- *A fingerprint of the upload*: in the `wp_handle_upload` filter, at the
  earliest priority (before any other plugin on that filter), the SHA-256
  and size of a JPEG, PNG or WebP file, kept for the request by its path.
  At `add_attachment`, when the attached file has that path, they are
  stored in post meta `_tracefern_upload` (`sha256`, `size`). Image sizes
  that pass the filter create no attachment and store nothing.
- *The check compares*: when the file a check reads is at the uploaded
  path and its SHA-256 differs from `_tracefern_upload`, the entry
  records `changed_after_upload: true`. The state stays the verifier's.
- *The display says so*: next to the verdict, "Changed after upload: this
  is not the file that was uploaded" in the column and the details, and in
  the details one sentence that an image optimizer or another plugin can
  do this. "Does not verify" and "No Content Credentials" are still shown.
- *The readme*: the FAQ entry "Why does a genuine photo say "Does not
  verify"?" names the new line.
- Uninstall also removes `_tracefern_upload`.

**Out of scope** (each needs its own spec before it may be built)

- Checking the upload itself before an optimizer runs (the option SPEC-013
  moved away from).
- Saying whether the upload carried Content Credentials; that needs a
  check or a cheap "has a manifest" probe, which is the verifier's to offer.
- A field in the `tracefern_verdict` filter (SPEC-026).
- Changes made in `wp_handle_upload_prefilter`, before the file is moved.
- Images uploaded before this version (no fingerprint: shown as today).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-028')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — an ordinary upload is fingerprinted and shown as today**
  - Given `fixture-signed.jpg` imported with `wp media import`
  - When its check has run
  - Then `_tracefern_upload` holds the source's SHA-256 and size, the entry
    has no `changed_after_upload`, and the column shows `Valid` with no
    extra line

- **AC2 — a file rewritten in place after the check**
  - Given `fixture-signed.png` checked (`Valid`), then its file overwritten
    in place with the same image re-encoded, manifest kept (a test helper,
    no optimizer plugin)
  - When `wp tracefern check <id>` runs
  - Then the entry is the CLI's verdict on that file (`Invalid`,
    `assertion.dataHash.mismatch`) with `changed_after_upload: true`, and
    the column and details show "Does not verify" and "Changed after upload"

- **AC3 — a file replaced during the upload**
  - Given a must-use test plugin that, on `wp_handle_upload` at priority
    10, replaces the file with `fixture-unsigned.jpg`
  - When `fixture-signed.jpg` is imported and checked
  - Then `_tracefern_upload` is the signed file's fingerprint, the entry is
    `none` with `changed_after_upload: true`, and the column shows "No
    Content Credentials" and "Changed after upload"

- **AC4 — no fingerprint when the file cannot be read** *(error path)*
  - Given an upload whose file is unreadable in `wp_handle_upload`, or a
    `wp_handle_upload` array without a file
  - When it is imported
  - Then the upload proceeds, no `_tracefern_upload` is stored, nothing is
    logged as an error state, and the check behaves as today

- **AC5 — an edit in WordPress is not "changed after upload"**
  - Given the image of AC1, rotated with `wp_save_image()` (SPEC-014 AC1)
  - When its re-check has run
  - Then the entry, for the edited file at another path, has no
    `changed_after_upload`

- **AC6 — image sizes do not overwrite the fingerprint**
  - Given an upload whose image sizes also pass `wp_handle_upload` (as the
    browser's sideloads do with client-side processing)
  - When the sizes are handled
  - Then `_tracefern_upload` still holds the original's fingerprint

- **AC7 — the new line is escaped text** — the line and the sentence are
  translatable strings output with `esc_html__()`; no file content is in
  them.

- **AC8 — uninstall removes the fingerprint** (with SPEC-005/SPEC-011's
  uninstall tests: no `_tracefern_upload` row on any site)

## References

- WordPress: `_wp_handle_upload()` and the `wp_handle_upload` filter,
  `add_attachment`, `wp_save_image()` (read in core 7.1.2).
- Oracle: `vendor/bin/c2pa-verify <file>` on the file on disk (verifier
  v0.2.6); `sha256sum` on the source fixture; attachment meta as WP-CLI
  reads it. Measured context: `notes/image-optimizer.md`.
- Reasoned: that the earliest priority on `wp_handle_upload` runs before
  optimizers that resize there (EWWW registers at the default 10,
  `classes/class-plugin.php`); other optimizers not read.

## API sketch

Illustrative only.

```php
final class UploadHook
{
    /** The uploaded file's SHA-256 and size, taken before other plugins change it (SPEC-028). */
    public const string UPLOAD_KEY = '_tracefern_upload';

    /** @var array<string, array{sha256: string, size: int}> by absolute path, this request only */
    private static array $fingerprints = [];

    /** @param array{file?: string, type?: string} $upload */
    public static function fingerprint(array $upload): array; // returns $upload unchanged
}
```

`add_filter('wp_handle_upload', [UploadHook::class, 'fingerprint'], PHP_INT_MIN)`.

## Open questions

- The wording, in the column and in the details (proposal above). Blocker.
- The readme is 10,205 bytes against a 10,240 limit; the FAQ line needs
  room. Move the changelog entries before 0.1.3 out of `readme.txt`
  (where to?), or shorten another answer. Blocker.
- Hashing a very large upload in the upload request costs time (about
  20 ms for the 5.7 MB Pixel file, reasoned from SHA-256 speed, not
  measured). A size above which no fingerprint is taken? Non-blocker.

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
