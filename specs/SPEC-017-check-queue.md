# SPEC-017: One queue for the background checks

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-013 in part: one WP-Cron event per upload, and AC5's one-hour cutoff while the queue runs |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

From the final review of 2026-09-27:

1. **One WP-Cron event per upload.** SPEC-013 schedules an event for every
   JPEG, PNG and WebP upload. A bulk import (the WordPress Importer, a
   migration, sideloading) adds thousands of events to the `cron` option,
   which is autoloaded and unserialized on every request that looks at
   cron (reasoned from `_set_cron_array()` storing all events in one
   option).
2. **Private core functions.** `UploadHook::unscheduleCheck()` reads
   `_get_cron_array()`, marked `@access private` in core, to find an
   attachment's event (SPEC-014 already replaced
   `_wp_relative_upload_path()`).
3. **"Not checked" while a long queue is still working.** With a large
   import, images further down wait longer than SPEC-013's one hour and
   show "Not checked" although their check is coming (reasoned).

The pending marker (`_provemark_c2pa_pending`) already says which images
wait; the per-image events add nothing it does not.

## Scope

**In scope**

- One event, `provemark_c2pa_check` with no arguments: the queue. An upload
  (and a re-check from SPEC-014) marks the image pending and schedules the
  queue now, unless it is due already; a later run (the safety run) is
  brought forward. *(Measured while building: scheduling only when none
  was scheduled let a new upload wait up to a minute behind a safety run.)*
- The queue's run: first it schedules a safety run 60 s later; then it
  checks the pending JPEG/PNG/WebP images, oldest marker first, at most 20,
  each as `runScheduled()` checks one now; it stops early when less than
  half of the host's time limit is left (SPEC-013 amendment 1). At the end:
  pending images left → the queue is scheduled now instead of the safety
  run; none left → no event remains. A run that dies leaves the safety run,
  so the rest is checked a minute later.
- "Check pending" (display and filter): a marker younger than one hour, or
  any marker while the queue is scheduled; otherwise "Not checked".
- No private core function in `src/`: `unscheduleCheck()` goes (a check
  clears the marker, and the queue skips images without one).
- Deactivation and uninstall unschedule the queue (`wp_unschedule_hook()`,
  unchanged).

**Out of scope** (each needs its own spec before it may be built)

- A setting for the batch size.
- Action Scheduler or another queue library.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-017')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — many uploads, one event**: five uploads leave exactly one
  `provemark_c2pa_check` event, without arguments, and five markers.
- **AC2 — the queue checks them all**: when it runs, each of the five has
  the CLI's verdict, no marker is left and no event remains.
- **AC3 — in batches of 20**: after 21 uploads, one run checks 20 and
  leaves the queue scheduled with one marker; the next run checks the last
  and leaves nothing.
- **AC4 — a run that dies loses nothing** *(error path)*: with a must-use
  plugin that exhausts memory in the first check only, the run dies, that
  image is `error` / `interrupted`, the safety run is scheduled, and when
  it runs the other images get their verdicts.
- **AC5 — a late check waits for the next run** *(error path)*: with
  `max_execution_time` 3 and checks of 2 s CPU (SPEC-013 AC10's set-up),
  the first run over HTTP checks one image and leaves the other pending,
  not `interrupted`; the next run checks it.
- **AC6 — pending while the queue runs**: a marker two hours old shows
  "Check pending" and is in the "Check pending" filter while the queue is
  scheduled, and "Not checked" when it is not (SPEC-013 AC5 otherwise
  unchanged).
- **AC7 — no private core functions**: no call in `src/` to a function
  whose name starts with `_` (tokens).

## References

- WordPress: [Cron](https://developer.wordpress.org/plugins/cron/),
  `wp_next_scheduled()`, `wp_schedule_single_event()` (an identical event
  within 10 minutes is refused), `wp_clear_scheduled_hook()`,
  `wp_unschedule_hook()` (read in core 7.1.2).
- Oracle: the verifier CLI; the `cron` option and post meta as WP-CLI
  reads them.

## API sketch

```php
final class UploadHook
{
    public const string EVENT = 'provemark_c2pa_check';   // the queue, no arguments
    public const int BATCH = 20;
    public const int SAFETY_DELAY = 60;

    public static function scheduleQueue(): void;          // when not scheduled
    public function runQueue(): void;                       // the event's handler
    public static function queueIsScheduled(): bool;        // for the display and the filter
}
```

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/QueueTest.php` :: AC1 | `UploadHook::onAddAttachment`, `scheduleQueue` |
| AC2 | `tests/Integration/QueueTest.php` :: AC2 | `UploadHook::runQueue`, `pending`, `runScheduled` |
| AC3 | `tests/Integration/QueueTest.php` :: AC3 | `UploadHook::runQueue` (`BATCH`, scheduled again while images are pending) |
| AC4 | `tests/Integration/QueueTest.php` :: AC4 | `UploadHook::runQueue` (the safety run, `SAFETY_DELAY`) |
| AC5 | `tests/Integration/BackgroundCheckTest.php` :: AC10 (groups SPEC-013, SPEC-017) | `UploadHook::runQueue` (half of `max_execution_time`) |
| AC6 | `tests/Integration/QueueTest.php` :: AC6 | `UploadHook::queueIsScheduled`; `MediaScreens::pendingSince`; `MediaSort::apply` (cutoff) |
| AC7 | `tests/Unit/RobustnessTest.php` :: SPEC-017 AC7 | `UploadHook` (`unscheduleCheck` and `_get_cron_array()` removed) |
