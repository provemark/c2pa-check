# Notes

Decisions and open questions for Tracefern Image Check for C2PA. Each entry says what
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
| name | Tracefern Image Check for C2PA |
| slug, text domain | `tracefern-image-check-for-c2pa` |
| namespace | `Tracefern\ImageCheck` |
| prefix | `tracefern_` |
| post meta | `_tracefern_result` (the leading underscore keeps it out of the Custom Fields panel) |

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
2. **Resolved 2026-09-27 by SPEC-013** (the check runs in the background
   through WP-Cron; measured: a check that dies no longer breaks the upload
   on the REST or the Media Library route). Kept for the record:
   *Do not let a check that dies break the upload.* Measured in that
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

3. **Media moved to external storage right after upload** (added
   2026-09-27). Offload plugins (for example WP Offload Media) can delete
   the local original once it is copied to S3 or similar. The background
   check then finds no file and stores `error` / `unreadable` ("Could not
   be checked"): fail closed, but the plugin is useless on such a site.
   Reasoned from `UploadHook::fileOf()` and `Checker::check()`; not tested
   with such a plugin. Options to weigh: check before the file leaves (in
   the upload request after all, at the cost SPEC-013 removed; or on the
   offload plugin's own hook), read the file through a stream wrapper the
   offload plugin provides, or say in the readme that such sites are not
   supported. The FAQ covers only the "Changed since its check" side.
4. **Mostly closed by verifier v0.2.5 (2026-09-27).** Measured there (its
   steps 164 and 168): name constraints, unknown critical extensions and
   the chain's placement gave wrong `Trusted` and are fixed; SHA-1 and MD5
   in the path are now refused; policy constraints agree with `c2patool`.
   Still open there, reasoned low: the stapled-OCSP binding and
   ESSCertID(v2). Kept for the record: *Lower findings in the bundled
   verifier, still open in v0.2.4*
   (added 2026-09-27; from the verifier's step 157, all reasoned there):
   the chain walk does not enforce nameConstraints, policy constraints,
   unknown critical extensions or intermediates' signature algorithms (a
   name-constrained subordinate CA could issue a leaf showing any
   organisation name and come out `Trusted`); `x5chain` is accepted from
   the unprotected COSE header; a stapled OCSP response is not bound to
   the leaf's verified issuer; the timestamp's ESSCertID(v2) is not
   compared with the TSA certificate. For a verifier release; the plugin
   takes it with `composer update` as below. To confirm against v0.2.4's
   code and `c2patool` before a spec there.
5. **Image optimizers** (added 2026-09-29, measured with EWWW Image
   Optimizer 8.8.0 at its defaults, `notes/image-optimizer.md`). Its
   upload resize replaces a large original before the plugin sees it
   (stored `none` for a signed upload); its lossless PNG optimization,
   in the background after the check, keeps the manifest and breaks its
   hash (shown "Changed since its check"; checked again, "Does not
   verify"). Options to weigh are in the note: a readme warning, a hash
   of the upload taken early, or an early check. **Done 2026-09-29**: the
   readme warning, and SPEC-028 (the hash; "Changed after upload").
6. **A rotated image on the block editor route is checked on a copy**
   (added 2026-09-29, measured, `notes/exif-rotation.md`). With
   client-side processing, a signed JPEG with EXIF orientation 6 is
   stored as `none`: the kept path moves to the browser's `-rotated-1`
   or `-scaled-1` copy when its sideload updates the metadata. WP-CLI and
   REST uploads, and the block editor without rotation, are correct.
   **Fixed 2026-09-29 by SPEC-029** (with amendments 1 and 2); images
   stored wrongly before are not repaired automatically. The core side is
   reported as WordPress/gutenberg#83757 (`original_image` names the
   rotated copy).

When a fixed verifier is released: `composer update provemark/c2pa-verifier`,
then the WPCS baseline reviewed again (`tests/wpcs-verifier-baseline.json`)
and every suite, the release suite included. **Done 2026-09-27 for
v0.2.4** (SPEC-006 amendment 5), **and for v0.2.5** (SPEC-006 amendment
7; SPEC-015 amendment 1 made AC2 follow the bundled version), **and for
v0.2.6 on 2026-09-28** (SPEC-006 amendment 8). Point 2 was resolved by SPEC-013: v0.2.4
bounds the known files, the background check the next unknown one.

## To measure before a spec relies on it

- Resolved 2026-09-26 (`notes/m1-original-file.md`): which hook gives the
  untouched original file, and whether it is byte-identical to the upload,
  including with client-side media processing enabled. The `-rotated`
  case was measured on 2026-09-29 (`notes/exif-rotation.md`).
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
  Since 2026-09-27: `Contributors: mauricevanloon` (profile checked,
  HTTP 200).

- 2026-09-26, `Update URI` header: **added, then removed the same day**
  (Maurice), to stay wordpress.org-ready (decision 2). What was found, for
  when the plugin is released outside wordpress.org: WordPress sends every plugin to
  api.wordpress.org for update checks; with an `Update URI` other than its
  own wordpress.org URL "the API will not return any result"
  (make.wordpress.org/core, 2021-06-29, WordPress 5.8). Without it, a
  plugin someone later registers as `tracefern-image-check-for-c2pa` on
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
  for `tracefern_custom_trust`, the first save of custom settings
  turned its autoload from `off` to `auto` (measured with WP-CLI; caught
  by SPEC-012 AC2). Without the registered default it stays `off`,
  measured through `options.php` as an administrator (HTTP 302, autoload
  `off`).

## Temporary measures (remove when their condition is met)

- None open. (Resolved: the empty-suite flag and `src` in PHPStan in M1;
  the Plugin Check exclusion list in M5, replaced by Plugin Check on the
  built zip, SPEC-006.)
