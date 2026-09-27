# SPEC-019: A link to the development repository in readme.txt

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

wordpress.org's Detailed Plugin Guidelines, guideline 4, ask for public
access to a plugin's source code and build tools, in one of two ways:
"Include the source code in the deployed plugin", or "A link in the readme
to the development location"; they also recommend documenting how the
development tools are used (read 2026-09-27).

The zip carries the plugin's own source and the bundled verifier's source
(prefixed by Strauss, readable), but not the build: `tools/build.sh`, the
pinned Strauss, `composer.json`'s `extra.strauss` and the tests are
export-ignored (SPEC-006). A reviewer may ask where they are. The
repository `provemark/c2pa-check` has been public since 2026-09-27
(Maurice), and its `README.md` describes `composer build` and Strauss.

## Scope

**In scope**

- `readme.txt`: a `== Development ==` section after the FAQ that names the
  repository (`https://github.com/provemark/c2pa-check`), says it holds
  the source, the tests and the build (`composer build`, which prefixes
  the bundled verifier with Strauss), and names the verifier's own
  repository (`https://github.com/provemark/c2pa-verifier`).
- A new zip, for Maurice to upload as a new version if the review has not
  begun, or for 0.1.1 after approval.

**Out of scope** (each needs its own spec before it may be built)

- A `Plugin URI` header (wordpress.org sets the plugin's page itself).
- A donate link.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-019')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — the readme links the development location**: `readme.txt` has a
  `== Development ==` section with
  `https://github.com/provemark/c2pa-check` and
  `https://github.com/provemark/c2pa-verifier`, and names `composer build`.
- **AC2 — the readme stays valid** *(error path: a readme the directory
  would not parse)*: the existing readme test (limits, the screenshot
  captions, SPEC-016's checks) still passes, and Plugin Check on the
  built zip reports nothing (the release suite).

## References

- wordpress.org: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
  guideline 4; the readme.txt standard (custom sections).
- Oracle: `readme.txt` as the tests read it; Plugin Check on the zip.

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/ReadmeTest.php` :: SPEC-019 AC1 | `readme.txt` (`== Development ==`) |
| AC2 | `tests/Unit/ReadmeTest.php` (every readme test); `tests/Release/ReleaseTest.php` :: AC3 (Plugin Check) | `readme.txt` |
