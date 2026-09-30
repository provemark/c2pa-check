# Media offloaded to S3 next to the plugin

Measured 2026-09-30 on WordPress 7.1.2, PHP 8.3 (wp-env via Colima,
aarch64, the release environment on port 8890), with this plugin at
0.1.5 (verifier v0.2.6) and WP Offload Media Lite 3.4.3. No plugin code
was changed. Closes the measurement `NOTES.md` "Open" 3 asked for.

## Question

Offload plugins copy each upload to cloud storage and can then delete the
local files. Since SPEC-013 this plugin checks in the background, after
the upload request. Does the check still find the original, and does it
give the verifier's verdict on it?

## Method

- Storage: a local S3-compatible gateway (Versity S3 Gateway,
  `versity/versitygw`, in Docker on the wp-env network) with throwaway
  keys. MinIO was the first choice; its images are no longer pullable
  without a login. Nothing left the machine.
- WP Offload Media Lite was configured by a temporary must-use plugin:
  `AS3CF_SETTINGS` (provider `aws`, the local bucket, object prefix and
  versioning off) and the filter `as3cf_aws_init_client_args` pointing
  the client at the gateway, path-style. Its settings "Remove Local Media"
  (`remove-local-file`) and "Deliver Offloaded Media"
  (`serve-from-s3`) were switched per round.
- Uploads: `fixture-signed.jpg` (small) and the Lightroom church JPEG
  (3280×2451, so a `-scaled` copy); both `Valid` with the verifier CLI.
  Routes: WP-CLI `wp media import`, then the queue run by hand
  (`wp cron event run tracefern_check`); and the block editor's
  `mediaUpload` in Chrome (client-side processing on), where the queue is
  started by WP-Cron.
- Per upload: whether the original is still on disk before the queue
  runs, what `get_attached_file()` and `wp_get_original_image_path()`
  return, whether the offloaded original is byte-identical to the
  fixture, and the stored result.

## Results (measured)

| round | route | local files | path the plugin keeps | stored |
|---|---|---|---|---|
| A: remove local off | WP-CLI, both files | present | the upload | `Valid` (= CLI) |
| B: remove local on, deliver off | WP-CLI, both files | **gone** before the queue runs | the upload's local path | **`error` / `unreadable`** |
| C: remove local on, deliver on | WP-CLI, both files | **gone** | `s3useast1://tracefern-test/…` (the offload plugin's stream wrapper) | **`error` / `exception`** |
| C | block editor, small JPEG | gone | `s3useast1://…` | `error` / `exception` |
| C | block editor, church JPEG | gone afterwards | the upload | `Valid`: the check ran before the local file was removed |

In every round the offloaded original in the bucket was byte-identical
to the fixture (md5). Round C is the realistic one: removing local files
without delivering from the bucket leaves a site with broken images.

So on a site that removes local media, the plugin shows "Could not be
checked" for (nearly) every upload. It fails closed, never a wrong
verdict. On the block editor route the outcome depends on timing: when
WP-Cron runs the check while the browser is still sideloading image
sizes, the local original is still there.

## Why `exception` in round C (measured)

WP Offload Media returns its stream wrapper path from `get_attached_file`
and `wp_get_original_image_path` when the file is not local and it
delivers from the bucket (`classes/integrations/media-library.php`).
`Checker::check()` opens it (`is_file` and `is_readable` pass through the
wrapper), and the verifier throws `InvalidArgumentException: FormatDetector
needs a seekable stream resource`. The AWS SDK's stream wrapper reports
`seekable` true but does not seek (`fseek` to the end leaves `ftell` 0).

Two ways to give the verifier a usable stream were tried by hand, outside
the plugin:

- Copying the object into `php://temp` first: `Valid` for both files,
  equal to the CLI.
- Opening with the wrapper's own context option `seekable => true`:
  **`Invalid`** for both files, with `general.error` "unexpected end of
  file while reading piece 1 data of the segment at offset 20: wanted
  63992 bytes, got 16364". That stream returns short reads (11 reads
  shorter than asked for the small file, before its end); the verifier
  treats a short `fread` as the end of the file. A local file never
  returns a short read before its end, so this was not seen before.

The second is a verifier issue, not a plugin one: a stream that is
seekable but returns short reads gets a wrong verdict. The plugin does not
reach it today (it never passes a context), but any fix that reads through
a wrapper must not either.

## Not measured

- WP Offload Media Pro, other offload plugins (Media Cloud, WP-Stateless),
  and real AWS S3 (the gateway stands in for it; the client and wrapper
  are the AWS SDK's own).
- The REST route (`POST /wp/v2/media`); reasoned to be the same as WP-CLI,
  since both offload in the upload request.
- PNG and WebP.

## For a spec (Maurice decides)

Options, each with its cost:

1. Read through the wrapper into a bounded `php://temp` copy when the
   kept path is not a local file (measured to work here). Costs a
   download per check and a size limit; network access through another
   plugin's client, which the plugin otherwise never does.
2. Check in the upload request before the offload plugin removes the
   file (its offload runs on `wp_update_attachment_metadata` at priority
   110): the cost SPEC-013 removed.
3. Say in the readme that sites which remove local media are not
   supported, and keep failing closed.

And for the verifier, separately: read until the requested length or the
real end of the stream, so a short read is not taken as the end.

## After SPEC-032 (measured, AC7)

Measured 2026-09-30 in the release environment with the build of
`904a206` (0.1.6 plus SPEC-032) and WP Offload Media Lite 3.4.3 against
the same local gateway, "Remove Local Media" and delivery on (round C
above). WP-CLI imports, the queue run by hand:

| upload | local original | path checked | stored | CLI |
|---|---|---|---|---|
| `fixture-signed.jpg` | gone | `s3useast1://tracefern-test/2026/09/fixture-signed.jpg` | `Valid` | exit 0 |
| Lightroom church JPEG | gone | `s3useast1://tracefern-test/2026/09/adobe-20260425-lightroom-classic-church.jpg` (the original, not `-scaled`) | `Valid` | exit 0 |

No "Changed after upload" on either; the stored upload fingerprint equals
the fixture's hash. The plugin read each original once, through WP Offload
Media's stream, into a temporary copy.
