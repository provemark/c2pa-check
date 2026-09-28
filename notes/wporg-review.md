# Notes for a wordpress.org plugin review

Written 2026-09-27 for when a reviewer asks about the bundled verifier.
Not shipped (`notes/` is export-ignored). Each section can be pasted as a
reply. Line numbers are those of verifier v0.2.5 in the build
(`vendor-prefixed/provemark/c2pa-verifier/src/`).

## Ready to paste: the bundled library, in one paragraph

Prepared 2026-09-28 for a reviewer who asks about the WPCS findings in
`vendor-prefixed/`. Send only when asked; the sections below give the
detail per finding.

> About the bundled library: vendor-prefixed/ holds
> provemark/c2pa-verifier (MIT, written by me), a plain PHP library that
> is also used outside WordPress, prefixed with Strauss so it cannot
> collide with another copy. WPCS reports ExceptionNotEscaped on its throw
> statements, but the plugin never outputs those messages: an exception
> during a check is caught and stored as a fixed reason from a closed
> list, and the only message shown (why pasted trust settings were
> rejected, on the admin-only settings page) goes through esc_html(). Its
> other findings are fread() on the read-only image stream,
> base64_encode()/base64_decode() for PEM and DER certificates,
> set_error_handler() around OpenSSL calls (restored right after each
> call), and json_encode() in toJson() methods the plugin does not call. I
> can give the file and line of each finding if that helps.

## What is bundled, and how it is checked

The plugin bundles one library, `provemark/c2pa-verifier` (MIT, same
author), prefixed with Strauss to `Tracefern\ImageCheck\Vendor\` so it
cannot collide with another copy (SPEC-009). Only its `src/`, `LICENSE`
and `composer.json` ship; its command-line tool does not (SPEC-006
amendment 4). `composer.json` ships so the dependency can be seen.

Plugin Check 2.1.0 passes on the built zip with no errors or warnings and
nothing excluded. Plugin Check does not scan `vendor-prefixed/`, so the
release tests run the WPCS security sniffs over the shipped verifier and
compare them with a reviewed baseline (`tests/wpcs-verifier-baseline.json`):
641 findings in 6 sniffs. A new sniff or a higher count fails the build.
The sections below explain each sniff.

## `WordPress.Security.EscapeOutput.ExceptionNotEscaped` (621)

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

- `Trust/Certificate.php` 270, `Cose/PublicKey.php` 47,
  `Cose/CoseSign1.php` 299: a DER certificate from the image is wrapped as
  PEM (`-----BEGIN CERTIFICATE-----` plus base64) for PHP's OpenSSL
  functions.
- `Trust/Certificate.php` 314, `Cose/PublicKey.php` 56: the reverse, a PEM
  public key back to DER, to compare keys.
- `Trust/TrustSettings.php` 175: PEM trust anchors (the bundled C2PA lists
  or an administrator's own) to DER.
- `Manifest/ManifestStore.php` 251, 257: binary values in the report's
  array form, as the C2PA reference tool `c2patool` writes them.

No base64 string in the code is decoded and executed; nothing is `eval`ed.

## `set_error_handler` (4)

`Trust/Certificate.php` (102, 225), `Cose/CoseSign1.php` (258),
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
- **`tracefern_trust_failed` is not autoloaded.** It is read only in the
  admin (`admin_notices`, on four screens since SPEC-022); one small query
  there, none on the front end.
- **No size limit on custom trust settings.** Only a user with
  `manage_options` can save them, the verifier must accept them, and they
  are never autoloaded.
- **The verifier's files have no direct-access guard.** It is a separate
  library, loaded only through Composer's autoloader; its
  files only declare classes.

## The `.pem` files in `trust/`

`trust/C2PA-TRUST-LIST.pem`, `trust/C2PA-TSA-TRUST-LIST.pem` and
`trust/DigiCertTrustedRootG4.crt.pem` are plain-text X.509 certificates
(`-----BEGIN CERTIFICATE-----` blocks and nothing else; no keys). They are
the default trust anchors the verifier checks signers and timestamps
against, read with `file_get_contents()` by `src/TrustConfig.php` and never
executed or served. Their source, date and licence are in
`trust/README.md` and `readme.txt`; the DigiCert root is the same
certificate WordPress ships in `wp-includes/certificates/ca-bundle.crt`.

## The name and slug

"Tracefern" is a coined name for this plugin; there is no company or
project behind it, so there is no owner to verify. "C2PA" comes after
"for": the plugin checks C2PA Content Credentials; it is not made or
endorsed by the Coalition for Content Provenance and Authenticity.

The first submission was "Provemark C2PA Check" (`provemark-c2pa-check`);
the first review (2026-09-28) read "ProveMark" as someone else's brand,
and Blockchain Commons does publish "Provenance Marks" under
`/provemark/`. Renamed in SPEC-020; the new slug
`tracefern-image-check-for-c2pa` must be requested in the reply.

## Hook callbacks

The plugin registers its hooks with first-class callables
(`$this->onAddAttachment(...)`), so another plugin cannot remove one with
`remove_action()` by name. That is deliberate: the classes hold no global
state and the plugin adds no global functions. A site that wants the
plugin off a screen can deactivate it, or filter the Media Library column
(`manage_media_columns`) and attachment fields
(`attachment_fields_to_edit`) at a later priority.

## Licences of bundled data

See `NOTES.md` (Measured, 2026-09-27): the C2PA trust lists are CC BY 4.0,
listed by gnu.org as compatible with all versions of the GPL, with credit
in `readme.txt` and `trust/README.md`; the DigiCert root is the same
certificate WordPress ships in `wp-includes/certificates/ca-bundle.crt`.
