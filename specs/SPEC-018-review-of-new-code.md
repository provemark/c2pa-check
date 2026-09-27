# SPEC-018: Fixes from the review of SPEC-014 to SPEC-017

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-014 in part: which file a verdict describes after an edit of a scaled image |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A review of the code added by SPEC-014 to SPEC-017 (2026-09-27, two
reviewers: behaviour with probes in the test environment; security and
wordpress.org rules, read-only) found no vulnerability, but:

1. **A symlinked uploads folder hides every verdict.** `uploadedFile()`
   returns a `realpath()`'d file, `relativeToUploads()` strips the
   unresolved `basedir`, so the entry records an absolute path, the display
   says "Changed since its check" for every image, and every metadata save
   (a re-save, regenerated thumbnails) deletes the verdict and queues a new
   check. Measured in the review with `upload_dir` on a symlink; confirmed
   by reading the code. Common with Deployer, Capistrano and Pantheon.
2. **An edited scaled image keeps its old verdict.** After an edit of an
   image WordPress scaled at upload, `original_image` stays in the
   metadata, so the plugin still sees the uploaded original, while the
   attachment shows a re-encoded `…-scaled-e<time>.jpg`. Measured in the
   review (threshold 100, the Pixel 10 photo rotated): still `Trusted`.
   SPEC-014 AC1 used a small image and missed it. Decision (Maurice,
   2026-09-27): **a verdict describes the file visitors see** (option A).
3. **An edit during a running check**: the running check stores its result
   and writes the old file back as the one to check, so the re-check reads
   the old file again (reasoned).
4. **Two queue runs at once** (a batch longer than the 60 s safety delay and
   WP-Cron's lock, or system cron without a lock) check the same images;
   one that dies can overwrite the other's verdict with `interrupted`
   (reasoned).
5. **The queue can reschedule itself without checking**: when `fileOf()`
   throws before the marker is removed, or when half of the time limit is
   used before the first image (reasoned).
6. **Short text with invalid UTF-8** is not stored (`update_post_meta()`
   refuses it), so `interrupted` stays: measured in the review.
7. **The PHP guard cannot run on PHP 7.4/8.0**: the main file uses
   `UploadHook::deactivate(...)`, PHP 8.1 syntax, so it does not parse
   there (read).
8. **The PHP and missing-libraries notices show to every admin-screen
   user**, subscribers included (read).
9. **Two `phpcs:ignore` lines in `Checker` give no reason on the line**
   (read).
10. **A migration that does not keep modification times, or media moved
    to external storage**, shows "Changed since its check" everywhere; fail
    closed, but not explained (reasoned).

## Scope

**In scope**

- *One rule for the file a verdict describes* (decision A), used by the
  upload, the metadata filter, the queue, the display and the command:
  the attached file, except when the attached file is the copy WordPress
  made at upload from `original_image` — exactly `<name>-scaled.<ext>` or
  `<name>-rotated.<ext>` next to it — then `original_image`.
- `relativeToUploads()` resolves both the path and the uploads folder with
  `realpath()` (falling back to them as given) and uses `/`, so a symlinked
  or non-canonical folder gives the same relative path on every side.
- `checkAndStore()` keeps its result only when the file to check is still
  the one it started with; otherwise it stores nothing and leaves the image
  pending.
- The queue claims an image by deleting its marker (`delete_post_meta()`
  returns whether it did) before anything else, skips images another run
  claimed, and checks at least one image per run before the time guard.
- `Outcome::bounded()` scrubs every text with `mb_scrub()`.
- The main file: `[UploadHook::class, 'deactivate']`; both notices only for
  users who can `activate_plugins`.
- `Checker`: the reason after `--` on both `phpcs:ignore` lines.
- `readme.txt`: the FAQ on "Changed since its check" names migrations
  that do not keep file times and media moved to external storage.

**Out of scope** (each needs its own spec before it may be built)

- Keeping a second verdict for the uploaded original next to the edited
  file's.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-018')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — a symlinked uploads folder** *(error path)*: with `upload_dir`
  pointing through a symlink, a checked upload records a relative `file`,
  shows its verdict (not "Changed since its check"), and a plain metadata
  re-save does not re-check it.
- **AC2 — an edited scaled image is checked again, on what visitors see**:
  the Pixel 10 photo (scaled by WordPress) is checked (`Trusted`), then
  rotated in the editor; the re-check's entry equals the CLI on the edited
  `…-scaled-e<time>.jpg` and records it; `wp provemark-c2pa check` gives
  the same file; "Restore original" brings back `Trusted` on the upload.
- **AC3 — an edit during a running check** *(error path)*: an edit made
  from inside the check leaves the image pending, and the next run gives
  the verdict on the edited file.
- **AC4 — overlapping runs check each image once** *(error path)*: a queue
  run started from inside another run's first check skips that image;
  every image is checked exactly once.
- **AC5 — every run checks at least one image**: with half the time limit
  used before the queue starts (a must-use plugin, over `wp-cron.php`), the
  run still checks one image.
- **AC6 — invalid UTF-8 is stored scrubbed** *(error path)*:
  `Outcome::bounded()` returns valid UTF-8 for a short invalid signer.
- **AC7 — the main file parses before PHP 8.1**: no first-class callable
  syntax in it (tokens), and both notices are behind
  `current_user_can('activate_plugins')`.
- **AC8 — every `phpcs:ignore` in `src/` states its reason** after `--`
  on the same line.
- **AC9 — the FAQ explains a false "Changed"**: the FAQ on "Changed since
  its check" names file times and external storage.

## References

- WordPress: `wp_create_image_subsizes()`,
  `_wp_image_meta_replace_original()` (`-scaled`, `-rotated`),
  `wp_save_image()`, `wp_restore_image()`, `wp_upload_dir()`,
  `delete_post_meta()` (read in core 7.1.2).
- Oracle: the verifier CLI on the file named; post meta as WP-CLI reads it.

## Open questions

None. Decided by Maurice on 2026-09-27: option A.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/NewCodeFixesTest.php` :: AC1 | `UploadHook::relativeToUploads` (as given and resolved) |
| AC2 | `tests/Integration/NewCodeFixesTest.php` :: AC2 | `UploadHook::shownFile`, `fileToCheck` (filter, display, queue, `RecheckCommand`) |
| AC3 | `tests/Integration/NewCodeFixesTest.php` :: AC3 | `UploadHook::checkAndStore` (kept only while the source is unchanged) |
| AC4 | `tests/Integration/NewCodeFixesTest.php` :: AC4 | `UploadHook::runQueue` (claim by `delete_post_meta`) |
| AC5 | `tests/Integration/NewCodeFixesTest.php` :: AC5 | `UploadHook::runQueue` (one check before the time guard) |
| AC6 | `tests/Unit/RobustnessTest.php` :: SPEC-018 AC6 | `Outcome::bounded` (`mb_scrub`) |
| AC7 | `tests/Unit/RobustnessTest.php` :: SPEC-018 AC7 | `provemark-c2pa-check.php` |
| AC8 | `tests/Unit/RobustnessTest.php` :: SPEC-018 AC8 | `Checker`, `MediaSort`, `RecheckCommand`, `UploadHook` (`phpcs:ignore … -- reason`) |
| AC9 | `tests/Unit/ReadmeTest.php` :: SPEC-018 AC9 | `readme.txt` |
