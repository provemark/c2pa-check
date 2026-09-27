# Provemark C2PA Check

A WordPress plugin that verifies the Content Credentials (C2PA) of every
uploaded image with [provemark/c2pa-verifier](https://github.com/provemark/c2pa-verifier)
and shows the result in the Media Library. It only checks: it never signs,
never blocks an upload and makes no network calls.

Status: version 0.1.0, submitted to the WordPress.org plugin directory on
2026-09-27 and awaiting review. Until it is listed there, it is not
released.

Requirements: WordPress 7.1 or later, PHP 8.3 or later with `ext-openssl`
and `ext-mbstring`.

## What it does

- Checks every JPEG, PNG and WebP upload in the background (one WP-Cron
  queue), so a check can never break an upload. It checks the file visitors
  see, except WordPress's own `-scaled`/`-rotated` copy made at upload, for
  which it checks the uploaded original that holds the credentials.
- Checks an image again when it is edited or restored in WordPress, and
  says "Changed since its check" when its file changed without WordPress
  knowing.
- Shows the verdict in a Media Library column and in the attachment
  details: verified (trusted signer), intact (signer not trusted), does not
  verify, no Content Credentials, or could not be checked, with the signer,
  the status codes and the trust list used.
- Shows "AI-generated (signed)" when a manifest that verifies says the
  image was made by generative AI; never on a file that does not verify.
- Trusts the C2PA conformance programme's trust lists by default (bundled,
  with their date; see [`trust/README.md`](trust/README.md)); Settings →
  C2PA Check lets an administrator replace them.
- Sorts and filters the Media Library list by verdict, including all
  AI-generated images and the images still waiting for their check.
- Re-checks existing images with WP-CLI: `wp provemark-c2pa check`.
- Works on multisite, suggests text for the site's privacy policy, and
  removes its data when the plugin is deleted.

The user-facing documentation, with the FAQ, is [`readme.txt`](readme.txt).

![The Content Credentials column in the Media Library](.wordpress-org/screenshot-1.png)

![The attachment details of a verified, AI-generated image](.wordpress-org/screenshot-2.png)

![Settings → C2PA Check](.wordpress-org/screenshot-3.png)

![The list filtered to AI-generated images](.wordpress-org/screenshot-4.png)

The screenshots live in `.wordpress-org/`, named as wordpress.org's
plugin directory expects them; the images in them are the test fixtures in
[`tests/Fixtures`](tests/Fixtures/README.md), with their sources and
licences.

## Building and testing

`composer check` runs Pint, PHPStan at level max and the unit tests.
Four wp-env environments, each its own WordPress:

| command | port | for |
|---|---|---|
| `npm run env:start` | 8888 | development, by hand; no test touches it |
| `npm run test:start` | 8892 | `composer test:integration` (uploads, uninstalls, thousands of test attachments) |
| `npm run multisite:start` | 8894 | `composer test:multisite`: a multisite network with the plugin network-activated |
| `npm run release:start` | 8890 | `composer test:release`: `composer build` makes `build/provemark-c2pa-check.zip`, which is installed and checked there |

`composer build` ([`tools/build.sh`](tools/build.sh)) makes the release
zip from the committed tree: `composer install --no-dev`, then
[Strauss](https://github.com/BrianHenryIE/strauss) 0.30.0 (a pinned,
SHA-256-checked `strauss.phar`) prefixes the bundled verifier to
`Provemark\C2paCheck\Vendor\`, and what does not run is left out.

The specifications are in
[`specs/`](specs/), measurements in [`notes/`](notes/), decisions in
[`NOTES.md`](NOTES.md).

This plugin is built with Claude Code; [`AI-LOG.md`](AI-LOG.md) records what
the assistant produced and what was decided by the maintainer.

## Licence

MIT, see [`LICENSE`](LICENSE). The bundled C2PA trust lists are CC BY 4.0
(see [`trust/README.md`](trust/README.md)).
