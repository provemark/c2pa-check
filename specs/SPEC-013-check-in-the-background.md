# SPEC-013: Check in the background, so a check that dies cannot break an upload

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-001 in part: *when* the check runs (no longer inside the upload request) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The plugin checks an image inside the upload request, in `add_attachment`
(SPEC-001). A check that runs out of memory or time ends that request with
a fatal error that cannot be caught. The review of the bundled verifier
(`NOTES.md`, "Open", point 2; the verifier's step 157) found files of a
few MB that did so in `v0.2.3`; `v0.2.4` bounds those, not the next
unknown one.

Measured on 2026-09-27 in the test environment (WordPress 7.1.2, PHP 8.3,
verifier v0.2.4), uploading the Pixel 10 photo as an administrator, with a
must-use plugin that exhausts memory while the plugin checks
(`pre_option_provemark_c2pa_digicert` during `add_attachment`):

| | no crash | crash in the check |
|---|---|---|
| REST `POST /wp/v2/media` (block editor) | HTTP 201 | HTTP 200, body "Fatal error: Allowed memory size of 134217728 bytes exhausted" |
| `async-upload.php` (Media Library) | HTTP 200 | HTTP 500, "There has been a critical error on this website." |
| attachment and file | yes | yes |
| attachment metadata, image sizes | yes, 6 sizes | **none** (no dimensions, no sizes, no `-scaled`) |
| the plugin's entry | `Trusted` | `error` / `interrupted` (SPEC-001 AC, as designed) |

So the entry fails closed, but the upload breaks: the user sees an error,
the attachment has no image sizes, and a retry fails the same way. That
breaks this plugin's rule that the upload always proceeds. Also measured:
in the REST route the check ran with a 128 MB limit (134217728 bytes in
the error), not the 256 MB of `WP_MAX_MEMORY_LIMIT`; where the 128 MB comes
from (PHP's own `memory_limit` in that container, reasoned) was not
measured.

Read in core 7.1.2: the REST route fires `rest_after_insert_attachment`
(`class-wp-rest-attachments-controller.php:610`) before it generates the
metadata (line 630), and the routes differ, so no hook in the same request
comes after the sizes on every route; a crash there would still reach the
user. `wp_cron` runs on `init` of every request
(`default-filters.php:413`); `wp_raise_memory_limit( 'admin' )` raises to
`WP_MAX_MEMORY_LIMIT` (256M here).

Decision this spec follows (Maurice, 2026-09-27): option B, the check in
a separate request through WP-Cron, with the label "Check pending" until
it has run.

## Scope

**In scope**

- On upload (`add_attachment`, JPEG/PNG/WebP): store a pending marker
  (post meta `_provemark_c2pa_pending`, the Unix time it was scheduled) and
  schedule one single WP-Cron event `provemark_c2pa_check` with the
  attachment ID. Nothing is verified in the upload request.
- The event's handler: remove the pending marker, raise the memory limit
  (`wp_raise_memory_limit( 'admin' )`), and run `checkAndStore()` as now
  (provisional `interrupted` entry first, then the result). An ID that is
  no longer a JPEG/PNG/WebP attachment is skipped.
- The display (SPEC-002): no entry and a pending marker younger than one
  hour → the badge "Check pending"; an older marker counts as not checked
  ("Not checked"), so a lost event (plugin deactivated, cron not running)
  does not say "pending" forever. An entry always wins over a marker.
- The filter (SPEC-007): a new option "Check pending"; "Not checked" no
  longer includes pending images younger than one hour. Sorting: pending
  sorts with the unchecked, after every state.
- `wp provemark-c2pa check` (SPEC-008) stays synchronous; `--unchecked`
  includes pending images; a check it makes removes the marker.
- Uninstall (SPEC-005, SPEC-011): also the pending marker, and
  `wp_unschedule_hook( 'provemark_c2pa_check' )`, on every site.
- The test helpers run the due event after an import
  (`wp cron event run provemark_c2pa_check --due-now`), so existing
  criteria keep their meaning; the release and multisite suites too.
- `readme.txt`: "checked shortly after upload, in the background"; an FAQ
  line on `DISABLE_WP_CRON` (then the site's own cron runs the checks).
  The privacy text (SPEC-010) stays true as written.

**Out of scope** (each needs its own spec before it may be built)

- A queue or Action Scheduler; retries of a check that died.
- Showing progress live in the open Media Library (it shows the result on
  the next load).
- Checking in the browser.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-013')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — an upload schedules the check and does not run it**
  - Given the plugin active
  - When `fixture-signed.jpg` is uploaded through REST as an administrator
  - Then the response is 201, the attachment has no entry, a pending
    marker, exactly one `provemark_c2pa_check` event with its ID, and its
    column shows "Check pending"

- **AC2 — the scheduled check gives the verdict the upload gave before**
  - Given an uploaded signed image and the Pixel 10 photo
  - When the due event runs
  - Then each entry equals the CLI with the default settings (SPEC-001
    AC1, SPEC-004 AC1), and the marker is gone

- **AC3 — a check that dies does not break the upload** *(error path)*
  - Given the must-use plugin that exhausts memory during the check
  - When the Pixel 10 photo is uploaded through REST and through
    `async-upload.php`
  - Then both responses succeed (201 and 200 with `"success":true`), both
    attachments have metadata with their image sizes; when the event runs
    it dies, and the entry is `error` / `interrupted` and the column says
    "Could not be checked", not "Check pending"

- **AC4 — the check runs with the raised memory limit**
  - Given a must-use plugin that records `ini_get('memory_limit')` inside
    the check
  - When the event runs from `wp-cron.php` (an HTTP request, not WP-CLI)
  - Then the recorded limit is `WP_MAX_MEMORY_LIMIT`

- **AC5 — a lost event does not say "pending" forever** *(error path)*
  - Given an image with a pending marker older than one hour, no entry and
    no scheduled event
  - When its column and details are shown, and `wp provemark-c2pa check
    --unchecked --dry-run` runs
  - Then it shows "Not checked", and the command lists it

- **AC6 — an image deleted before its check** *(error path)*
  - Given an uploaded image whose event is due
  - When the image is deleted and then the event runs
  - Then nothing is stored and no error or notice is raised

- **AC7 — filter and sort**
  - Given one pending image (marker under an hour), one unchecked image
    without a marker, and one `Valid` image
  - When the list is filtered by "Check pending", by "Not checked", and
    sorted by the column
  - Then "Check pending" gives only the first, "Not checked" only the
    second, and the sort puts `Valid` before both

- **AC8 — uninstall removes the markers and the events, on every site**
  - Given pending markers and scheduled events on two sites of a network
  - When the plugin is uninstalled
  - Then no `_provemark_c2pa_pending` row and no `provemark_c2pa_check`
    event remains on either site

## References

- WordPress: [Cron](https://developer.wordpress.org/plugins/cron/),
  `wp_schedule_single_event()`, `wp_unschedule_hook()`, `spawn_cron()`,
  `wp_raise_memory_limit()`, `media_handle_upload()`,
  `WP_REST_Attachments_Controller::create_item()` (read in core 7.1.2).
- Oracle: HTTP responses and attachment metadata as WP-CLI reads them in
  the test environment; the verifier CLI for AC2.

## API sketch

```php
final class UploadHook
{
    public const string EVENT = 'provemark_c2pa_check';
    public const string PENDING_KEY = '_provemark_c2pa_pending';
    public const int PENDING_FOR = 3600;

    public function register(): void;            // add_attachment → schedule(); EVENT → runScheduled()
    public function schedule(int $attachmentId): void;
    public function runScheduled(int $attachmentId): void;
    public function checkAndStore(int $attachmentId, string $path): array; // unchanged; clears the marker
}

// Display::headline($entry, ?int $pendingSince, int $now)
```

## Open questions

1. *Answered 2026-09-27, measured in the test environment:* the result
   appears about 2 s after the upload starts (three REST uploads of the
   Pixel 10 photo: upload 1.8 s, then one admin page load, result in the
   database at 1.95–1.97 s), because the next request's `init` spawns
   WP-Cron. On a site with no further request it waits for one. The readme
   says "usually within seconds".

Also measured while building: a check that dies ends its cron request, so
due checks after it in the same request wait for the next one (as
`wp-cron.php` runs them in one loop); every WP-CLI call and page load
spawns WP-Cron when a check is due; `wp-cron.php` called as `spawn_cron()`
calls it (lock set, `doing_wp_cron` passed) runs the check.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/BackgroundCheckTest.php` :: AC1 | `UploadHook::onAddAttachment` (marker, `wp_schedule_single_event`); `Display::headline` ("Check pending") |
| AC2 | `tests/Integration/BackgroundCheckTest.php` :: AC2 | `UploadHook::runScheduled` (`wp_get_original_image_path`), `checkAndStore` (clears the marker) |
| AC3 | `tests/Integration/BackgroundCheckTest.php` :: AC3 | `UploadHook::onAddAttachment` (no check in the upload); `checkAndStore` (provisional entry) |
| AC4 | `tests/Integration/BackgroundCheckTest.php` :: AC4 | `UploadHook::runScheduled` (`wp_raise_memory_limit('admin')`) |
| AC5 | `tests/Integration/BackgroundCheckTest.php` :: AC5 | `Display::pending` (`UploadHook::PENDING_FOR`); `MediaScreens::pendingSince`; `RecheckCommand` (`--unchecked`, unchanged) |
| AC6 | `tests/Integration/BackgroundCheckTest.php` :: AC6 | `UploadHook::runScheduled` (skips a missing attachment) |
| AC7 | `tests/Integration/SortFilterTest.php` :: SPEC-013 AC7 | `MediaSort::options`, `MediaSort::apply` |
| AC8 | `tests/Multisite/MultisiteTest.php` :: SPEC-013 AC8 | `uninstall.php` (`_provemark_c2pa_pending`, `wp_unschedule_hook`) |
