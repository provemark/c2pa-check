# SPEC-023: The settings page carries the plugin's name

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-28                      |
| Supersedes | SPEC-020's out-of-scope line on the menu label    |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

After SPEC-020 the settings page is still "Settings → C2PA Check", menu
label and heading: "C2PA" first, without "for", the pattern wordpress.org's
first review (2026-09-28) asked to avoid. `readme.txt`, `trust/README.md`
and the trust notice point to it by that name.

The shipped `composer.json` names the package
`provemark/tracefern-image-check`; the review asked about "Provemark" in
anything that belongs to the plugin. The Composer vendor name is free to
choose.

## Scope

**In scope**

- Menu label "Tracefern"; page heading "Tracefern Image Check for C2PA".
- Every "Settings → C2PA Check" becomes "Settings → Tracefern" (readme,
  README, `trust/README.md`, the trust notice, doc comments).
- `composer.json` `name`: `mauricevanloon/tracefern-image-check`.
- `.wordpress-org/screenshot-3.png` taken again with the new heading.

**Out of scope**

- The links to `github.com/provemark/…` (the development location that
  guideline 4 asks for, and the bundled verifier); explained in the reply.

## Acceptance criteria

- **AC1** No shipped text or code says "C2PA Check" (the historical
  records aside).
- **AC2** The settings page's heading is "Tracefern Image Check for C2PA"
  and its menu label "Tracefern".
- **AC3** `composer.json`'s `name` is `mauricevanloon/tracefern-image-check`.

## Traceability

- AC1, AC3: `tests/Unit/NameTest.php`
- AC2: `tests/Release/ReleaseTest.php` (the heading),
  `tests/Unit/NameTest.php` (the menu label)
