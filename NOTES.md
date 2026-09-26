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

## Measured

- 2026-09-26, plugin header: the handbook
  ([Header Requirements](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/))
  sets no PHP minimum for plugins; `Requires PHP` is "the minimum required
  PHP version", so `8.3` is allowed.
- 2026-09-26, `Requires at least`: `wp_get_original_image_path()` exists
  since WordPress 5.3.0 (code reference), and the current release is 7.1.2
  (`api.wordpress.org/core/version-check/1.7`). The header says `7.1`: we
  claim only what we test. Lowering it means adding that version to CI.
