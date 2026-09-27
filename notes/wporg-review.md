# Notes for a wordpress.org plugin review

Written 2026-09-27 for when a reviewer asks about the bundled verifier.
Not shipped (`notes/` is export-ignored). Each section can be pasted as a
reply. Line numbers are those of verifier v0.2.4 in the build
(`vendor-prefixed/provemark/c2pa-verifier/src/`).

## What is bundled, and how it is checked

The plugin bundles one library, `provemark/c2pa-verifier` (MIT, same
author), prefixed with Strauss to `Provemark\C2paCheck\Vendor\` so it
cannot collide with another copy (SPEC-009). Only its `src/`, `LICENSE`
and `composer.json` ship; its command-line tool does not (SPEC-006
amendment 4). `composer.json` ships so the dependency can be seen.

Plugin Check 2.1.0 passes on the built zip with no errors or warnings and
nothing excluded. Plugin Check does not scan `vendor-prefixed/`, so the
release tests run the WPCS security sniffs over the shipped verifier and
compare them with a reviewed baseline (`tests/wpcs-verifier-baseline.json`):
636 findings in 6 sniffs. A new sniff or a higher count fails the build.
The sections below explain each sniff.

## `WordPress.Security.EscapeOutput.ExceptionNotEscaped` (616)

The verifier is a plain PHP library, also used outside WordPress (a
command line and JSON output). Its exceptions carry messages such as
`sprintf('SEQUENCE at offset %d has %d element(s) …')`; HTML-escaping them
at the `throw` would be wrong for those uses, and `esc_html()` does not
exist there. Escaping happens where a message is shown, and the plugin
shows almost none:

- On upload, an exception from the verifier is caught and the file's
  result is stored as state `error` with a fixed reason from a closed list
  (`src/Checker.php`; `Display::REASONS`). The exception's message is never
  stored or shown: it may hold file content.
- The one message shown is the verifier's reason for rejecting pasted
  trust settings, on the plugin's settings page, and it is escaped:
  `esc_html( $e->getMessage() )` (`src/SettingsPage.php`).
- Everything the plugin shows that comes from an image (signer names,
  status codes) goes through `Display::text()`, which replaces control and
  bidirectional characters and then applies `esc_html()`; tested with a
  manifest holding `<script>` and control characters (SPEC-002).

## `fread` (6)

`Container/StreamReader.php` (44, 69), `Container/FormatDetector.php`
(56), `Hash/BmffHashCheck.php` (102, 257, 754). The verifier reads the
uploaded image from a stream the plugin opens read-only on the original
file, to parse its C2PA data and hash its bytes. `WP_Filesystem` offers no
stream reading and the library does not depend on WordPress. It never
writes a file.

## `base64_encode` (5) and `base64_decode` (3)

Certificate and key encoding, not obfuscation:

- `Trust/Certificate.php` 266, `Cose/PublicKey.php` 47,
  `Cose/CoseSign1.php` 271: a DER certificate from the image is wrapped as
  PEM (`-----BEGIN CERTIFICATE-----` plus base64) for PHP's OpenSSL
  functions.
- `Trust/Certificate.php` 310, `Cose/PublicKey.php` 56: the reverse, a PEM
  public key back to DER, to compare keys.
- `Trust/TrustSettings.php` 175: PEM trust anchors (the bundled C2PA lists
  or an administrator's own) to DER.
- `Manifest/ManifestStore.php` 251, 257: binary values in the report's
  array form, as the C2PA reference tool `c2patool` writes them.

No base64 string in the code is decoded and executed; nothing is `eval`ed.

## `set_error_handler` (4)

`Trust/Certificate.php` (99, 221), `Cose/CoseSign1.php` (230),
`Cose/OpenSsl.php` (26). Around calls to PHP's OpenSSL functions, which
emit PHP warnings on malformed input (an image can hold anything). The
handler is set immediately before the call and restored immediately
after; a warning becomes a verification failure instead of output on the
page. It is not error logging and does not stay active.

## `json_encode` (2)

`Verifier/VerificationReport.php` (68) and `Manifest/ManifestStore.php`
(121): the library's `toJson()` methods, with `JSON_THROW_ON_ERROR`.
`wp_json_encode()` is not available to a library that does not depend on
WordPress. The plugin does not call either; it stores its own compact
result as post meta.

## Left as they are, with the reason

From a read-through as a reviewer (2026-09-27, SPEC-012 fixed the rest):

- **The stylesheet loads on every admin screen.** It styles the result in
  the media modal, which can open on any admin screen (the block editor,
  a post's featured image, widgets); it is 67 lines and versioned by its
  file time.
- **`@fopen` in `src/Checker.php`.** The file is checked with `is_file()`
  and `is_readable()` first; the `@` keeps a race (the file removed in
  between) from printing a warning into an upload response. The failure
  is then stored as state `error`, reason `unreadable`.
- **`provemark_c2pa_index_done` and `provemark_c2pa_trust_failed` are not
  autoloaded.** Both are read only in the admin (`admin_init` and
  `admin_notices`); one small query each there, none on the front end.
- **No size limit on custom trust settings.** Only a user with
  `manage_options` can save them, the verifier must accept them, and they
  are never autoloaded.
- **The verifier's files have no direct-access guard.** It is a
  third-party library, loaded only through Composer's autoloader; its
  files only declare classes.

## Licences of bundled data

See `NOTES.md` (Measured, 2026-09-27): the C2PA trust lists are CC BY 4.0,
listed by gnu.org as compatible with all versions of the GPL, with credit
in `readme.txt` and `trust/README.md`; the DigiCert root is the same
certificate WordPress ships in `wp-includes/certificates/ca-bundle.crt`.
