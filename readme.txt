=== Provemark C2PA Check ===
Tags: c2pa, content credentials, provenance, media library, ai
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Checks the Content Credentials (C2PA) of uploaded images and shows the result in the Media Library.

== Description ==

Content Credentials (C2PA) are a signed record inside an image file: who
made or edited it, with which tool, and whether generative AI was used.
Provemark C2PA Check verifies that record for every JPEG, PNG and WebP you
upload and shows the verdict where you already work with media.

* **Checked right after upload, on the original file**, not on the
  resized copies WordPress or your browser makes. The check runs in the
  background, so a file that trips it up can never break an upload.
* **A verdict per image** in a Media Library column and in the attachment
  details: who signed it, when, and against which trust list.
* **"AI-generated (signed)"** when a manifest that verifies says the image
  was made by generative AI. Never on a file that does not verify.
* **Sort and filter** the Media Library list by verdict, including all
  AI-generated images.
* **Check existing images again** with WP-CLI:
  `wp provemark-c2pa check --all`.

The verdicts:

* **Verified: trusted signer** — the credentials verify and the signer's
  certificate chains to a trusted certificate authority.
* **Intact: signer not trusted** — the credentials verify, but the signer
  is not on the trust list. The file is unchanged since signing; who
  signed it is not vouched for.
* **Does not verify** — something failed, for example the image was
  changed after signing. The details list the C2PA status codes.
* **No Content Credentials** — the file carries none. Most images today.
* **Could not be checked** — the file could not be read or the check did
  not finish. The upload always proceeds.

What it does not do:

* It never signs anything and holds no keys.
* It never blocks an upload.
* It makes no network calls. A manifest that is only referenced by URL is
  not fetched, and trust lists are never downloaded.

Verification is done by
[provemark/c2pa-verifier](https://github.com/provemark/c2pa-verifier), a
C2PA verifier written in PHP, bundled with the plugin.

== Installation ==

1. Install and activate the plugin. The server needs PHP 8.3 or later with
   the `openssl` and `mbstring` extensions.
2. Upload images as usual. Each JPEG, PNG and WebP is checked in the
   background, usually within seconds; until then it shows "Check pending".
3. Optional: under Settings → C2PA Check, choose whether to trust
   DigiCert timestamps, or paste your own trust settings.
4. Images uploaded before the plugin was active show "Not checked". Check
   them with `wp provemark-c2pa check --unchecked` (WP-CLI).

== Frequently Asked Questions ==

= What is the difference between "Verified" and "Intact"? =

Both mean the file is exactly as it was signed. "Verified" also means the
signer's certificate comes from an authority on the trust list, by default
the C2PA conformance programme's list. "Intact" means nobody on that list
vouches for who the signer is.

= Why does a genuine photo say "Does not verify"? =

The file was changed after it was signed, for example by an editor that
kept the old Content Credentials but not the signature's match with the
pixels. The details show the status codes; `assertion.dataHash.mismatch`
means the image data changed.

= Why do most of my images show "No Content Credentials"? =

Most cameras and apps do not add them yet, and many services remove them
when an image is shared or downloaded.

= Does "AI-generated (signed)" detect AI images? =

No. It shows what a verified manifest in the file says about itself. An
AI image without Content Credentials gets no label.

= Does it change my images or send data anywhere? =

No. It only reads the original file and stores the result with the image.
Settings → Privacy offers suggested text for your privacy policy.

= Why does an image say "Check pending"? =

The check runs in the background through WP-Cron, on the next request to
the site after the upload, usually within seconds. If WP-Cron is switched
off (`DISABLE_WP_CRON`), the checks run when the site's own cron job runs
it. After an hour without a result the image shows "Not checked"; check it
with `wp provemark-c2pa check --unchecked`.

= Which formats are checked? =

JPEG, PNG and WebP. HEIC files are converted to JPEG by the browser before
upload and arrive without their Content Credentials. Video and audio are
not checked.

= How do I check images again after changing the trust settings? =

With WP-CLI: `wp provemark-c2pa check --all`, or `--state=Invalid,error`,
or attachment IDs. Add `--dry-run` to see what would be checked.

= Does it work on multisite? =

Yes. Each site checks its own uploads and has its own settings. Deleting
the plugin removes its data from every site of the network, in one
request; on a network of thousands of sites, prefer WP-CLI.

= Why PHP 8.3? =

The bundled verifier requires PHP 8.3 or later.

== Screenshots ==

1. The Content Credentials column in the Media Library list.
2. The attachment details of a verified, AI-generated image.
3. Settings → C2PA Check: the bundled trust list, DigiCert timestamps and custom trust settings.
4. The Media Library list filtered to AI-generated images, with the verdict filter above the list.

== Trust lists ==

By default the plugin trusts the certificate authorities on the C2PA
conformance programme's trust lists, bundled with the plugin (see
`trust/README.md` for the date and source), and, optionally, the DigiCert
Trusted Root G4 for timestamps. Settings → C2PA Check shows the date of the
bundled copy and lets an administrator replace the lists with their own
trust settings. The plugin never downloads a list; a new copy comes with a
plugin update.

The C2PA trust lists are © the Coalition for Content Provenance and
Authenticity (C2PA), from https://github.com/c2pa-org/conformance-public,
licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/).

== Changelog ==

= 0.1.0 =

* Verifies the Content Credentials of JPEG, PNG and WebP uploads on the original file, in the background.
* Media Library column and attachment details, with an AI label for verified AI images.
* Bundled C2PA trust lists (2026-08-14), DigiCert timestamps, custom trust settings.
* Sorting and filtering by verdict; `wp provemark-c2pa check` for existing images.
* Suggested privacy policy text; data removed on uninstall.

== Upgrade Notice ==

= 0.1.0 =

First version.
