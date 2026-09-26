# Notes

Decisions and open questions for Provemark C2PA Check. Each entry says what
is measured (a command was run) and what is reasoned (read or concluded).

## Decisions (2026-09-26)

### 1. Trust list

Without trust settings the best verdict any file gets is `Valid` ("signer
unknown"), which tells a site owner little.

- The plugin bundles `C2PA-TRUST-LIST.pem` (trust kind `manifest`) and
  `C2PA-TSA-TRUST-LIST.pem` (trust kind `tsa`) from
  [c2pa-org/conformance-public](https://github.com/c2pa-org/conformance-public/tree/main/trust-list)
  (CC BY 4.0), and shows the date of the copy on the settings page.
- An admin can paste a trust-settings JSON (the verifier's format). It
  **replaces** the bundled lists; nothing is merged.
- DigiCert Trusted Root G4 is a separate `tsa` anchor behind its own
  option, **on by default**. Adobe Firefly and Microsoft Bing timestamp
  under that root; without it, every such file whose signer has expired is
  `Invalid` (the verifier's `docs/trust-settings.md`, measured there on
  2026-09-25). Turning it on means accepting the time DigiCert's timestamp
  authorities put on a signature.
- The settings page warns when the bundled copy is older than about six
  months. The plugin never fetches a list itself (no network calls).
- Maintenance: a plugin release for each update of the C2PA lists.

Why bundle, although the verifier argues against it: a site owner will not
build a settings file, so without a bundled list the plugin says almost
nothing useful. The risk of a stale list is a CA the C2PA has removed that
is still trusted here; hence the visible date and the warning.

### 2. Distribution

Built as if for the wordpress.org plugin directory from M0 (`readme.txt`,
slug equals text domain, Plugin Check in CI), released on GitHub first
(private; public only with Maurice's go). Whether to submit to
wordpress.org is decided after M5.

### 3. Name, slug, namespace

| what | value |
|---|---|
| name | Provemark C2PA Check |
| slug, text domain | `provemark-c2pa-check` |
| namespace | `Provemark\C2paCheck` |
| prefix | `provemark_c2pa_` |
| post meta | `_provemark_c2pa_result` (the leading underscore keeps it out of the Custom Fields panel) |

The brand comes first because "C2PA" and "Content Credentials" belong to
others (wordpress.org guideline 17; reasoned) and because
`provemark/content-credentials`, which signs, already exists. Measured
2026-09-26: the slug is not a published plugin on wordpress.org; reserved or
pending slugs are not visible through the API.

### 4. Tests

- Pest for unit tests of WordPress-free classes.
- wp-env (Docker; real PHP and OpenSSL) for integration. Integration tests
  are Pest tests that drive WordPress through WP-CLI (`wp media import`,
  `wp post meta get`) and compare with the verifier's `bin/c2pa-verify`.
- `composer check`: Pint, PHPStan at max, unit tests; runs without Docker.
  `composer test:integration` runs against wp-env, locally and in CI.

Why not WordPress Playground: it runs PHP as WebAssembly, and a verdict
under its OpenSSL does not prove what a real host gives (reasoned). Why not
`WP_UnitTestCase`: WordPress's test library and Pest 4 need different
PHPUnit versions (reasoned; not measured).

## To measure before a spec relies on it

- Which hook gives the untouched original file (candidates: `add_attachment`
  with `get_attached_file()` / `wp_get_original_image_path()`), and whether
  that file is byte-identical to the upload, including with Gutenberg's
  client-side media processing enabled (measured by hand in a browser).
- Whether wordpress.org accepts the CC BY 4.0 trust lists as bundled data.
- Resolved in M5 (SPEC-006): Plugin Check never scans `vendor/`; the
  shipped verifier is checked with the WPCS security sniffs against a
  reviewed baseline (`tests/wpcs-verifier-baseline.json`).

## Measured

- 2026-09-26, plugin header: the handbook
  ([Header Requirements](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/))
  sets no PHP minimum for plugins; `Requires PHP` is "the minimum required
  PHP version", so `8.3` is allowed.
- 2026-09-26, `Requires at least`: `wp_get_original_image_path()` exists
  since WordPress 5.3.0 (code reference), and the current release is 7.1.2
  (`api.wordpress.org/core/version-check/1.7`). The header says `7.1`: we
  claim only what we test. Lowering it means adding that version to CI.
- 2026-09-26, activation: the empty plugin activates and deactivates in
  WordPress 7.1.2 on PHP 8.3.35 (wp-env via Colima), loads the verifier, and
  without `vendor/` shows one admin notice instead of a fatal error.

- 2026-09-26, Plugin Check 2.1.0: reads the working tree, not the
  release, so it reports development files (hidden files, `AI-LOG.md`,
  `NOTES.md`); the test excludes those. It does **not** scan `vendor/`: the
  same unescaped `echo $_GET[...]` probe gave one error and four warnings in
  `src/` and nothing in `vendor/`. `License: MIT` in `readme.txt` passes.
- 2026-09-26, distribution: GitHub only for now (Maurice). No
  wordpress.org account yet, so `readme.txt` has no `Contributors` line.

- 2026-09-26, `Update URI: false` in the plugin header (decided by
  Maurice; value chosen by the assistant). WordPress sends every plugin to
  api.wordpress.org for update checks; with an `Update URI` other than its
  own wordpress.org URL "the API will not return any result"
  (make.wordpress.org/core, 2021-06-29, WordPress 5.8). Without it, a
  plugin someone later registers as `provemark-c2pa-check` on
  wordpress.org would be offered as an update to this plugin's users.
  `false` rather than a GitHub URL: `github.com` is shared, and any plugin
  hooking `update_plugins_github.com` could answer for it. **Remove the
  header before submitting to wordpress.org**: its plugin team rejects it
  there. Plugin Check 2.1.0 does not look at it (searched its source).

## Temporary measures (remove when their condition is met)

- None open. (Resolved: the empty-suite flag and `src` in PHPStan in M1;
  the Plugin Check exclusion list in M5, replaced by Plugin Check on the
  built zip, SPEC-006.)
