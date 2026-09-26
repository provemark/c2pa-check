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

This version is under construction: it activates, but does not check
anything yet.

What it does not do:

* It never signs anything and holds no keys.
* It never blocks an upload; a file that cannot be checked is marked as such.
* It makes no network calls. A manifest that is only referenced by URL is
  not fetched.

== Frequently Asked Questions ==

= Does it change my images? =

No. It only reads the original file.

= Why PHP 8.3? =

The verifier it uses requires PHP 8.3 or later.

== Changelog ==

= 0.1.0 =

* An empty plugin that activates and loads the verifier.
