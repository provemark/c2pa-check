# Bundled trust lists

These files are what Provemark C2PA Check trusts by default (SPEC-004).
Nothing here is fetched at run time; a new copy arrives with a plugin
release. An administrator can replace all of it with custom trust settings
on Settings → C2PA Check.

| file | source | what it anchors |
|---|---|---|
| `C2PA-TRUST-LIST.pem` | [`c2pa-org/conformance-public`](https://github.com/c2pa-org/conformance-public/tree/main/trust-list), `trust-list/`, commit `99927ca` (2026-08-14) | the certificate authorities the C2PA conformance programme accepts for signers (trust kind `manifest`); 30 certificates |
| `C2PA-TSA-TRUST-LIST.pem` | the same directory and commit | the timestamp authorities the programme accepts (trust kind `tsa`); 22 certificates |
| `DigiCertTrustedRootG4.crt.pem` | [DigiCert](https://cacerts.digicert.com/DigiCertTrustedRootG4.crt.pem) | timestamp authorities (trust kind `tsa`), used only when the DigiCert option is on (the default) |

The two C2PA lists are © the Coalition for Content Provenance and
Authenticity (C2PA), licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/), and included
unchanged.

The DigiCert root's SHA-256 fingerprint is
`55:2F:7B:DC:F1:A7:AF:9E:6C:E6:72:01:7F:4F:12:AB:F7:72:40:C7:8E:76:1A:C2:03:D1:D9:D2:0A:C8:99:88`
(checked when copied, 2026-09-26). It is not on the C2PA's TSA list;
Adobe Firefly, Microsoft Bing and Amazon Titan timestamp under it. With it,
a file whose signing certificate has since expired verifies when a DigiCert
timestamp authority vouches for the signing time; without it, such a file
is `Invalid`. See the verifier's `docs/trust-settings.md`.
