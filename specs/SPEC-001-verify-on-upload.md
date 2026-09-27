# SPEC-001: Verify on upload and store the result

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The plugin's one job is to tell a site owner what the Content Credentials of
an uploaded image say. That needs, first, a verdict per image, computed on
the file that was uploaded and kept with the attachment. Without it there is
nothing to show (SPEC-002) and nothing to label (SPEC-003).

A C2PA signature covers the file's bytes (C2PA 2.4 §15, hard bindings), so
verifying anything but the uploaded bytes gives a wrong verdict: every image
size WordPress or the browser makes is a re-encoded copy without a
credential. `notes/m1-original-file.md` measured where the uploaded file is:
at `add_attachment`, `get_attached_file()` is the original, byte-identical,
on every upload route in WordPress 7.1, including client-side media
processing; later hooks see `-scaled`, `wp_get_original_image_path()` is
wrong in some intermediate metadata hooks, and `wp_handle_upload` fires for
every image size the browser sideloads.

Two facts about the verifier shape the mapping (measured on v0.2.3): a file
without a manifest reports `hasManifest === false` **and** `state ===
Invalid` with no statuses, so "no credential" must be decided on
`hasManifest`, never on the state; and `signatureInfo['time']` is present
only when the timestamp validated.

Decisions this spec follows: `NOTES.md` (trust list, names, tests). Trust
settings arrive in SPEC-004 (M4); until then the verifier runs without
them, so no upload reaches `Trusted`.

## Scope

**In scope**

