# A signed image with EXIF rotation

Measured 2026-09-29 on WordPress 7.1.2, PHP 8.3.35 (wp-env via Colima),
Chrome 153, with this plugin at `42adb76` (0.1.3 plus readme changes,
verifier v0.2.6). No plugin code was changed. Closes the case
`notes/m1-original-file.md` left unmeasured: no fixture with an EXIF
orientation other than 1 was found then.

## Question

For an image whose EXIF orientation is not 1, WordPress (or, with
client-side media processing, the browser) makes an upright `-rotated`
copy, and `-scaled` for a large one. Does the plugin still check the
uploaded original, and equal the verifier CLI on it?

## Fixtures

Found with `exiftool -Orientation` across `tests/Fixtures/` here, in the
verifier and in `C2PA_Content_Credentials`; copied into the untracked
`tests/tmp/m-rotated/`. All three have orientation 6 (rotate 90° CW):

| file (verifier `tests/Fixtures/`) | size | CLI on the file |
|---|---|---|
| `c2pa-rs/no_alg.jpg` | 480×270 | `Invalid`: `claimSignature.mismatch`, `signingCredential.untrusted`, `algorithm.unsupported` (2×), `assertion.action.malformed`, `assertion.dataHash.mismatch` |
| `public-testfiles/truepic-20230212-landscape.jpg` | 4032×3024 | `Invalid`: `signingCredential.expired`, `signingCredential.untrusted`, `assertion.dataHash.mismatch` |
| `public-testfiles/truepic-20230212-camera.jpg` | 4032×3024 | the same |

Their verdicts are not about rotation; any `-rotated` or `-scaled` copy
has no manifest, so a check of the copy says `none`. The comparison is
the active manifest's failure codes, in order.

## Method

A probe (`tests/tmp/m-rotated/measure.sh`, untracked) imported or
received each upload, ran the plugin's queue, and logged the attached
file, the metadata's `original_image`, the kept path
(`_tracefern_source`), whether `wp_get_original_image_path()` is
byte-identical to the source, the stored state and codes, and the CLI on
the source and on the attached file. Routes:

- (a) WP-CLI `wp media import`: all three.
- (b) REST `POST /wp/v2/media` with an application password (deleted
  afterwards): `no_alg.jpg`, `truepic-…-camera.jpg`.
- (d) the block editor's own `mediaUpload` in Chrome 153 on
  `post-new.php` (`crossOriginIsolated` true, so client-side processing
  on): `no_alg.jpg`, `truepic-…-camera.jpg`; and, as controls without
  rotation, the Lightroom church JPEG (3280×2451) and
  `fixture-signed.jpg`.

## Results (measured)

| route | fixture | attached | `original_image` | kept (checked) | plugin | equals CLI on source |
|---|---|---|---|---|---|---|
| (a) WP-CLI | `no_alg` | `-rotated` | the upload | the upload | `Invalid`, same codes | yes |
| (a) WP-CLI | Truepic ×2 | `-scaled` (1920×2560, upright) | the upload | the upload | `Invalid`, same codes | yes |
| (b) REST | `no_alg`, Truepic camera | as (a) | the upload | the upload | as (a) | yes |
| (d) block editor | `no_alg` | `-rotated-1` (270×480) | the upload | **`-rotated-1`** | **`none`** | **no** |
| (d) block editor | Truepic camera | `-scaled-1` (1920×2560) | **`-rotated-1`** | **`-scaled-1`** | **`none`** | **no** |
| (d) block editor | church (no rotation) | `-scaled` | the upload | the upload | `Valid` | yes |
| (d) block editor | `fixture-signed.jpg` | the upload | – | the upload | `Valid` | yes |

On every route the uploaded file itself stayed byte-identical to the
source (`editor-no_alg.jpg`, `editor-truepic-20230212-camera.jpg`).

**The finding: on the block editor route with client-side processing, a
signed image with EXIF rotation is shown as "No Content Credentials".**
The plugin checks the browser's rotated or scaled copy, not the upload.
That is a verdict the verifier gave about another file than the one the
site owner uploaded, on the route that is the default on HTTPS sites in
Chromium (`notes/m1-original-file.md`).

Two things differ from the server routes, both measured in the attachment
metadata and the uploads folder:

1. The browser's copies carry a `-1` suffix: `-rotated-1`, `-scaled-1`,
   and every image size (`-150x150-1`, …). Without rotation the copy is
   `-scaled`, without suffix.
2. For the large rotated image, `original_image` names the browser's
   `-rotated-1` copy, not the upload, so `wp_get_original_image_path()`
   returns a copy as well. (`notes/m1-original-file.md` found it returns
   the original in the final state; that holds for the routes measured
   there, not for this one.)

Core's own record of sideloaded names, the post meta
`_wp_sideloaded_file`, does not survive: after finalize, `no_alg` and the
church upload had no rows left, the Truepic upload two (its `-rotated-1`
copy and the upload's name). `finalize_item()` deletes the rows it
consumed right after its metadata update (read in core).

## Cause (reasoned from the code, not traced)

`UploadHook::shownFile()` treats the attached file as WordPress's own copy
only when its name is exactly `<original_image name>-scaled` or
`-rotated`. With the `-1` suffix, or with `original_image` itself a copy,
it does not match, so `UploadHook::onMetadataUpdate()` (SPEC-014) sees a
new "original", moves the kept path to the copy and checks again. The
upload's own path was kept first (`add_attachment`); the metadata update
from the browser's sideload replaced it.

## Not measured

- Other orientations (3, 8); only 6 was available.
- The Media Library modal opened from the block editor; `media-new.php`
  (not cross-origin isolated, so the server route, reasoned).
- Why the browser's copies get the `-1` suffix (reasoned: a collision
  check in the sideload endpoint; not read).
- A real phone photo; the fixtures are a test file and two 2023 Truepic
  camera images.

## For a spec (Maurice decides)

The plugin should keep the upload's path through the browser's sideloads
(for example: do not move the kept path in `onMetadataUpdate()` when the
kept file still exists unchanged and the new "original" is an image the
browser sideloaded for the same upload), and a regression test on route
(d) needs a rotated signed fixture in `tests/Fixtures/` (`no_alg.jpg`
is from `c2pa-rs`, MIT/Apache-2.0, like the ones already there).
