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

### 5. Icon and banner (2026-09-27)

Accepted by Maurice: `.wordpress-org/icon-*.png` and `banner-*.png` as in
commit `afd4b34`, rendered from `.wordpress-org/source/` with
`render.sh`. A photo (sun over two mountains) with a turquoise check seal;
deliberately not the C2PA "cr" mark, whose use has its own rules.

## Open, each for a later spec (2026-09-27)

From the review of the bundled verifier `v0.2.3` (the verifier's step 157,
2026-09-27). The verifier's own fixes are made there; these two are the
plugin's part.

1. **Lapsed 2026-09-27 (Maurice van Loon): verifier v0.2.4 closes it
   itself** (its SPEC-031 amendment 3: under the legacy field, a signer
   whose only EKU is Time Stamping is `signingCredential.untrusted`).
   Kept for the record: *Refuse the legacy trust format in custom
   settings.* Measured in that
   review: with the legacy `trust.trust_anchors` field, anchors serve both
   signers and timestamp authorities, and a certificate meant only for
   time stamping can sign a manifest that comes out `Trusted` (as
   `c2pa-rs` does). The plugin's own settings are kind-separated
   (`trust.anchors` with `trust_kind`, `src/TrustConfig.php`) and not
   affected; only an administrator's pasted settings can be.
   `SettingsPage::sanitizeCustom()` could refuse the legacy field with a
   settings error, and the description on the settings page say so.
2. **Do not let a check that dies break the upload.** Measured in that
   review: crafted files of a few MB make the verifier exhaust 256 MB of
   memory or run for about a minute or more. Neither can be caught; the
   plugin's provisional entry (`interrupted`) stays, as designed, but the
   request that runs `add_attachment` ends with a fatal error, so the
   upload reports a failure and WordPress generates no image sizes, which
   breaks this plugin's rule that an upload always proceeds (reasoned from
   the order in `media_handle_upload()`: `wp_insert_attachment()` fires
   `add_attachment` before `wp_generate_attachment_metadata()`; to measure
   with such a file). Options to weigh: check after the image sizes are
   made (e.g. `wp_generate_attachment_metadata` or a later hook), or in a
   separate request (WP-Cron or a loopback), showing "Not checked yet"
   until then. The verifier's own limits fix the known files; this is
   about the next unknown one.

When a fixed verifier is released: `composer update provemark/c2pa-verifier`,
then the WPCS baseline reviewed again (`tests/wpcs-verifier-baseline.json`)
and every suite, the release suite included. **Done 2026-09-27 for
v0.2.4** (SPEC-006 amendment 5). Point 2 stays open: v0.2.4 bounds the
known files, not the next unknown one.

## To measure before a spec relies on it

- Which hook gives the untouched original file (candidates: `add_attachment`
  with `get_attached_file()` / `wp_get_original_image_path()`), and whether
  that file is byte-identical to the upload, including with Gutenberg's
  client-side media processing enabled (measured by hand in a browser).
- Resolved 2026-09-27 (see Measured): whether wordpress.org accepts the
  CC BY 4.0 trust lists as bundled data.
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

- 2026-09-26, `Update URI` header: **added, then removed the same day**
  (Maurice), to stay wordpress.org-ready (decision 2). What was found, for
  when the plugin is released outside wordpress.org: WordPress sends every plugin to
  api.wordpress.org for update checks; with an `Update URI` other than its
  own wordpress.org URL "the API will not return any result"
  (make.wordpress.org/core, 2021-06-29, WordPress 5.8). Without it, a
  plugin someone later registers as `provemark-c2pa-check` on
  wordpress.org would be offered as an update to this plugin's users.
  `false` rather than a GitHub URL: `github.com` is shared, and any plugin
  hooking `update_plugins_github.com` could answer for it. wordpress.org's
  plugin team rejects the header there. Plugin Check 2.1.0 reports it as an ERROR
  (`plugin_updater_detected`: "Use of the Update URI header is not allowed
  in plugins hosted on WordPress.org"; a first search of its source missed
  this by leaving out its `vendor/`). With `--ignore-codes` Plugin Check
  prints nothing at all, not even "Checks complete"; a test that allows
  the header must therefore require that finding as the only one rather
  than ignore it.
  Reconsider the header only when a public release outside wordpress.org
  is decided.
- 2026-09-27, licences of the bundled trust files:
  - wordpress.org's [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
    guideline 1: all code, data and images in the plugin, third-party ones
    included, must be under the GPL or a GPL-compatible licence, and it
    points to gnu.org's list for which ones are.
  - `c2pa-org/conformance-public` is `CC-BY-4.0` (GitHub licence API, file
    `LICENSE` at the root, so `trust-list/` too). gnu.org's
    [licence list](https://www.gnu.org/licenses/license-list.html#ccby)
    calls CC BY 4.0 free and "compatible with all versions of the GNU
    GPL"; its caution that CC licences should not be used on software does
    not apply to certificate lists. CC BY's conditions (credit, a link to
    the licence, a note of changes) are met in `readme.txt` and
    `trust/README.md` ("included unchanged").
  - `DigiCertTrustedRootG4.crt.pem` has the same SHA-256 fingerprint as the
    "DigiCert Trusted Root G4" entry in WordPress 7.1.2's own
    `wp-includes/certificates/ca-bundle.crt` (Mozilla's root store, via
    curl): the plugin ships nothing here that core does not ship already.
  - Reasoned: the reviewer decides, but nothing here conflicts with
    guideline 1.
- 2026-09-27, `update_option()` and a registered default (WordPress
  7.1.2, `wp-includes/option.php`): when the stored value equals the
  option's registered default, `update_option()` treats the option as
  missing and calls `add_option()` without an autoload value, which gives
  `auto`, i.e. autoloaded below 150 KB. With `'default' => ''` registered
  for `provemark_c2pa_custom_trust`, the first save of custom settings
  turned its autoload from `off` to `auto` (measured with WP-CLI; caught
  by SPEC-012 AC2). Without the registered default it stays `off`,
  measured through `options.php` as an administrator (HTTP 302, autoload
  `off`).

## Temporary measures (remove when their condition is met)

- None open. (Resolved: the empty-suite flag and `src` in PHPStan in M1;
  the Plugin Check exclusion list in M5, replaced by Plugin Check on the
  built zip, SPEC-006.)
