=== Provemark C2PA Check ===
Tags: c2pa, content credentials, media, provenance, ai
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Checks the Content Credentials (C2PA) of uploaded images and shows the result in the Media Library.

== Description ==

Provemark C2PA Check reads the Content Credentials (C2PA manifest) of every
image uploaded to the Media Library and verifies them with
[provemark/c2pa-verifier](https://github.com/provemark/c2pa-verifier), a
verifier written in PHP.

It checks JPEG, PNG and WebP uploads on the original file, stores the
verdict (trusted, intact, does not verify, no Content Credentials, or could
not be checked) and shows it in a Media Library column and in the
attachment details, with the signer, and "AI-generated (signed)" when a
verifying manifest says so. Not released yet.

What it does not do:

* It never signs anything and holds no keys.
* It never blocks an upload; a file that cannot be checked is marked as such.
* It makes no network calls. A manifest that is only referenced by URL is
  not fetched.

== Trust lists ==

By default the plugin trusts the certificate authorities on the C2PA
conformance programme's trust lists, bundled with the plugin (see
`trust/README.md` for the date and source), and, optionally, the DigiCert
Trusted Root G4 for timestamps. Settings → C2PA Check shows the date of the
bundled copy and lets an administrator replace the lists with their own
trust settings. The plugin never downloads a list.

The C2PA trust lists are © the Coalition for Content Provenance and
Authenticity (C2PA), from https://github.com/c2pa-org/conformance-public,
licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/).

== Frequently Asked Questions ==

= Does it change my images? =

No. It only reads the original file.

= Why PHP 8.3? =

The verifier it uses requires PHP 8.3 or later.

== Changelog ==

= 0.1.0 =

* An empty plugin that activates and loads the verifier.
