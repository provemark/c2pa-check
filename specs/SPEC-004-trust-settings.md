# SPEC-004: Trust settings

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Without trust settings the best any file gets is `Valid`: "the signer is
not on your lists". That tells a site owner little. The C2PA publishes the
certificate authorities its conformance programme accepts, for signers and
for timestamp authorities (C2PA 2.4 §14.4). `notes/m4-trust-settings.md`
measured what those lists do on real files: a Google Pixel 10 photo and an
OpenAI image become `Trusted`; with the DigiCert Trusted Root G4 added as a
timestamp anchor, an Amazon Titan image and a c2pa-rs test file whose
signers have expired become `Valid` instead of `Invalid`. The verifier's
CLI agrees with every one of those verdicts for the same settings file.

Decisions this spec follows (`NOTES.md`, decision 1, and Maurice on
2026-09-26): bundle the two C2PA lists with their date; an admin-pasted
settings JSON **replaces** them; DigiCert as a separate `tsa` anchor behind
its own option, on by default; warn when the bundled copy is older than
about six months; never fetch anything. If settings cannot be built at
upload time, verify without them and say so. Record per image which trust
source was used, and show it. The settings live on their own page.

## Scope

**In scope**

- `trust/` in the plugin: `C2PA-TRUST-LIST.pem` and `C2PA-TSA-TRUST-LIST.pem`
  from `c2pa-org/conformance-public` commit `99927ca` (2026-08-14), and
  `DigiCertTrustedRootG4.crt.pem`; a `trust/README.md` with source, commit,
  date, the CC BY 4.0 attribution and the DigiCert SHA-256 fingerprint.
- `TrustConfig`: builds the `TrustSettings` for a check from the custom
  JSON (if any) or the bundled lists (with or without DigiCert), and names
  the source.
- The stored entry (SPEC-001) gains `trust`: `c2pa-2026-08-14+digicert`,
  `c2pa-2026-08-14`, `custom` or `none`; the details (SPEC-002) name it.
- A settings page, Settings → "C2PA Check" (`manage_options`): the bundled
  list's date and commit, a warning when it is older than 183 days, the
  DigiCert option, a textarea for custom settings JSON (validated with
  `TrustSettings::fromJson()` on save; rejected input keeps the previous
  value), and a note that settings apply to new uploads only.
- An admin notice while the last check had to run without trust settings.
- The attribution in `readme.txt`.
- SPEC-003 amendment 1: its AC2 runs with DigiCert off, where Amazon Titan
  is `Invalid` (with DigiCert on it is `Valid` and rightly labelled).

**Out of scope** (each needs its own spec before it may be built)

- Re-checking existing media after a settings change (a later WP-CLI
  command).
- Fetching or updating the lists from the network. Never.
- Merging custom settings with the bundled lists.
- Removing the options on uninstall (M5).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-004')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

"The default settings" below means the settings `TrustConfig` builds from
the bundled lists with DigiCert, written to a file so the CLI can read the
same bytes (`vendor/bin/c2pa-verify --settings <file>`).

- **AC1 — a Pixel 10 photo is Trusted by default**
  - Given `google-20250919-pixel10-npld-picnic-table.jpg` and the default
    settings
  - When it is uploaded
  - Then its entry has state `Trusted`, equal to the CLI with the default
    settings, and `trust` `c2pa-2026-08-14+digicert`; the column shows
    "Verified: trusted signer"

- **AC2 — an OpenAI image is Trusted and labelled**
  - Given `openai-20260826-c2pa_2x.png` and the default settings
  - When it is uploaded
  - Then its state is `Trusted` (as the CLI says) and the column shows
    "AI-generated (signed)"

- **AC3 — DigiCert decides expired signers with DigiCert timestamps**
  - Given `amazon-20240925-titan-g1.png`
  - When it is uploaded with DigiCert on, and again with DigiCert off
  - Then the first entry is `Valid` with `trust` `c2pa-2026-08-14+digicert`,
    the second `Invalid` with `trust` `c2pa-2026-08-14`, each equal to the
    CLI with the corresponding settings

- **AC4 — custom settings replace the bundled lists**
  - Given custom settings whose only anchor is the verifier's public c2pa-rs
    test root (the chain `fixture-signed.jpg` is signed with)
  - When `fixture-signed.jpg` and the Pixel 10 photo are uploaded
  - Then the first is `Trusted` and the second is not, both with `trust`
    `custom`, each equal to the CLI with the custom settings

- **AC5 — invalid custom settings are refused** *(error path)*
  - Given saved custom settings S
  - When text that is not trust settings (not JSON; a top-level
    `trust.allowed_list`, which the verifier refuses) is saved
  - Then the stored value is still S and a settings error names the
    verifier's reason