- Verifying JPEG, PNG and WebP uploads (`image/jpeg`, `image/png`,
  `image/webp` by the attachment's mime type) in `add_attachment` at the
  default priority 10, on `get_attached_file( $id )`, with `TrustSettings`
  `null`.
- One post-meta entry per checked attachment, `_provemark_c2pa_result`,
  holding the compact result below; never the full report.
- Fail closed: a provisional `error` entry before verifying, replaced by the
  outcome; any `Throwable` becomes `error` with a reason. The upload always
  proceeds.
- Bringing `src` back into `phpstan.neon` and removing
  `--do-not-fail-on-empty-test-suite` (the temporary measures in
  `NOTES.md`).

**Out of scope** (each needs its own spec before it may be built)

- Showing the result anywhere (SPEC-002).
- The AI label and any reading of assertions (SPEC-003).
- Trust settings and the `Trusted` state in practice (SPEC-004).
- Checking attachments that already exist (a later WP-CLI command).
- Other formats (HEIC arrives already converted to JPEG by the browser, see
  the note; AVIF, video, audio).
- Fetching a remote manifest. Never.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-001')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

The stored entry is an array:

| key | value |
|---|---|
| `schema` | `1` |
| `state` | `Trusted`, `Valid`, `Invalid` (the verifier's `ValidationState` value), `none` (no manifest), or `error` (not checked) |
| `format` | the report's `format`, or `null` for `error` |
| `signer` | `['issuer' => ?string, 'common_name' => string]` from `signatureInfo`, or `null` when the report has none |
| `signed_at` | `signatureInfo['time']` when present, else `null` |
| `codes` | for `Invalid`: the `code` of every failure, as the report's `toArray()['validation_status']` and the CLI give them (active manifest first, then each ingredient's); otherwise `[]` (amendment 1) |
| `remote_manifest_url` | the report's `remoteManifestUrl` when present (never fetched), else `null` |
| `reason` | for `error`: `interrupted`, `unreadable` or `exception`; otherwise `null` |
| `verifier` | the installed `provemark/c2pa-verifier` version (`InstalledVersions::getPrettyVersion`) |
| `checked_at` | UTC time of the check, ISO 8601 |

`signer`, `signed_at`, `codes` and `remote_manifest_url` come from the file and are stored as the
verifier gives them; SPEC-002 escapes them on output. Nothing in this spec
outputs them.

- **AC1 — signed file: same verdict as the CLI**
  - Given `fixture-signed.jpg`, `fixture-signed.png` and `fixture-signed.webp`
    from the verifier's fixtures
  - When each is uploaded (`wp media import`)
  - Then its entry has `state`, `format`, `signer` and `signed_at` equal to
    what `vendor/bin/c2pa-verify <file>` reports for the same file
    (`validation_state`, `format`, and `signature_info` of the manifest named
    by `active_manifest`), and `codes` is `[]`

- **AC2 — unsigned file: `none`, not `Invalid`**
  - Given `fixture-unsigned.jpg`, `.png` and `.webp`
  - When each is uploaded
  - Then its entry has `state` `none`, `signer` `null` and `codes` `[]`,
    although the verifier's own state for these files is `Invalid`

- **AC3 — altered file: `Invalid` with the CLI's codes** *(error path)*
  - Given a copy of `fixture-signed.jpg` with one byte of image data changed
  - When it is uploaded
  - Then its entry has `state` `Invalid` and `codes` equal to the codes in
    the CLI's `validation_status` for the same altered file, in order

- **AC4 — large image: the original is checked, not `-scaled`**
  - Given `writers/adobe-20260425-lightroom-classic-church.jpg` (3280×2451,
    above the 2560 threshold)
  - When it is uploaded
  - Then WordPress has made a `-scaled` copy, and the entry equals the CLI's
    verdict on the original file (`Valid`), not on the copy

- **AC5 — other types are left alone**
  - Given an upload whose mime type is not one of the three (a PDF, a GIF)
  - When it is uploaded
  - Then no `_provemark_c2pa_result` entry exists, and the upload succeeds

- **AC6 — unreadable file: `error`, upload proceeds** *(error path)*
  - Given an attachment whose file cannot be opened when `add_attachment` runs
  - When the check runs
  - Then the entry has `state` `error`, `reason` `unreadable`, and the
    attachment exists

- **AC7 — exception: `error`, upload proceeds** *(error path)*
  - Given verification that throws any `Throwable`
  - When the check runs
  - Then the entry has `state` `error` and `reason` `exception`; the
    exception's message is not stored (it may hold paths); the attachment
    exists

- **AC8 — interrupted check leaves `error`, never nothing** *(error path)*
  - Given a check that is stopped after it started (a fatal error such as
    the memory or time limit cannot be caught)
  - When the request ends
  - Then the entry that remains has `state` `error` and `reason`
    `interrupted`, because it was written before verifying

- **AC9 — no verdict without the verifier**
  - Given any entry with `state` `Trusted`, `Valid` or `Invalid`
  - Then that state is the verifier's `ValidationState` value for that
    file, unchanged; the plugin derives only `none` (from `hasManifest`) and
    `error` (from its own failures)

- **AC10 — manifest by URL only: `none`, URL kept, nothing fetched**
  - Given `writers/adobe-20260304-photoshop-remote-manifest.jpg` (no manifest
    in the file; its XMP points to a manifest URL)
  - When it is uploaded
  - Then its entry has `state` `none` and `remote_manifest_url` equal to the
    verifier's `remoteManifestUrl`, and no request is made to that URL

- **AC11 — text from the file is stored exactly** *(amendment 2)*
  - Given a file whose text holds a backslash (the remote-manifest fixture
    with one `/` of its XMP URL replaced by `\`)
  - When it is uploaded
  - Then the stored `remote_manifest_url` equals the verifier's
    `remoteManifestUrl`, backslash included

## References

- Specification: C2PA Technical Specification 2.4, §15 (validation, status
  codes); WordPress developer reference:
  [`add_attachment`](https://developer.wordpress.org/reference/hooks/add_attachment/),
  [`get_attached_file()`](https://developer.wordpress.org/reference/functions/get_attached_file/),
  [`update_post_meta()`](https://developer.wordpress.org/reference/functions/update_post_meta/).
- Oracle: `provemark/c2pa-verifier` v0.2.3, `vendor/bin/c2pa-verify <file>`
  without settings, on the fixtures named in each criterion, copied into
  `tests/Fixtures/`; measured on WordPress 7.1.2, PHP 8.3 / 8.4 / 8.5 in
  wp-env. `notes/m1-original-file.md` for the hook and the file.
- Reasoned: that a fatal error inside `add_attachment` leaves the
  attachment row in place (the row is inserted before the hook fires); AC8
  is tested by stopping the check on purpose, not by exhausting memory.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Provemark\C2paCheck;

// Pure PHP, unit-tested: report or failure in, compact array out.
final class Outcome
{
    /** @return array<string, mixed> the stored entry */
    public static function fromReport(VerificationReport $report, string $verifierVersion, DateTimeImmutable $at): array;

    /** @param 'interrupted'|'unreadable'|'exception' $reason */
    public static function error(string $reason, string $verifierVersion, DateTimeImmutable $at): array;
}

// Pure PHP: opens the file, runs the verifier, catches Throwable.
final class Checker
{
    /** @param Closure(resource): VerificationReport $verify defaults to (new Verifier)->verify(...) */
    public function __construct(private Closure $verify) {}

    public function check(string $path): array; // an Outcome array, never throws
}

// WordPress glue, integration-tested: the hook, the mime check, the meta.
final class UploadHook
{
    public function register(): void;          // add_action('add_attachment', ...)
    public function onAddAttachment(int $id): void;
}
```

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed in the draft:

- Remote manifests: `state` `none` plus `remote_manifest_url` (AC10), so
  SPEC-002 can say the file refers to Content Credentials elsewhere, not
  checked.
- Hook priority: the default, 10; nothing measured calls for another.
- `codes` for `Valid`: not stored; `codes` is filled for `Invalid` only.

## Amendments

1. **2026-09-26, approved by Maurice van Loon.** The `codes` row said "the
   `code` of every status, in the report's order". `result->statuses` also
   holds successes and informational codes, in another order, while AC3
   compares with the CLI's `validation_status`, which holds failures only.
   The row now names that list. Found by AC3's unit test while building.

2. **2026-09-26, approved by Maurice van Loon.** `update_post_meta()`
   unslashes the value it stores, so a backslash in text from the file
   (signer, time, URL) was lost. The entry is now stored through
   `wp_slash()`, and AC11 checks it. Found by reasoning while building
   SPEC-002, then seen red with a crafted file.

3. **2026-09-27, approved by Maurice van Loon** with SPEC-013. The check no
   longer runs inside the upload request: `add_attachment` marks the image
   pending and schedules a WP-Cron event, which checks the original file
   (`wp_get_original_image_path()`, as the upload's `-scaled` copy exists by
   then). What is stored, and every criterion's verdict, is unchanged; the
   tests run the due event after an import. AC6–AC8 drive the check through
   the event's handler (`UploadHook::runScheduled`).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/OutcomeTest.php` :: AC1; `tests/Unit/CheckerTest.php` :: checks a file with the bundled verifier; `tests/Integration/UploadTest.php` :: AC1 | `src/Outcome.php` `Outcome::fromReport`; `src/Checker.php` `Checker::check`; `src/UploadHook.php` `UploadHook::onAddAttachment` |
| AC2 | `tests/Unit/OutcomeTest.php` :: AC2; `tests/Integration/UploadTest.php` :: AC2 | `Outcome::fromReport` (`hasManifest`) |
| AC3 | `tests/Unit/OutcomeTest.php` :: AC3; `tests/Integration/UploadTest.php` :: AC3 | `Outcome::failureCodes` |
| AC4 | `tests/Integration/UploadTest.php` :: AC4 | `UploadHook::onAddAttachment` (`add_attachment`, `get_attached_file`) |
| AC5 | `tests/Integration/UploadTest.php` :: AC5 | `UploadHook::MIME_TYPES` |
| AC6 | `tests/Unit/CheckerTest.php` :: AC6; `tests/Integration/UploadTest.php` :: AC6 | `Checker::check`, `Outcome::error` |
| AC7 | `tests/Unit/CheckerTest.php` :: AC7; `tests/Integration/UploadTest.php` :: AC7 | `Checker::check` (`catch Throwable`) |
| AC8 | `tests/Unit/CheckerTest.php` :: AC8; `tests/Integration/UploadTest.php` :: AC8 | `Checker::interrupted`, `UploadHook::onAddAttachment` |
| AC9 | `tests/Unit/OutcomeTest.php` :: AC9 | `Outcome::fromReport` |
| AC10 | `tests/Unit/OutcomeTest.php` :: AC10; `tests/Unit/NoNetworkTest.php` :: AC10; `tests/Integration/UploadTest.php` :: AC10 | `Outcome::fromReport` (`remote_manifest_url`) |
| AC11 | `tests/Integration/UploadTest.php` :: AC11 | `UploadHook::onAddAttachment` (`wp_slash`) |
