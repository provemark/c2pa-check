# Test fixtures

Copied unchanged from `provemark/c2pa-verifier` `tests/Fixtures/` at commit
`75b7811` (2026-09-26). The verifier's fixture READMEs say where each file
comes from; the short version is below. No private key lives here, under any
name.

| file | from | licence | what it is for |
|---|---|---|---|
| `fixture-signed.jpg` / `.png` / `.webp` | the verifier's own signed test assets, signed with the public c2pa-rs ES256 test certificates | MIT (the verifier's) | a manifest that verifies: `Valid`, signer untrusted without settings |
| `fixture-unsigned.jpg` / `.png` / `.webp` | the verifier's own | MIT (the verifier's) | no manifest: `none` |
| `adobe-20260425-lightroom-classic-church.jpg` | Wikimedia Commons, `File:Church detail at night, Manganitis, Ikaria, Greece julesvernex2.jpg` by **Jules Verne Times Two (Julesvernex2)**, via the verifier's `writers/` | **CC BY-SA 4.0**; the licence attaches to this file, not to the plugin's code | 3280×2451, above WordPress's 2560 threshold, so a `-scaled` copy is made; signed by Adobe Lightroom Classic |
| `adobe-20260304-photoshop-remote-manifest.jpg` | `contentauth/c2pa-js`, `packages/c2pa-web/test/assets/PirateShip_save_credentials_to_cloud.jpg` (b2f23fc), via the verifier's `writers/` | MIT, see `LICENSE-contentauth-c2pa-js` | no manifest in the file; its XMP points to one by URL, which is never fetched |

The tests make further files at run time in `tests/tmp/` (gitignored): an
altered copy of `fixture-signed.jpg`, and a GIF and a PDF for the types the
plugin leaves alone.
