# SPEC-003: The AI label

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Site owners, and WordPress itself (Trac #65952, WordPress/ai#1058), want
to know whether an image says it was made by generative AI. A C2PA
manifest can say so: an action in its actions assertion carries the IPTC
digital source type `trainedAlgorithmicMedia`. That statement is only
worth showing when the manifest verifies; on an `Invalid` file it is a
claim nobody can stand behind, and showing it as a fact would be exactly
the verdict the verifier did not give.

`notes/m3-ai-label.md` measured where the statement sits
(`VerificationReport::toArray()`, the active manifest's `c2pa.actions` or
`c2pa.actions.v2`, `data.actions[].digitalSourceType`, a full IPTC URI),
and found one fixture that is AI-generated and `Valid` (OpenAI) and one
that claims AI and is `Invalid` (Amazon Titan).

Decisions this spec follows (Maurice, 2026-09-26): only the exact URI
`http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
counts, not `compositeWithTrainedAlgorithmicMedia` (no verifying fixture
yet); it counts in any action of the active manifest, not only
`c2pa.created`. The brief: read it from `toArray()`, never from
`$report->store` (`@internal`).

## Scope

**In scope**

- An `ai` key in the stored entry (SPEC-001): `true` when an action in the
  active manifest's actions assertion carries that URI, else `false`;
  computed at upload for every state, from `toArray()`.
- The label "AI-generated (signed)" in the column and the details
  (SPEC-002), shown only when the state is `Trusted` or `Valid` and `ai`
  is `true`.

**Out of scope** (each needs its own spec before it may be built)

- `compositeWithTrainedAlgorithmicMedia`, `algorithmicMedia` and other
  source types; AI statements in ingredients.
- Re-checking attachments uploaded before this spec (their entries have no
  `ai` key and show no label).
- Any AI detection that is not a signed statement in the file.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-003')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — a verifying AI image is labelled**
  - Given `openai-20260826-c2pa_2x.png` (`Valid`, `c2pa.created` with
    `trainedAlgorithmicMedia`)
  - When it is uploaded
  - Then its entry has `ai` `true`, and the column and the details show
    "AI-generated (signed)"

- **AC2 — an invalid file that claims AI is not labelled** *(error path; amendment 1)*
  - Given `amazon-20240925-titan-g1.png` (`Invalid`, `c2pa.created` with
    `trainedAlgorithmicMedia`), checked with the DigiCert option off
    (SPEC-004; with it on, the file is `Valid` and rightly labelled)
  - When it is uploaded
  - Then its entry has `ai` `true` and state `Invalid`, and neither the
    column nor the details contain "AI-generated"

- **AC3 — a tampered AI image is not labelled** *(error path)*
  - Given a copy of the OpenAI PNG with one byte of image data changed
  - When it is uploaded
  - Then its state is `Invalid`, as the verifier CLI says for the same
    file, and "AI-generated" appears nowhere

- **AC4 — files without the statement get `ai` false**
  - Given `fixture-signed.jpg`, `adobe-20260425-lightroom-classic-church.jpg`,
    `fixture-unsigned.png`, and `c2pa-rs/ocsp.jpg`
    (`compositeWithTrainedAlgorithmicMedia` only)
  - When their outcomes are computed
  - Then `ai` is `false` for each

- **AC5 — the label follows the state, not the flag alone**
  - Given stored entries with `ai` `true` and each state `Trusted`,
    `Valid`, `Invalid`, `none`, `error`
  - When the column and the details are rendered
  - Then only `Trusted` and `Valid` show "AI-generated (signed)"

- **AC6 — older and malformed entries** *(error path)*
  - Given an entry without an `ai` key, and one whose `ai` is the string
    `"true"`
  - When they are rendered
  - Then the first shows its headline without the label; the second shows
    "Result unreadable"

- **AC7 — only the public report**
  - Given the plugin's source files
  - Then none of them reads `->store` (the verifier's `@internal` parse
    model)

## References

- Specification: C2PA Technical Specification 2.4, actions assertion
  (`c2pa.actions`, `c2pa.actions.v2`, `digitalSourceType`); IPTC Digital
  Source Type vocabulary,
  `http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`.
- Oracle: `provemark/c2pa-verifier` v0.2.3 `toArray()` and
  `vendor/bin/c2pa-verify` on the fixtures above (copied from the
  verifier's `writers/` and `c2pa-rs/`, with their licence files: MIT,
  Apache-2.0, Apache-2.0 OR MIT); measured in `notes/m3-ai-label.md`.
- Reasoned: that no other place in a verifying manifest makes a stronger
  AI statement than the actions assertion.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Provemark\C2paCheck;

final class Outcome
{
    public const string TRAINED_ALGORITHMIC_MEDIA = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    // fromReport() adds 'ai' => self::claimsAi($report)
    private static function claimsAi(VerificationReport $report): bool;
}

final class Display
{
    // headline() and details() add the label for Trusted / Valid with ai === true
}
```

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed in the draft:

- Wording and place: "AI-generated (signed)", as a second line in the
  column cell and as the line right under the headline in the details.
- Schema: `schema` stays 1; `ai` is an added key, and an entry without it
  shows no label (AC6).

## Amendments

1. **2026-09-26, approved by Maurice van Loon.** With SPEC-004's default
   settings (bundled C2PA lists and DigiCert), Amazon Titan's expired
   signer is vouched for by a DigiCert timestamp and the file is `Valid`,
   so it carries the AI label. AC2 therefore runs with the DigiCert option
   off, where the file is `Invalid`; AC3 (the tampered OpenAI image) is
   `Invalid` under any settings.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/AiTest.php` :: AC1; `tests/Integration/AiLabelTest.php` :: AC1 | `src/Outcome.php` `Outcome::claimsTrainedAi`; `src/Display.php` `Display::showsAiLabel` |
| AC2 | `tests/Unit/AiTest.php` :: AC2; `tests/Integration/AiLabelTest.php` :: AC2 | `Display::showsAiLabel` (state gate) |
| AC3 | `tests/Unit/AiTest.php` :: AC3; `tests/Integration/AiLabelTest.php` :: AC3 | `Outcome::fromReport`, `Display::showsAiLabel` |
| AC4 | `tests/Unit/AiTest.php` :: AC4 | `Outcome::claimsTrainedAi` (`TRAINED_ALGORITHMIC_MEDIA`, exact) |
| AC5 | `tests/Integration/AiLabelTest.php` :: AC5 | `Display::showsAiLabel` |
| AC6 | `tests/Integration/AiLabelTest.php` :: AC6 | `Display::read` (`ai` optional, bool only) |
| AC7 | `tests/Unit/AiTest.php` :: AC7 | all of `src/` (reads `toArray()` only) |
