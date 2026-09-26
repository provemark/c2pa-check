# SPEC-008: Re-check images with WP-CLI

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A verdict is computed once, at upload (SPEC-001). Afterwards it can go out
of date: a new bundled trust list or custom trust settings (SPEC-004), a
new verifier version, images uploaded before the plugin was active. The
settings page says "settings apply to new uploads only". A site owner
needs a way to check existing images again.

Measured on 2026-09-26 (WP-CLI 2.12.0 in wp-env): for an existing large
image, `get_attached_file()` is the `-scaled` copy and
`wp_get_original_image_path()` the original, as `notes/m1-original-file.md`
found; a check takes 0–56 ms per image. Checking with `Checker` alone gave
the Pixel 10 photo `Invalid` and the OpenAI image `Valid`, because no trust
settings were passed: a re-check must take exactly the path an upload
takes (trust settings, provisional entry, index), or it gives a different
verdict than the upload would.

Decisions this spec follows (Maurice, 2026-09-26): the images are always
chosen explicitly; a `--dry-run`; a table and a summary as output.

## Scope

**In scope**

- `wp provemark-c2pa check`, registered only when WP-CLI runs, with exactly
  one selection: attachment IDs, `--all` (every JPEG, PNG and WebP
  attachment), `--unchecked` (those without an entry), or
  `--state=<list>` (comma-separated `Trusted`, `Valid`, `Invalid`,
  `error`, `none`, `unreadable`, by the SPEC-007 index).
- The check of one attachment moved into one method used by both the
  upload hook and the command; the command passes
  `wp_get_original_image_path()` where the upload passes
  `get_attached_file()`.
- `--dry-run`: the selection as a table, nothing stored.
- Output: a table of ID, file, before and after, then a summary line;
  `--format=json` for scripts; a progress bar for the table format when
  more than 20 images are checked. Images are fetched in pages of 500.

**Out of scope** (each needs its own spec before it may be built)

- A "Check again" button in the admin, or checks on a schedule.
- Other formats than JPEG, PNG and WebP.
- Running on every site of a multisite network.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-008')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — a re-check uses the current trust settings**
  - Given `fixture-signed.jpg` uploaded with the default settings (`Valid`),
    and then custom trust settings with the public c2pa-rs test root
  - When `wp provemark-c2pa check <id>` runs
  - Then its entry is `Trusted` with `trust` `custom`, equal to the CLI
    with the custom settings, and its index says `Trusted`

- **AC2 — a re-check checks the original, not the `-scaled` copy**
  - Given the Pixel 10 photo uploaded (a `-scaled` copy exists)
  - When it is re-checked
  - Then the entry equals the CLI's verdict on the original with the
    default settings (`Trusted`)

- **AC3 — the selections**
  - Given images of several states, one image without an entry, and a PDF
  - When the command runs with `--all`, `--unchecked`, `--state=Invalid,error`
    and two IDs
  - Then exactly the chosen images are checked: all JPEG/PNG/WebP; only
    the one without an entry; only the Invalid and error ones; only the two;
    never the PDF

- **AC4 — no selection, two selections, or an unknown state** *(error path)*
  - Given the command with no selection, with IDs and `--all`, or with
    `--state=Maybe`
  - When it runs
  - Then it exits 1 with a message naming the selections, and no entry
    changes

- **AC5 — IDs that cannot be checked are skipped** *(error path)*
  - Given an ID that does not exist, and the ID of the PDF, next to one
    image
  - When the command runs on the three
  - Then the image is checked, the other two are reported as skipped with
    a warning, and the command exits 0

- **AC6 — a file that is gone: `error`, and on to the next** *(error path)*
  - Given an image whose original file has been deleted, and a second image
  - When both are re-checked
  - Then the first entry is `error` / `unreadable` and the second is
    checked as usual

- **AC7 — `--dry-run` changes nothing**
  - Given a selection
  - When it runs with `--dry-run`
  - Then the table lists ID, file and current state, and no entry, index or
    option changes

- **AC8 — the output**
  - Given a selection of three images
  - When the command runs, and again with `--format=json`
  - Then the table has one row per image with before and after, the last
    line is "Checked 3: …" with the count per state; the JSON is a list of
    `{id, file, before, after}` equal to the stored entries

## References

- WP-CLI: [`WP_CLI::add_command()`](https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-add-command/),
  `WP_CLI\Utils\format_items()`, `make_progress_bar()`; WP-CLI 2.12.0 in
  wp-env (measured). WordPress:
  [`wp_get_original_image_path()`](https://developer.wordpress.org/reference/functions/wp_get_original_image_path/).
- Oracle: the verifier CLI with the settings in force; the stored entries
  and index as WP-CLI reads them; `notes/m1-original-file.md` for the
  original file.

## API sketch

```php
namespace Provemark\C2paCheck;

final class UploadHook
{
    public function onAddAttachment(int $id): void;              // get_attached_file()
    public function checkAndStore(int $id, string $path): array; // the shared path; returns the entry
}

final class Command // wp provemark-c2pa check [<id>...] [--all] [--unchecked] [--state=<states>] [--dry-run] [--format=<table|json>]
{
    public function check(array $args, array $assocArgs): void;
}
```

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed: `wp provemark-c2pa
check`.

## Traceability

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
