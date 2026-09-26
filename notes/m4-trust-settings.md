# M4.0: the official trust lists on real files

Measured 2026-09-26 with `provemark/c2pa-verifier` v0.2.3, following the
verifier's `docs/trust-settings.md`. Input for SPEC-004.

## The lists

| file | source | as fetched |
|---|---|---|
| `C2PA-TRUST-LIST.pem` | `c2pa-org/conformance-public` `trust-list/`, CC BY 4.0 | 30 certificates, 37 911 bytes |
| `C2PA-TSA-TRUST-LIST.pem` | the same directory | 22 certificates, 28 863 bytes |
| `DigiCertTrustedRootG4.crt.pem` | `https://cacerts.digicert.com/` | SHA-256 `55:2F:7B:DC:…:C8:99:88`, equal to the verifier's docs; valid until 2038-01-15 |

Latest commit touching `trust-list/`: `99927ca`, 2026-08-14T21:00:15Z,
"Automated Sync: Update trust lists" (the list is synced automatically;
the two before it are from 2026-08-13). The settings JSON built by the
recipe (signer list as kind `manifest`, TSA list and DigiCert as kind
`tsa`, `verify_trust` true) is 70 177 bytes.

## Verdicts (measured)

| file | no settings | C2PA lists | lists + DigiCert |
|---|---|---|---|
| `writers/google-20250919-pixel10-npld-picnic-table.jpg` (public domain) | Invalid | **Trusted** | **Trusted** |
| `openai-20260826-c2pa_2x.png` | Valid | **Trusted** | **Trusted** |
| `amazon-20240925-titan-g1.png` | Invalid | Invalid | **Valid** |
| `c2pa-rs-ocsp.jpg` | Invalid | Invalid | **Valid** |
| `adobe-20260425-lightroom-classic-church.jpg` | Valid | Valid | Valid |
| `fixture-signed.jpg` / `.png` / `.webp` | Valid | Valid | Valid |
| `writers/trustnxt-20260113-icon-signed-timestamp.jpg` | Valid | Valid | Valid |
| `writers/microsoft-20260609-bing-fast-heartbeat.jpg` | Invalid | Invalid | Invalid |
| unsigned fixtures, the remote-manifest file | none | none | none |

`vendor/bin/c2pa-verify --settings` with the same file gives the same
verdicts for the Pixel, OpenAI, Amazon and `fixture-signed.jpg` files.

Cost: `TrustSettings::fromJson()` 9.4 ms for the full settings; `verify()`
with them 0–17 ms per file (17 ms for the 5.8 MB Pixel file).

## Consequences (reasoned)

- With the bundled lists as the default, the plugin's verdicts change for
  files whose signer or timestamp authority is on a list. The integration
  tests compare with the CLI; the CLI must then get the same settings.
- With DigiCert on (the decided default), Amazon Titan becomes `Valid`, so
  it would carry the AI label. SPEC-003 AC2 uses it as the "Invalid file
  that claims AI" case; under the default settings that no longer holds.
- Parsing the settings on every upload costs about 10 ms; no cache needed.
