# SPEC-027: AI in the image's history

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-003 labels an image "AI-generated (signed)" only when an action in
the **active** manifest carries the IPTC digital source type
`trainedAlgorithmicMedia`. Two kinds of real files fall through that rule,
and both were measured on 2026-09-28 (see References):

1. **An AI image that was processed afterwards.** A PNG made by an image
   model, then watermarked and converted by a second service. Each step
   adds a manifest. The model's `c2pa.created` action with
   `trainedAlgorithmicMedia` sits two manifests down the chain, in a
   `parentOf` ingredient. The active manifest only says "edited:
   `composite`". The file is `Trusted`, every ingredient validates, and
   the plugin shows no AI label.
2. **A photo edited with AI.** A camera photo, cropped, then edited with
   a generative tool. The active manifest's `c2pa.edited` action carries
   `compositeWithTrainedAlgorithmicMedia`. SPEC-003 excluded that source
   type on purpose, because no verifying fixture had it then. The file is
   `Trusted`, and the plugin shows nothing.

A site owner who relies on the label is misled in the first case. In the
second case, they miss information that the file states and that the
verifier checked. Both statements are signed. Showing them is no weaker
than what SPEC-003 already shows, provided the manifest that makes the
statement validates.

C2PA 2.4 models an asset's history as a chain of manifests linked by
ingredient assertions (§11.1, ingredients; `relationship` `parentOf`,
`componentOf`, `inputTo`). Each ingredient that points at a manifest in
the store carries its own validation results (§15.11). The verifier
reports these in `toArray()` as `manifests.<label>.ingredients[]`, with
`relationship`, `active_manifest` and `validation_results`. It also
reports them as `validation_results.ingredientDeltas`.

## Scope

**In scope**

- `ai` (stored entry, SPEC-001/003) is also `true` when an action carries
  `trainedAlgorithmicMedia` in any manifest reached from the active
  manifest through `parentOf` ingredients only. Label unchanged:
  "AI-generated (signed)".
- A new key `ai_edited`: `true` when `ai` is `false` and a reached
  manifest carries an AI source type that does not make the image
  AI-generated. That is `compositeWithTrainedAlgorithmicMedia` in any
  reached manifest, or `trainedAlgorithmicMedia` in a manifest reached
  through a `componentOf` or `inputTo` ingredient. New label: "AI-edited
  (signed)".
- **The gate on ingredients.** An ingredient is followed only when its
  `validation_results.activeManifest.failure` is present and is empty or
  holds only `signingCredential.untrusted`. That is the same bar as
  `Valid` for the active manifest. Nothing below an ingredient that is not
  followed is read.
- **The gate on the label** is unchanged (SPEC-003 AC5). Either label is
  shown only when the state is `Trusted` or `Valid`.
- The `tracefern_verdict` filter (SPEC-026) gains the key `ai_edited`,
  with the same gate as `ai`.
- The Media Library's "AI-generated (signed)" filter (SPEC-007) follows
  the widened `ai` with no change of its own.
- `readme.txt`: the FAQ and feature text name both labels.

**Out of scope** (each needs its own spec before it may be built)

- A Media Library filter for "AI-edited".
- `algorithmicMedia`, `compositeSynthetic` and other source types that
  are not generative AI.
- AI statements in ingredients that carry no manifest (an ingredient
  assertion's own `digitalSourceType` field, without a signed manifest
  behind it).
- Re-checking existing attachments automatically. Old entries keep
  showing what they showed; `wp tracefern check` (SPEC-008) updates them.
- Any AI detection that is not a signed statement in the file.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-027')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

The history rules (AC1–AC6) are tested on report arrays in the shape of
`toArray()`, built in the test. AC7 and AC8 run on real fixtures.

- **AC1: AI origin down a parent chain**
  - Given a report where the active manifest's only ingredient is
    `parentOf` manifest B, B's is `parentOf` manifest C, C has
    `c2pa.created` with `trainedAlgorithmicMedia`, and both ingredients
    have an empty failure list
  - When the outcome is computed
  - Then `ai` is `true` and `ai_edited` is `false`

- **AC2: AI-edited in the active manifest or a parent**
  - Given a report whose active manifest has `c2pa.edited` with
    `compositeWithTrainedAlgorithmicMedia`, and a second report where
    that action sits in a `parentOf` ingredient's manifest
  - When the outcomes are computed
  - Then both have `ai` `false` and `ai_edited` `true`

- **AC3: AI as a component or an input**
  - Given a report whose active manifest has a `componentOf` ingredient,
    and a second one with an `inputTo` ingredient, whose manifest has
    `c2pa.created` with `trainedAlgorithmicMedia`
  - When the outcomes are computed
  - Then both have `ai` `false` and `ai_edited` `true`

- **AC4: a failing ingredient is not followed** *(error path)*
  - Given the report of AC1, with B's ingredient failure list holding
    `assertion.dataHash.mismatch`; a variant where it holds
    `signingCredential.untrusted` and `claimSignature.mismatch`; and a
    variant where B's ingredient has no `validation_results`
  - When the outcomes are computed
  - Then each has `ai` `false` and `ai_edited` `false`

- **AC5: an unknown signer on an ingredient is followed**
  - Given the report of AC1, with each ingredient failure list holding
    only `signingCredential.untrusted`
  - When the outcome is computed
  - Then `ai` is `true`

