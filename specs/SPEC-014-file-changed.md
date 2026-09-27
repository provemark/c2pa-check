# SPEC-014: A verdict belongs to one file; a changed file is checked again

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-013 amendment 1 in part: where the path to check is kept (post meta, not the event's arguments) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A stored verdict says nothing about which file it describes. Found in the
final review of 2026-09-27 and measured in the test environment
(WordPress 7.1.2):

- **An edited image keeps its old verdict.** `fixture-signed.jpg` was
  uploaded and checked (`Valid`), then rotated with WordPress's image
  editor (`wp_save_image()`, target all). The attachment now points at a
  re-encoded `…-e<time>.jpg` without Content Credentials, the column still
  said `Valid`, and a re-check gave `none`. "Restore original" has the same
  problem the other way round (reasoned from the same code path).
- **An edit before the background check runs** made the check read the
  uploaded file, as the event carries its path (SPEC-013 amendment 1),
  while `wp provemark-c2pa check` reads the edited one: `Valid` against
  `none` for one attachment (measured in the review).
- A file replaced without WordPress knowing (a plugin or FTP overwriting it
  in place) is the same case, unannounced (reasoned).

Read in core 7.1.2: `wp_save_image()` and `wp_restore_image()` both call
`update_attached_file()` first and `wp_update_attachment_metadata()` after,
whose filter `wp_update_attachment_metadata` receives the new metadata
(`wp-includes/post.php:7173`); so every change WordPress makes to an
attachment's file passes that filter with the new file already attached.

## Scope

**In scope**

- *Which file to check* is kept in post meta, `_provemark_c2pa_source`:
  the original's path relative to the uploads folder. Set at upload (as
  `get_attached_file()` gives it in `add_attachment`), and updated in the
  `wp_update_attachment_metadata` filter whenever the attachment's current
  original (the attached file, or the metadata's `original_image` next to
  it) is another file. The background check reads it (only when it
  resolves inside the uploads folder), not the event's arguments, which go
  back to `[ID]`.
- *A changed original is checked again*: when the filter finds another
  original and the attachment already has an entry, the entry and its
  index are removed, the image is marked pending and a check is scheduled.
  Before the first check, only the kept path changes.
- *An entry records its file*: `file` (relative path), `size` and
  `modified` (the file's size and modification time when it was checked);
  `null` when the file could not be read.
- *A file changed without WordPress knowing*: when the current original's
  path, size or modification time differ from the entry's, the column and
  details show "Changed since its check" and no verdict, no signer and no
  AI label; `wp provemark-c2pa check` (unchanged: the current original)
  gives the new verdict. The sort and filter index keeps the old state until
  then (stated in the readme's FAQ).
- Uninstall also removes `_provemark_c2pa_source`.

**Out of scope** (each needs its own spec before it may be built)

- Detecting unannounced changes in the background (a scan).
- Keeping the verdict of the uploaded original next to the edited file's.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-014')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — an image edited in WordPress is checked again**
  - Given `fixture-signed.jpg` uploaded and checked (`Valid`)
  - When it is rotated with `wp_save_image()` (target all)
  - Then the entry is gone and the image is pending with one scheduled
    check; after it runs the entry is the CLI's verdict on the edited file
    and records that file

- **AC2 — restoring the original checks it again**
  - Given the image of AC1 after its re-check
  - When `wp_restore_image()` restores the original
  - Then after the scheduled check the entry is `Valid` again, equal to the
    CLI on the uploaded file

- **AC3 — an edit before the first check** *(error path)*
  - Given an upload whose check has not run
  - When it is edited, and then the check runs
  - Then the entry is the verdict on the edited file, as `wp
    provemark-c2pa check` gives it

- **AC4 — a file changed without WordPress knowing** *(error path)*
  - Given a checked `Valid` image whose file is then overwritten in place
    with other bytes (no WordPress call)
  - When its column and details are shown
  - Then they say "Changed since its check", with no verdict, no signer,
    no AI label; after `wp provemark-c2pa check <id>` they show the new
    verdict

- **AC5 — an ordinary upload is checked once, and records its file**
  - Given the Pixel 10 photo uploaded (WordPress makes `-scaled` and its
    image sizes)
  - When its check has run
  - Then exactly one check was scheduled, the entry records the original's
    relative path, size and modification time, and the column shows the
    verdict (not "Changed since its check"); SPEC-013 AC9 still holds

- **AC6 — the command records the same file**
  - Given a checked image
  - When `wp provemark-c2pa check <id>` checks it again
  - Then `file`, `size` and `modified` equal the background check's

- **AC7 — uninstall removes the kept path** (with SPEC-005/SPEC-011's
  uninstall tests: no `_provemark_c2pa_source` row on any site)

## References

- WordPress: `wp_save_image()`, `wp_restore_image()`,
  `update_attached_file()`, `wp_update_attachment_metadata()` and its
  filter, `wp_get_original_image_path()` (read in core 7.1.2).
- Oracle: the verifier CLI on the file as it is on disk; attachment meta as
  WP-CLI reads it.

## API sketch

```php
final class UploadHook
{
    public const string SOURCE_KEY = '_provemark_c2pa_source';

    public function onMetadataUpdate(mixed $data, mixed $attachmentId): mixed; // filter; returns $data unchanged
    public static function fileOf(int $attachmentId): ?string;                 // the kept source, inside uploads, else the current original
}

// Outcome entry: + 'file' => ?string, 'size' => ?int, 'modified' => ?int
// Display::headline($entry, $pendingSince, $now, bool $changed = false)
```

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/FileChangedTest.php` :: AC1 | `UploadHook::onMetadataUpdate` (filter `wp_update_attachment_metadata`), `checkAndStore` (`file`, `size`, `modified`) |
| AC2 | `tests/Integration/FileChangedTest.php` :: AC2 | `UploadHook::onMetadataUpdate` |
| AC3 | `tests/Integration/FileChangedTest.php` :: AC3 | `UploadHook::onMetadataUpdate` (the kept source), `UploadHook::fileOf` |
| AC4 | `tests/Integration/FileChangedTest.php` :: AC4 | `Display::changed`, `Display::headline` / `details` ("Changed since its check"); `MediaScreens::currentOriginal` |
| AC5 | `tests/Integration/FileChangedTest.php` :: AC5 | `UploadHook::onAddAttachment` (`SOURCE_KEY`), `onMetadataUpdate` (no change during an upload) |
| AC6 | `tests/Integration/FileChangedTest.php` :: AC6 | `UploadHook::checkAndStore` (shared by the command) |
| AC7 | `tests/Integration/FileChangedTest.php` :: AC7; `tests/Multisite/MultisiteTest.php` :: SPEC-013 AC8 (`sources`) | `uninstall.php` |
