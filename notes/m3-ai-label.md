# M3.0: where a file says it is AI-generated

Measured 2026-09-26 with `provemark/c2pa-verifier` v0.2.3, without trust
settings, on every JPEG, PNG and WebP in the verifier's `writers/` fixtures
and on every fixture in the verifier and the sister repository whose bytes
contain an IPTC `digitalsourcetype` for AI. Input for SPEC-003.

## Where it is

In `VerificationReport::toArray()` (c2patool's shape): `manifests[<active
manifest>].assertions[]`, the assertion labelled `c2pa.actions` (claim v1)
or `c2pa.actions.v2` (claim v2), `data.actions[].digitalSourceType`: a full
IPTC URI. Every such URI in both fixture trees starts with
`http://cv.iptc.org/newscodes/digitalsourcetype/` (821 occurrences, none
with `https`).

## Results (measured)

| file | verdict | digitalSourceType in the active manifest's actions |
|---|---|---|
| `writers/openai-20260826-c2pa_2x.png` (MIT) | `Valid` | `c2pa.created` = `…/trainedAlgorithmicMedia` (actions.v2) |
| `writers/amazon-20240925-titan-g1.png` (Apache-2.0) | `Invalid` | `c2pa.created` = `…/trainedAlgorithmicMedia` (actions, v1) |
| `c2pa-rs/ocsp.jpg` | `Invalid` | `c2pa.opened` and `c2pa.edited` = `…/compositeWithTrainedAlgorithmicMedia` |
| `ingredient/manifest-and-dst.png` | `Invalid` | `c2pa.created` = `…/algorithmicMedia` (not trained) |
| `writers/google-20250919-pixel10-npld-picnic-table.jpg` | `Invalid` | `c2pa.created` = `…/computationalCapture` (a camera) |
| `writers/microsoft-20260609-bing-fast-heartbeat.jpg` | `Invalid` | none readable: the verifier does not yet read Bing's hard binding |
| `writers/adobe-20260425-lightroom-classic-church.jpg` | `Valid` | none |

So: one fixture that is AI-generated **and** `Valid` (OpenAI); one that
claims AI but is `Invalid` (Amazon Titan, a natural negative); no fixture
with `compositeWithTrainedAlgorithmicMedia` that verifies.

## Consequences for SPEC-003 (reasoned)

- A suffix match on "trainedAlgorithmicMedia" is case-sensitive and would
  not match `compositeWithTrainedAlgorithmicMedia` (capital T); matching the
  full URI is clearer than a suffix.
- A tampered copy of the OpenAI PNG (one byte of image data) is the
  "tampered AI fixture" the brief asks for; no key is needed.
- The flag can be stored with the entry at upload time and gated at display
  time on `Trusted` / `Valid`, so an `Invalid` file never shows the label.
