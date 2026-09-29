# An image optimizer next to the plugin

Measured 2026-09-29 on WordPress 7.1.2, PHP 8.3.35 (wp-env via Colima,
aarch64), with this plugin at 0.1.3 (`632567e`, verifier v0.2.6) and
EWWW Image Optimizer 8.8.0 at its default settings. No plugin code was
changed.

## Question

Image optimizers rewrite uploaded images, often the original, and often
in the background. Since SPEC-013 this plugin checks in the background
too, so an optimizer can act before or after the check. What does a site
owner see then, and does the plugin ever show a verdict for a file that
is not the one on disk?

## Method

EWWW was added through a local, untracked `.wp-env.override.json`, and
initialised by loading its settings page as the admin. Its defaults, read
from the options table: optimize on upload, in the background
(`background_optimization` 1), remove metadata (`metadata_remove` 1),
lossless JPEG and PNG (level 10), WebP untouched (level 0), and resize
uploads to at most 2560×2560 (`maxmediawidth` / `maxmediaheight`).

EWWW installs no tools on Linux on aarch64 (`classes/class-local.php`,
it returns early for `aarch64`). The first round ran like that. For the
second round its own x86-64 tools (`jpegtran`, `optipng`, `gifsicle`,
`cwebp`) were copied by hand into `wp-content/ewww/`, where it installs
them on an x86-64 host; they run in the container through emulation. That
copy is the one deviation from a real x86-64 site.

Six signed fixtures from `tests/Fixtures/` were imported per round with
`wp media import` (WP-CLI route only): `fixture-signed.jpg`, `.png`,
`.webp`, the Lightroom church JPEG (3280×2451), the Pixel 10 JPEG
(4000×3000) and the OpenAI PNG. A probe script (in the untracked
`tests/tmp/m-optimizer/`) logged, per attachment, the SHA-256 and size of
the original after import and after the plugin's queue ran
(`wp cron event run tracefern_check`), then, once EWWW's queue was empty,
the verdict of `vendor/bin/c2pa-verify` on the file on disk, the stored
state, and what the `tracefern_verdict` filter returns.

## Results (measured)

| fixture | what EWWW did | when | file on disk now | plugin shows |
|---|---|---|---|---|
| signed JPEG (small) | nothing (no saving) | – | unchanged, `Valid` | `Valid` |
| signed WebP | nothing (WebP level 0) | – | unchanged, `Valid` | `Valid` |
| signed PNG | lossless `optipng`, 47,736 → 46,701 bytes, manifest kept | background, **after** the check | `Invalid` (`assertion.dataHash.mismatch`) | "Changed since its check" |
| OpenAI PNG | the same, 2,210,928 → 2,152,105 bytes | background, after the check | `Invalid` (`assertion.dataHash.mismatch`) | "Changed since its check" |
| church JPEG | resized **in place** to 2560 wide, manifest gone; later optimized again | at upload, **before** the check | no manifest | "No Content Credentials", then "Changed since its check" |
| Pixel 10 JPEG | the same | at upload, before the check | no manifest | the same |

Both rounds gave the same picture: in the first round (no tools) EWWW's
queue waited, and optimized the round-1 files too once the tools were
there.

1. **The resize destroys the original before the plugin sees it.** EWWW
   resizes in the `wp_handle_upload` filter (`classes/class-plugin.php`,
   `ewww_image_optimizer_handle_upload()` → `ewww_image_optimizer_resize_upload()`),
   which runs before the attachment exists. WordPress makes no `-scaled`
   copy and records no `original_image`. The plugin checks what is there
   and stores `none`, which is true of the file and false of the upload:
   the site owner uploaded a signed image.
2. **A lossless optimization keeps the manifest and breaks its hash.** The
   PNG optimizer keeps the C2PA chunk but rewrites the bytes it covers, so
   the verifier says `Invalid`.
3. **SPEC-014 works as designed here.** When the optimizer ran after the
   check, the plugin stopped showing the old verdict and showed "Changed
   since its check"; the filter returned `status: changed`, `state: null`.
4. **Checking again gives "Does not verify".** `wp tracefern check` on the
   two optimized PNGs stored `Invalid` with `assertion.dataHash.mismatch`,
   and the Media Library then says "Does not verify". The readme's FAQ
   advises checking a changed image again. The verdict is the verifier's
   own for the file on disk, so the fail-closed rule holds; but a site
   owner reads "Does not verify" about a Trusted OpenAI image that only
   an optimizer touched.

## Reasoned, not measured

- The order is a race. Here the plugin's check ran within seconds and
  EWWW's background queue later. When the optimizer finishes first, the
  first check already stores `Invalid`, with no "Changed" step between.
- Other optimizers (Imagify, ShortPixel, Smush, Converter for Media) were
  not tested; any that rewrite the original in place, or resize on upload,
  will do one of the two things above. Those that keep the original
  (WordPress's own `-scaled`) will not.
- The block editor route with client-side media processing was not
  repeated with EWWW active. There the browser uploads the original as is
  (`notes/m1-original-file.md`); EWWW's `wp_handle_upload` resize would
  still apply to it (reasoned from the hook, which that route also
  passes through).
- A check in `wp_handle_upload_prefilter`, or in `wp_handle_upload` before
  EWWW's priority 10, would see the uploaded bytes (reasoned from the
  hook order in `_wp_handle_upload()`); SPEC-013 moved the check out of
  the upload request for a reason, so that is a trade-off, not a fix.

## Options for a spec (for Maurice to weigh)

1. Say it in the readme: optimizers that rewrite or resize the original
   destroy or break its Content Credentials, and name the settings
   (EWWW: "Resize Images", lossless PNG).
2. Record the uploaded file's hash at `wp_handle_upload`, before EWWW's
   priority 10 (cheap, early),
   so a later check can tell "changed after upload" apart from "changed
   after signing", and say so in plain words instead of "Does not verify".
3. Verify early after all, on the upload's own bytes, at the cost
   SPEC-013 removed.

## Cleanup

The override and the probe are untracked; the test attachments
(IDs 12931–12942) live only in the local wp-env database.
