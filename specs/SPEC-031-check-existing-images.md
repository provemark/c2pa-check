# SPEC-031: Check existing images from the settings page

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

The plugin checks each image when it is uploaded (SPEC-001, SPEC-013).
Images that were in the Media Library before the plugin was activated
show "Not checked", and after a change of trust settings or a new
verifier every image keeps the result of its earlier check. The settings
page says so: "Settings apply to new uploads only; images already in the
Media Library keep the result of their check."

The only way to check them is `wp tracefern check` (SPEC-008), which
needs shell access and WP-CLI. Most site owners have neither (reasoned).
A site that installs the plugin today sees "Not checked" on every image
it already has, which is the first thing a new user sees.

Another plugin in the directory, added on 2026-09-29, offers a scan of
the whole library from the admin screens (by its own description); a
site owner will expect the same here (reasoned).

## Scope

**In scope**

- *A section "Existing images"* on Settings → Tracefern (capability
  `manage_options`, as the page), below the trust settings, showing the
  number of JPEG, PNG and WebP images, how many were never checked, and,
  while a run is going, how many are still to do.
- *Two buttons*, each a POST with a nonce to `admin-post.php`:
  - "Check images that were never checked" (as `wp tracefern check
    --unchecked`);
  - "Check all images again" (as `--all`), with the explanation that this
    applies the current trust settings and verifier to every image.
- *The work runs in the existing check queue* (SPEC-017), in the
  background, `BATCH` images per run: a button stores a run in one
  option, `tracefern_backfill` (`mode`, the last attachment ID done,
  totals, when it started); the queue first checks pending uploads
  (they keep priority), then the next images of the run by ascending ID.
  No marker is written per image up front, so a large library costs one
  option write, not one per image.
- *Each image is claimed before it is checked*: the stored "last ID done"
  moves past it first, so an image whose check dies (memory, time) does
  not stop the run; it keeps the provisional `error` / `interrupted`
  entry SPEC-013 writes, and the run continues with the next.
- *Progress and a way to stop*: while a run is going the section shows
  "Checking existing images: N of M done" and a "Stop" button (POST,
  nonce) that deletes the run; a finished run shows when it finished.
- *The sentence on the settings page* changes to: settings apply to new
  uploads; use "Check all images again" to apply them to images already
  in the Media Library.
- The same file choice as `wp tracefern check` (`UploadHook::fileToCheck()`),
  so a button and the command give the same verdict.
- Multisite: per site, as the settings page.
- Uninstall also removes `tracefern_backfill`.

**Out of scope** (each needs its own spec before it may be built)

- A bulk action or a per-image "Check again" link in the Media Library.
- Checking in the administrator's browser.
- Scheduling a recheck automatically (for example after the weekly
  maintenance check, SPEC-030, finds new trust lists).
- Changes to `wp tracefern check`.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-031')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — never-checked images get checked**
  - Given three JPEG/PNG/WebP attachments without an entry (as before
    activation) and one checked image
  - When "Check images that were never checked" is pressed and the queue
    runs until the run ends
  - Then the three have entries equal to `wp tracefern check <id>`'s, the
    checked one is untouched, and the run is gone

- **AC2 — all images again, with the current settings**
  - Given checked images and a trust setting changed afterwards
  - When "Check all images again" is pressed and the queue has run
  - Then every image's entry records the current trust setting
    (`trust`) and equals the command's verdict

- **AC3 — uploads keep priority**
  - Given a run with images still to do
  - When an image is uploaded
  - Then the next queue run checks the upload before the run's images

- **AC4 — progress, finish and stop**
  - Given a run of M images
  - When part of it is done
  - Then the section shows "N of M done"; when it ends it shows that it
    finished; pressing "Stop" deletes the run and no further images of it
    are checked

- **AC5 — a check that dies does not stop the run** *(error path)*
  - Given a run whose second image makes the check die (the SPEC-015 test
    hook)
  - When the queue and its safety run have run
  - Then that image has `error` / `interrupted`, and the images after it
    are checked

- **AC6 — no nonce, no permission, no run** *(error path)*
  - Given a POST to either action without a valid nonce, or by a user
    without `manage_options`
  - Then no run is stored, nothing is scheduled, and WordPress answers
    with its usual refusal

- **AC7 — a large library costs one write**: pressing a button writes one
  option and schedules the queue; no per-image meta is written before
  the queue reaches an image (counted in the test)

- **AC8 — texts escaped and translatable**: the section's strings are
  output with `esc_html__()` / `esc_attr__()`; numbers with
  `number_format_i18n()`

- **AC9 — uninstall removes the run** (with SPEC-005/SPEC-011's uninstall
  tests: no `tracefern_backfill` option on any site)

## References

- WordPress: `admin_post_{$action}`, `check_admin_referer()`,
  `current_user_can()`, `number_format_i18n()`; WP-Cron.
- This plugin: SPEC-008 (`wp tracefern check`, the oracle for every
  verdict here), SPEC-013/SPEC-017 (the queue and its safety run),
  SPEC-015 (the hook that makes a check die in tests), SPEC-029
  (`fileToCheck()`).
- Reasoned: that most site owners have no WP-CLI; that users expect a
  library scan in the admin screens.

## API sketch

Illustrative only.

```php
final class Backfill
{
    public const string OPTION = 'tracefern_backfill';

    /** Starts a run: 'unchecked' or 'all'. */
    public static function start(string $mode): void;

    /** The next image IDs of the run, claimed (the cursor moves past them). @return list<int> */
    public static function claim(int $limit): array;

    /** @return array{mode: string, done: int, total: int, started: int}|null */
    public static function progress(): ?array;

    public static function stop(): void;
}
```

`UploadHook::runQueue()` takes pending uploads first, then
`Backfill::claim()` for the rest of the batch.

## Open questions

- The two buttons, or only "never checked" first? Proposal: both; "all
  again" is the only way to apply new trust settings without WP-CLI.
  Non-blocker.
- Should "Check all images again" ask for confirmation (a JavaScript
  `confirm()`), since on a large library it runs for a long time?
  Proposal: no; it can be stopped, and nothing is lost. Non-blocker.

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
| AC9                  | —                           | —                    |
