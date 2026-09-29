# SPEC-030: A weekly check for what goes out of date

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-29                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Three things the plugin depends on change without anyone telling the
repository, and `notes/wporg-submission.md` §5 lists each as a reason for
a release:

- **WordPress.** `readme.txt` says `Tested up to: 7.1`. When a new major
  version ships, wordpress.org shows the plugin as not tested with it
  until that line changes (after a test run).
- **The C2PA trust lists.** `trust/README.md` names the bundled lists'
  source commit in `c2pa-org/conformance-public` (`99927ca`, 2026-08-14).
  A new list adds or removes certificate authorities, and so changes
  which images come out `Trusted` (SPEC-004).
- **The verifier.** `composer.lock` pins `provemark/c2pa-verifier`
  (`v0.2.6`). A new release can be a security fix, as 0.2.6 was.

Today each is checked only when someone asks (measured this way on
2026-09-29: WordPress 7.1.2, trust lists at `99927ca`, verifier `v0.2.6`,
all current). With no active installs yet there is no hurry, which is
exactly when it goes stale unnoticed.

## Scope

**In scope**

- A script `tools/maintenance-check.php` (not in the release zip:
  `/tools` is `export-ignore`). It reads the three local values from the
  repository (`Tested up to` in `readme.txt`, the commit in
  `trust/README.md`, the verifier's version in `composer.lock`) and the
  three current values from the network (WordPress: the latest release's
  major.minor from `https://api.wordpress.org/core/version-check/1.7/`;
  trust lists: the latest commit touching `trust-list/` in
  `c2pa-org/conformance-public`; verifier: the highest `v*` tag of
  `provemark/c2pa-verifier`). It prints one Markdown line per item that
  is behind, and nothing when all are current.
- The comparison is plain PHP functions without network access, so they
  are unit-tested; fetching is a thin layer around them.
- A workflow `.github/workflows/maintenance.yml`: weekly (Mondays, 06:00
  UTC) and by hand (`workflow_dispatch`). When the script reports items,
  it opens one issue in this repository with label `maintenance`, titled
  `Maintenance: <items>`, with the report as its body; when an open issue
  with that label and title already exists, it opens none. Permissions:
  `contents: read`, `issues: write`, nothing else.
- The script is linted and analysed like the rest: `tools/*.php` in
  PHPStan's paths, and Pint no longer excludes `tools`.

**Out of scope** (each needs its own spec before it may be built)

- Changing anything: no commit, pull request, `Tested up to` edit,
  trust-list update, `composer update` or release. What to do with a
  report is Maurice's call.
- The DigiCert Trusted Root G4 file (a root certificate valid until
  2038; a change there is not a weekly event).
- PHP versions, Plugin Check versions, WP-CLI, Gutenberg issue replies.
- Any network access from the plugin itself (it stays without any;
  `tests/Unit/NoNetworkTest.php` covers `src/` and the main file).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-030')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

(Here "fails closed" means: a check that cannot find out is a red run,
never a silent "all current".)

- **AC1 — all current, no report**
  - Given local and current values that match (`7.1` / `7.1.2`,
    `99927ca` / `99927ca…`, `v0.2.6` / `v0.2.6`)
  - When the comparison runs
  - Then it reports nothing and the script exits 0 with no output

- **AC2 — a new WordPress major version**
  - Given `Tested up to: 7.1` and a latest release `7.2`
  - Then the report has one line naming both, and that
    `Tested up to` needs a test run before it changes; a latest `7.1.3`
    is not behind

- **AC3 — new trust lists**
  - Given `trust/README.md` at `99927ca` and a latest commit `1a2b3c4…`
    touching `trust-list/`
  - Then one line naming both commits and the commit's date

- **AC4 — a new verifier release**
  - Given `v0.2.6` locked and tags `v0.2.6`, `v0.2.7`, `v0.10.0`
  - Then one line naming `v0.10.0` (tags compared as versions, not as
    text); tags that are not `v<major>.<minor>.<patch>` are ignored

- **AC5 — several behind at once**: one line each, in the order
  WordPress, trust lists, verifier; the issue title lists them
  (`Maintenance: WordPress 7.2, trust lists, verifier v0.2.7`)

- **AC6 — a value that cannot be read** *(error path)*
  - Given a local file without the expected line, or a remote answer that
    is missing, not JSON, or without the expected field
  - When the script runs
  - Then it exits non-zero with a message naming what it could not read,
    prints no report, and the workflow opens no issue (the run is red)

- **AC7 — the workflow is inert on push**: it has no `push` or
  `pull_request` trigger, only `schedule` and `workflow_dispatch`, and
  only the permissions above (read from the YAML in a unit test).

- **AC8 — no duplicate issue**: the workflow's step searches open issues
  with label `maintenance` and the same title before creating one
  (checked once by hand with `workflow_dispatch`, not in Pest).

## References

- WordPress: `https://api.wordpress.org/core/version-check/1.7/`
  (`offers[0].current`); the readme's `Tested up to`
  ([plugin readme](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)).
- GitHub REST API: list commits with `path=trust-list`
  (`GET /repos/c2pa-org/conformance-public/commits`), list tags
  (`GET /repos/provemark/c2pa-verifier/tags`); in the workflow with the
  run's `GITHUB_TOKEN`.
- GitHub Actions: `schedule`, `workflow_dispatch`, `permissions`;
  `gh issue list` / `gh issue create`.
- Measured 2026-09-29: the three current values above.

## API sketch

Illustrative only.

```php
// tools/maintenance-check.php
/** @return list<string> Markdown lines, one per item behind; empty when all are current */
function maintenanceReport(array $local, array $current): array;

function testedUpTo(string $readme): string;            // '7.1'
function bundledTrustCommit(string $trustReadme): string; // '99927ca'
function lockedVerifier(string $composerLock): string;  // 'v0.2.6'
function newestVersionTag(array $tags): ?string;        // 'v0.10.0'
```

## Open questions

- Approved as proposed (2026-09-29): Monday 06:00 UTC, and a public
  issue in this repository.

## Traceability

Filled at implementation (2026-09-29). Unit tests in
`tests/Unit/MaintenanceCheckTest.php`, group `SPEC-030`. The status
became `implemented` after the manual run for AC8.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | MaintenanceCheckTest :: AC1 (both) | `maintenanceReport()`; `testedUpTo()`, `bundledTrustCommit()`, `lockedVerifier()` |
| AC2                  | MaintenanceCheckTest :: AC2 | `wordpressMajorMinor()`, `maintenanceReport()` |
| AC3                  | MaintenanceCheckTest :: AC3 | `latestTrustCommit()`, `maintenanceReport()` |
| AC4                  | MaintenanceCheckTest :: AC4 | `tagNames()`, `newestVersionTag()` |
| AC5                  | MaintenanceCheckTest :: AC5 | `maintenanceReport()`, `maintenanceTitle()` |
| AC6                  | MaintenanceCheckTest :: AC6 (both; `MAINTENANCE_CHECK_OFFLINE=1` stands in for an unreachable remote) | the readers above; `fetchUrl()`, `main()` (exit 1, nothing on stdout) |
| AC7                  | MaintenanceCheckTest :: AC7 | `.github/workflows/maintenance.yml` (`on`, `permissions`) |
| AC8                  | by hand: run 36529574384 (`workflow_dispatch` on `e23fcc7`, 2026-09-29) green, "All current.", no issue; the duplicate search itself checked on sample data only, since nothing was behind | `.github/workflows/maintenance.yml` (issue search before `gh issue create`) |

Also checked by hand on 2026-09-29: the script against the real sources
reports nothing (all current, exit 0); with older local values it reports
all three; the YAML parses; the duplicate filter's `jq` finds a matching
title and not another. `tools/maintenance-check.php` is in PHPStan's paths
and linted by Pint. The HTTP status is read from `stream_get_meta_data()`,
because PHP 8.5 deprecates `$http_response_header` even where it is not
reached.