- **AC6 — settings that cannot be built: check without them** *(error path)*
  - Given custom settings that fail `TrustSettings::fromJson()` at upload
    time (stored past validation)
  - When an image is uploaded
  - Then it is verified without settings, its entry has `trust` `none`,
    and an admin notice says the last check ran without trust settings;
    after a check that could build them, the notice is gone

- **AC7 — the details name the trust source**
  - Given entries with each `trust` value, and one without the key
  - When their details are rendered
  - Then they end with "… against the C2PA trust list of 2026-08-14 (with
    DigiCert timestamps)", "… against the C2PA trust list of 2026-08-14",
    "… against custom trust settings", "… without a trust list", and for
    the entry without the key the SPEC-002 line unchanged

- **AC8 — the settings page**
  - Given an administrator
  - When the page renders
  - Then it shows the list date and commit, the DigiCert option checked by
    default, the custom JSON in a textarea escaped with `esc_textarea`
    (tested with `</textarea><script>`), and the new-uploads-only note; a
    user without `manage_options` cannot open it

- **AC9 — the age warning**
  - Given the bundled list date 2026-08-14
  - When "now" is 183 days later or more
  - Then `TrustConfig::isStale()` is true, and false before; the page shows
    the warning only when it is true

## References

- Specification: C2PA Technical Specification 2.4 §14.4 (trust lists, trust
  kinds), §15; the verifier's README ("Use", trust settings) and
  `docs/trust-settings.md` (the recipe); WordPress
  [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/),
  [`register_setting()`](https://developer.wordpress.org/reference/functions/register_setting/),
  [`add_options_page()`](https://developer.wordpress.org/reference/functions/add_options_page/),
  [`esc_textarea()`](https://developer.wordpress.org/reference/functions/esc_textarea/).
- Oracle: `provemark/c2pa-verifier` v0.2.3, `vendor/bin/c2pa-verify
  --settings <file>` with the same settings bytes; fixtures Pixel 10
  (public domain), OpenAI (MIT), Amazon Titan (Apache-2.0),
  `fixture-signed.jpg` and the verifier's public test root; measured in
  `notes/m4-trust-settings.md`.
- Reasoned: that `register_setting()`'s sanitize callback also runs for
  `update_option()` (so AC5 can be tested through WP-CLI); to be measured
  first.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Provemark\C2paCheck;

final class TrustConfig
{
    public const string LIST_DATE = '2026-08-14';
    public const string LIST_COMMIT = '99927ca';

    public function __construct(private string $trustDir, private string $customJson, private bool $digiCert) {}

    /** @return array{?TrustSettings, string} settings or null, and the source name */
    public function build(): array;

    public static function isStale(DateTimeImmutable $now): bool;
}

final class SettingsPage { public function register(): void; }
```

`Checker::check()` takes the settings; `UploadHook` asks `TrustConfig`
and stores `trust` with the entry.

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed in the draft:

- Age threshold: 183 days after the list date.
- The age warning shows on the settings page only.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/TrustTest.php` :: AC1 | `src/TrustConfig.php` `TrustConfig::build`, `settingsJson`; `src/UploadHook.php` `onAddAttachment`; `src/Checker.php` `check` |
| AC2 | `tests/Integration/TrustTest.php` :: AC2 | as AC1; `Display::showsAiLabel` (SPEC-003) |
| AC3 | `tests/Integration/TrustTest.php` :: AC3; `tests/Unit/TrustConfigTest.php` :: builds the bundled lists with / without DigiCert | `TrustConfig::settingsJson` (DigiCert anchor), `SettingsPage::trustConfig` |
| AC4 | `tests/Integration/TrustTest.php` :: AC4; `tests/Unit/TrustConfigTest.php` :: AC4; :: checks with the settings it is given | `TrustConfig` (custom replaces), `Checker::check` |
| AC5 | `tests/Integration/TrustTest.php` :: AC5 | `src/SettingsPage.php` `SettingsPage::sanitizeCustom` |
| AC6 | `tests/Integration/TrustTest.php` :: AC6; `tests/Unit/TrustConfigTest.php` :: AC6 | `TrustConfig::build` (`none`), `UploadHook` (`TRUST_FAILED_OPTION`), `SettingsPage::trustNotice` |
| AC7 | `tests/Integration/TrustTest.php` :: AC7 | `src/Display.php` `Display::checkedLine`, `Display::read` (`trust`) |
| AC8 | `tests/Integration/TrustTest.php` :: AC8 | `SettingsPage::addPage`, `SettingsPage::render` (`esc_textarea`) |
| AC9 | `tests/Unit/TrustConfigTest.php` :: AC9; `tests/Integration/TrustTest.php` :: AC9 | `TrustConfig::isStale`, `SettingsPage::render` |
