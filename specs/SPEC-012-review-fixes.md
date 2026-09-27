# SPEC-012: Review fixes: settings off the front end, direct-access guards

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A read-through of the whole plugin as a wordpress.org reviewer would do it
(2026-09-27) found two things to change.

1. **The custom trust settings are loaded on every request.**
   `SettingsPage::registerSettings()` runs on `init`, so on every front-end
   request too, and calls `get_option('provemark_c2pa_custom_trust')` to
   make sure the option exists with autoload off (SPEC-004). That option
   can hold about 70 KB. Measured in the test environment on 2026-09-27:
   the option's autoload is `off` and it is not in `wp_load_alloptions()`,
   yet after `init` on a request outside the admin it is in the object
   cache, i.e. it was read: one extra query and the whole value on every
   page view. The Settings API is meant for `admin_init`
   ([Settings API](https://developer.wordpress.org/plugins/settings/settings-api/),
   `register_setting()`), and `wp-admin/options.php`, which saves the
   settings form, runs `admin_init` before it saves (read in core 7.1.2).

2. **The class files have no direct-access guard.** The main file checks
   `ABSPATH` and `uninstall.php` checks `WP_UNINSTALL_PLUGIN`; the 11 files
   in `src/` do not. Measured on 2026-09-27: requesting
   `src/Display.php`, `src/MediaSort.php` and `src/Checker.php` directly
   gives HTTP 200 and an empty body, so nothing leaks today; the files only
   declare classes. Plugin Check 2.1.0 reports nothing. Reasoned: the
   wordpress.org review team asks for the guard in every PHP file as a
   matter of course, and adding it costs one line per file.

## Scope

**In scope**

- `SettingsPage::registerSettings()` on `admin_init` instead of `init`,
  with the `add_option(..., autoload off)` guard it holds.
- `if (! defined('ABSPATH')) { exit; }` after the `namespace` line of each
  file in `src/`.
- The unit-test bootstrap defines `ABSPATH` (to a path under the system
  temp directory) when WordPress is not loaded, so unit tests can load the
  classes; nothing else about the unit tests changes.
- SPEC-004 AC5's test registers the setting the way `options.php` does
  (the plugin's `admin_init` callback) before `update_option`; the
  criterion itself is unchanged.
- `notes/wporg-review.md`: the points left as they are (stylesheet on
  every admin screen, `@fopen`, two small non-autoloaded flags, no size
  limit on custom settings), each with its reason.

**Out of scope** (each needs its own spec before it may be built)

- Guards in the bundled verifier (a third-party library; changing it is
  out of scope for the plugin, SPEC-006).
- Autoloading `provemark_c2pa_index_done` or `provemark_c2pa_trust_failed`.
- A size limit on custom trust settings.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-012')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — the custom settings are not read outside the admin**
  - Given custom trust settings saved (a non-empty value, autoload off)
  - When WordPress loads for a request outside the admin (WP-CLI, which
    runs `init` but not `admin_init`)
  - Then `provemark_c2pa_custom_trust` is not in the object cache, and the
    plugin's settings are registered on `admin_init`, not on `init`

- **AC2 — the settings form still saves**
  - Given `admin_init` has run, as `options.php` runs it
  - When valid custom settings are saved, and then text that is not trust
    settings *(error path)*
  - Then the valid settings are stored with autoload off, and the invalid
    text is refused with a settings error and the previous value kept
    (SPEC-004 AC5, unchanged)

- **AC3 — every file in `src/` refuses direct access**
  - Given each PHP file in `src/`
  - When its tokens are read
  - Then the first statement after `namespace` is
    `if (! defined('ABSPATH')) { exit; }`

- **AC4 — an upload still works outside the admin**
  - Given the plugin active and default settings
  - When `fixture-signed.jpg` is imported with WP-CLI (no `admin_init`)
  - Then its entry is `Valid`, equal to the CLI (SPEC-001; the trust
    settings are read from the options when a check runs, not at `init`)

## References

- WordPress: [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/),
  `register_setting()`, `get_option()`, `add_option()`,
  `wp-admin/options.php` (read in core 7.1.2).
- Oracle: the object cache and `wp_load_alloptions()` as WP-CLI reads them
  in the test environment; the plugin's own files for AC3; the verifier
  CLI for AC4.

## API sketch

```php
// SettingsPage::register()
add_action('admin_menu', $this->addPage(...));
add_action('admin_init', $this->registerSettings(...));   // was 'init'
add_action('admin_notices', $this->trustNotice(...));

// each file in src/
namespace Provemark\C2paCheck;

if (! defined('ABSPATH')) {
    exit;
}
```

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/ReviewFixesTest.php` :: AC1 | `SettingsPage::register` (`admin_init`) |
| AC2 | `tests/Integration/ReviewFixesTest.php` :: AC2; `tests/Integration/TrustTest.php` :: AC5 (SPEC-004) | `SettingsPage::registerSettings` (no registered default for the custom option), `sanitizeCustom` |
| AC3 | `tests/Unit/DirectAccessTest.php` :: AC3 | every file in `src/`; `ABSPATH` for unit tests in `tests/Pest.php` |
| AC4 | `tests/Integration/ReviewFixesTest.php` :: AC4 | `UploadHook`, `SettingsPage::trustConfig` (unchanged) |