- **AC6: malformed and hostile histories** *(error path)*
  - Given reports where
    - an ingredient points at a label not in `manifests`,
    - two manifests name each other as `parentOf` ingredients (a cycle),
    - `ingredients` is a string, or an ingredient is a number,
    - `relationship` is missing or is an integer,
  - When the outcomes are computed
  - Then the computation ends without an error or a warning. A branch it
    cannot read counts as no AI, and each manifest is read at most once.

- **AC7: the real files**
  - Given the fixtures, checked with the bundled trust settings
    (SPEC-004 defaults):
    - `openai-20260826-c2pa_2x.png`: AI in the active manifest,
    - `c2pa-rs-ocsp.jpg`: `compositeWithTrainedAlgorithmicMedia` in the
      active manifest, `trainedAlgorithmicMedia` in a `componentOf`
      ingredient whose only failure is `signingCredential.untrusted`,
    - `fixture-signed.jpg`,
    - `adobe-20260425-lightroom-classic-church.jpg`,
    - `fixture-unsigned.png`
    - and a fixture with AI origin down a `parentOf` chain (see Open
      questions, 1)
  - When they are uploaded
  - Then the entries hold, in order:
    - `ai` `true`, `ai_edited` `false`;
    - `ai` `false`, `ai_edited` `true`;
    - both `false` for the next three;
    - `ai` `true`, `ai_edited` `false` for the chain fixture.
  - And the details and the column show "AI-generated (signed)" or
    "AI-edited (signed)" to match, never both.

- **AC8: the gate and old entries** *(error path)*
  - Given stored entries with `ai_edited` `true` and each state
    `Trusted`, `Valid`, `Invalid`, `none`, `error`; an entry without an
    `ai_edited` key; and one whose `ai_edited` is the string `"true"`
  - When the column, the details and the `tracefern_verdict` filter are
    rendered
  - Then:
    - only `Trusted` and `Valid` show "AI-edited (signed)" and give
      `ai_edited` `true` in the verdict;
    - the entry without the key shows no edited label and gives
      `ai_edited` `false`;
    - the string shows "Result unreadable", as SPEC-003 AC6 does for `ai`.

- **AC9: the tampered file** *(error path)*
  - Given `c2pa-rs-ocsp.jpg` with one byte of image data changed
  - When it is uploaded
  - Then its state is `Invalid`, as the verifier CLI says for the same
    file, and neither label appears.

## References

- **Specification:**
  - C2PA Technical Specification 2.4, ingredients (§11.1, the
    `relationship` values), the actions assertion (`digitalSourceType`)
    and ingredient validation (§15.11);
  - the IPTC Digital Source Type vocabulary,
    `http://cv.iptc.org/newscodes/digitalsourcetype/`: `trainedAlgorithmicMedia`
    and `compositeWithTrainedAlgorithmicMedia` ("the compositing of
    trained algorithmic media with some other media, such as with
    inpainting or outpainting operations").
- **Oracle:** `provemark/c2pa-verifier` v0.2.6 (the bundled version),
  `bin/c2pa-verify --settings <bundled settings> <file>`, measured
  2026-09-28:
  - `c2pa-rs-ocsp.jpg` → `Valid`. Active manifest: `c2pa.opened` and
    `c2pa.edited` with `compositeWithTrainedAlgorithmicMedia`. One
    `componentOf` ingredient whose manifest has `c2pa.created` with
    `trainedAlgorithmicMedia`. Its delta has one failure,
    `signingCredential.untrusted`.
  - A scan of every JPEG, PNG and WebP fixture in this repository and in
    the verifier's `tests/Fixtures/`: no other licensed fixture carries
    AI in an ingredient.
  - Two sample files that C2PA staff shared on 2026-09-28. They are the
    two cases in Problem, both `Trusted` with no ingredient failures. They
    are not in the repository: their licence is unknown.
- **Reasoned:**
  - That a `trainedAlgorithmicMedia` statement anywhere on the `parentOf`
    line makes the image AI-generated, however it was processed later.
  - That `componentOf` and `inputTo` make it AI-edited rather than
    generated.
  - That `signingCredential.untrusted` on an ingredient is the only
    failure a `Valid` active manifest would also tolerate.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Tracefern\ImageCheck;

final class Outcome
{
    public const string COMPOSITE_TRAINED_ALGORITHMIC_MEDIA = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    // fromReport() adds 'ai' and 'ai_edited' from aiHistory($report->toArray())

    /**
     * Walks the active manifest and the manifests its followed ingredients
     * reach, each at most once.
     *
     * @param array<mixed> $report toArray()'s shape
     * @return array{bool, bool} [generated, edited]
     */
    public static function aiHistory(array $report): array;
}

final class Display
{
    // showsAiEditedLabel(): Trusted / Valid with ai_edited === true
    // verdict() adds 'ai_edited'
}
```

## Open questions

1. **Blocker: a real fixture for AC1's case.** No licensed file has AI
   origin down a `parentOf` chain. Proposal: ask C2PA staff whether the
   two shared samples may go in the repository, with the source named.
   Fallback: make one in the verifier repository with its variant script
   (`bin/make-m7-absence-variants.php` makes a two-manifest store with a
   throw-away key outside the repository). That is verifier work, with
   its own step there.
2. **Wording.** Proposal: "AI-edited (signed)", on the same place and
   line as "AI-generated (signed)".
3. **The ingredient bar.** Proposal: as in Scope. The strict
   alternative, an empty failure list only, would hide the label on the
   same file whenever the site has no trust list.
4. **Version.** Proposal: 0.1.3, in line with 0.1.1 for SPEC-026.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
