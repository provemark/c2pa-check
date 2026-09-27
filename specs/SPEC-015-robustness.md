# SPEC-015: Robustness fixes from the final review

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-007 AC6 (the index backfill) and SPEC-007 AC3's "unchecked" row for non-images |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The final review of 2026-09-27 (security, wordpress.org guidelines,
behaviour) found no vulnerability, but a set of places where the plugin
breaks its own rules or could break a site. Each is one criterion here;
SPEC-014 took the largest, SPEC-016 takes packaging, SPEC-017 the cron
queue.

1. **A verdict the verifier did not give.** For a file that is not a
   JPEG, PNG, WebP or ISOBMFF file the verifier reports `general.error`,
   "unsupported file type" (`Verifier::verify()`, format `unknown`,
   `hasManifest` false). `Outcome` decides "no credential" on
   `hasManifest` alone, so it stores `none`. Measured: `spinner.gif`
   through `Checker::check()` gave `none`. Reachable by a file whose mime
   type says JPEG/PNG/WebP but whose bytes are not.
2. **The verifier's version through a global class.** `Checker::version()`
   calls `Composer\InstalledVersions`, a class any plugin's Composer
   autoloader can define first, in an older version without this plugin's
   packages; `getPrettyVersion()` then throws, outside any `try`
   (`interrupted()` too), and `runScheduled()`'s catch leaves the image
   with no entry (reasoned). The version is in this plugin's own
   `vendor/composer/installed.php` (measured: `v0.2.4`, in the repository
   and in the zip).
3. **Entities pass the scrub of untrusted text.** `Display::text()` ends in
   `esc_html()`, which does not re-encode an existing entity: measured,
   `Display::text('Reuters&#x202E;gnp.exe')` returns it unchanged, and the
   browser turns it into U+202E, the direction control SPEC-002 removes.
4. **"Not checked" on files the plugin never checks.** Measured: a PDF's
   column says "Not checked"; the "Not checked" filter lists PDFs, video,
   GIF and SVG, and the readme suggests `--unchecked` for them.
5. **PHP older than 8.3 takes the site down.** If a host lowers PHP after
   activation, Composer's `platform_check.php`, loaded by the plugin's
   autoloader, throws on every request (read in the zip).
6. **`renderSelect()` requires its arguments.** A list table that fires
   `restrict_manage_posts` without arguments makes it a fatal
   `ArgumentCountError` (reasoned).
7. **Deactivation leaves scheduled checks and markers**, so a reactivated
   plugin can say "Check pending" with no event behind it (reasoned).
8. **An image deleted during its check** leaves the plugin's meta rows
   behind (measured in the review with a must-use plugin).
9. **Text from the file has no length limit** in the stored entry (signer,
   issuer, times, the remote manifest URL, the list of codes) (reasoned).
10. **`wp provemark-c2pa check`** runs every check in one process without
    raising the memory limit (reasoned).
11. **`json_encode()`** in `TrustConfig`, where the handbook asks for
    `wp_json_encode()`.
12. **The index backfill is dead code**: it indexes entries stored before
    the index existed, which only development installs ever had; at 0.1.0
    it costs a meta query on the first admin request and an option.

## Scope

**In scope**

- `Outcome`: "no credential" (`none`) only when the verifier found no
  manifest *and* reported no failure; a file of unknown format is `error`
  with a new reason `unsupported` ("not a JPEG, PNG or WebP file"); any
  other failure without a manifest is `error` / `unreadable`.
- `Checker::version()`: read `pretty_version` of `provemark/c2pa-verifier`
  from the plugin's own `vendor/composer/installed.php`, once per request,
  `unknown` when it cannot; never throws.
- `Display::text()`: `htmlspecialchars()` with double encoding
  (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8) instead of `esc_html()`.
- Non-images: no column content and no details row for a mime type
  outside JPEG/PNG/WebP; the "Not checked" and "Check pending" filters
  list JPEG/PNG/WebP only.
- The main file returns early, with an admin notice, below PHP 8.3, before
  loading anything bundled.
- `renderSelect(mixed $postType = '', mixed $which = '')`.
- `register_deactivation_hook`: unschedule the plugin's events and delete
  its pending markers (on every site when network-deactivated).
- `checkAndStore()`: before its final writes, an attachment that no longer
  exists gets none of the plugin's meta.
- `Outcome`: each text from the file cut to 256 characters (with "…"), the
  codes to the first 50 with `codes_omitted` counting the rest, shown as
  "and N more".
- `RecheckCommand::check()`: `wp_raise_memory_limit('admin')` first.
- `TrustConfig`: `json_encode()` kept, with a `phpcs:ignore` stating why (see AC11).
- Remove the index backfill (`MediaSort::backfill`, `Index::backfill`,
  `provemark_c2pa_index_done`; uninstall still deletes that option, for
  development installs).

**Out of scope** (each needs its own spec before it may be built)

