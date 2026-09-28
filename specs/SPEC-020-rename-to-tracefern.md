# SPEC-020: Rename the plugin to Tracefern Image Check for C2PA

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-28                      |
| Supersedes | the name and slug decided in `NOTES.md` (M0)      |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

wordpress.org's first review (2026-09-28, pre-review of
`provemark-c2pa-check`) flagged the name "Provemark C2PA Check":

- "ProveMark" was read as an existing provenance-verification brand that
  the submitter was not shown to own. Measured (web search, 2026-09-28):
  Blockchain Commons publishes "Provenance Marks" at
  `developer.blockchaincommons.com/provemark/`, the same field. The
  account's e-mail address is not on a Provemark domain, so ownership
  cannot be shown the way the directory checks it.
- "C2PA" came first, without wording that makes clear the plugin is not
  made by the C2PA; the review asks for a trademark or project name after
  "for" or "with" (guideline 17).

## Decision (Maurice, 2026-09-28)

A coined name that belongs to no one else, with C2PA at the end:
**Tracefern Image Check for C2PA**. Measured on 2026-09-28: no plugin
named or slugged "tracefern" in the directory API
(`plugin_information` and `query_plugins`); a web search found only a
housing subdivision in Alabama. Reasoned: closed or reserved slugs do not
show in the API, and this is not a legal trademark search.

The GitHub repository was renamed from `provemark/c2pa-check` to
`provemark/tracefern-image-check` (Maurice's go, 2026-09-28; GitHub
redirects the old URL).

## Scope

**In scope**

| what | was | becomes |
|---|---|---|
| display name | Provemark C2PA Check | Tracefern Image Check for C2PA |
| slug, text domain, main file, zip | `provemark-c2pa-check` | `tracefern-image-check-for-c2pa` |
| namespace (and Strauss prefix) | `Provemark\C2paCheck` | `Tracefern\ImageCheck` |
| options, meta keys, cron event, settings group | `provemark_c2pa_…`, `_provemark_c2pa_…` | `tracefern_…`, `_tracefern_…` |
| CSS classes, WP-CLI command | `provemark-c2pa…`, `wp provemark-c2pa` | `tracefern…`, `wp tracefern` |
| Composer package, npm package | `provemark/c2pa-check` | `provemark/tracefern-image-check` |
| repository links | `github.com/provemark/c2pa-check` | `github.com/provemark/tracefern-image-check` |

Tests, CI, build script, wp-env files, README, `readme.txt` and the
current notes follow.

**Out of scope**

- The bundled verifier keeps its name: `provemark/c2pa-verifier`,
  `Provemark\C2paVerifier`. Naming a dependency is a fact, not use as the
  plugin's name.
- No migration of stored data: no version has been released; the only
  copy outside this repository is the zip under review (reasoned).
- Historical records (`AI-LOG.md`, earlier specs, `build/submitted/`) keep
  the old name; they describe what happened then.
- The settings menu label "C2PA Check" stays: it describes the page.
- Icons and banners: checked separately; changed only if they show the
  old name.
- The other review points (sanitize callback, admin notice) are separate
  steps.

## Acceptance criteria

- **AC1** The main file is `tracefern-image-check-for-c2pa.php`; its
  `Plugin Name` is `Tracefern Image Check for C2PA` and its `Text Domain`
  is `tracefern-image-check-for-c2pa`; `readme.txt`'s title is
  `=== Tracefern Image Check for C2PA ===`.
- **AC2** Outside the historical records, no tracked file contains
  "provemark" except as part of `provemark/c2pa-verifier`,
  `Provemark\C2paVerifier` (with any escaping), `provemark.github.io`,
  `provemark/content-credentials`, `provemark/tracefern-image-check` or
  in the notes that tell the history of the name.
- **AC3** `composer check`, the integration suite and the release suite
  pass; the release zip is `build/tracefern-image-check-for-c2pa.zip` and
  installs and works on a clean WordPress.

## Traceability

- AC1, AC2: `tests/Unit/NameTest.php`
- AC3: the existing suites, run after the rename