- The stylesheet on `wp_enqueue_media` instead of every admin screen.
- "Check pending" based on the scheduled event instead of the marker.
- Building the trust settings once per command run.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-015')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — an unknown format is an error, not "no credential"** *(error
  path)*: a GIF's bytes uploaded as `image/jpeg` → `error` / `unsupported`,
  shown "Could not be checked" with the reason; `fixture-unsigned.jpg`
  stays `none`.
- **AC2 — the version without the global class** *(error path)*: with a
  must-use plugin that defines its own `Composer\InstalledVersions` whose
  `getPrettyVersion()` throws, an upload's entry has `verifier` `v0.2.4`
  and its verdict.
- **AC3 — entities are shown as text** *(error path)*: an entry whose
  signer is `Reuters&#x202E;gnp.exe` shows the characters `&#x202E;`
  literally (the HTML holds `&amp;#x202E;`), no U+202E reaches the page.
- **AC4 — non-images get nothing**: a PDF has an empty column cell and no
  details row; "Not checked" and "Check pending" list no PDF.
- **AC5 — below PHP 8.3 the site stays up**: the main file's first
  statements after the `ABSPATH` guard check the PHP version against
  8.3.0 and return with a notice before any `require` (read as tokens,
  as PHP 7.4 cannot be run here). *(Written with `version_compare()`:
  PHPStan, which knows the package needs 8.3, calls `PHP_VERSION_ID <
  80300` always false.)*
- **AC6 — `restrict_manage_posts` without arguments**: no error, no
  output. *(Measured while writing the test: `do_action()` without
  arguments passes `''`, so that call was never fatal; `null` arguments
  were a `TypeError`. The test covers both.)*
- **AC7 — deactivation cleans up** : scheduled checks and pending markers
  are gone after `deactivate_plugins()`; on a network, on every site.
- **AC8 — deleted during its check** *(error path)*: an attachment deleted
  from inside the check leaves no `_provemark_c2pa_*` row.
- **AC9 — long text and many codes are capped**: a 1,000-character signer
  is stored as 256 characters ending in "…"; 120 codes are stored as 50
  with `codes_omitted` 70 and shown with "and 70 more".
- **AC10 — the command raises memory**: inside a check started by
  `wp provemark-c2pa check`, `memory_limit` is `WP_MAX_MEMORY_LIMIT`.
- **AC11 — no `json_encode()` without a reason, and no backfill left**:
  every `json_encode(` in `src/` carries a `phpcs:ignore` with its reason,
  and `src/` holds no `backfill`; SPEC-007 AC6's test is removed.
  *(Measured while building: `TrustConfig` must stay WordPress-free, as
  the tests build the verifier CLI's settings with it outside WordPress;
  with `wp_json_encode()` 21 integration tests and 2 unit tests failed on
  an undefined function. So `json_encode()` stays there, with its reason;
  Plugin Check never reported it.)*

## References

- WordPress: `register_deactivation_hook()`, `wp_json_encode()`,
  `wp_raise_memory_limit()`, `restrict_manage_posts`,
  `_wp_specialchars()` (why `esc_html()` keeps entities; read in core
  7.1.2); the plugin handbook's common issues.
- Oracle: the verifier's own report (format, statuses); rows as WP-CLI
  reads them; the rendered HTML.

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/RobustnessTest.php` :: AC1 | `Outcome::fromReport` (`unsupported` / `unreadable`), `Display` (reason words) |
| AC2 | `tests/Integration/RobustnessTest.php` :: AC2 | `Checker::version` (the plugin's own `vendor/composer/installed.php`) |
| AC3 | `tests/Integration/RobustnessTest.php` :: AC3 | `Display::text` (`htmlspecialchars`, double encoding) |
| AC4 | `tests/Integration/RobustnessTest.php` :: AC4; `tests/Integration/SortFilterTest.php` :: AC3 (`unchecked`) | `MediaScreens::isChecked`, `MediaSort::apply` (`post_mime_type`) |
| AC5 | `tests/Unit/RobustnessTest.php` :: AC5 | `provemark-c2pa-check.php` (`version_compare` before any `require`) |
| AC6 | `tests/Integration/RobustnessTest.php` :: AC6 | `MediaSort::renderSelect` (optional `mixed` parameters) |
| AC7 | `tests/Integration/RobustnessTest.php` :: AC7; `tests/Multisite/MultisiteTest.php` :: SPEC-015 AC7 | `UploadHook::deactivate`, `register_deactivation_hook` |
| AC8 | `tests/Integration/RobustnessTest.php` :: AC8 | `UploadHook::checkAndStore` (post gone: no rows) |
| AC9 | `tests/Unit/RobustnessTest.php` :: AC9; `tests/Integration/RobustnessTest.php` :: AC9 | `Outcome::bounded`; `Display` (`codes_omitted`, "and N more") |
| AC10 | `tests/Integration/RobustnessTest.php` :: AC10 (the limit lowered to 64M in `before_invoke`, as WP-CLI runs with -1) | `RecheckCommand::check` (`wp_raise_memory_limit`) |
| AC11 | `tests/Unit/RobustnessTest.php` :: AC11 | `TrustConfig` (reasoned `phpcs:ignore`); `MediaSort`, `Index` (backfill removed) |
