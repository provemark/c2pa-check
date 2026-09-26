# SPEC-009: Prefix the bundled verifier's namespace

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

The release zip (SPEC-006) bundles `provemark/c2pa-verifier` under its own
namespace, `Provemark\C2paVerifier`. WordPress loads every active plugin
into one PHP process. If another plugin bundles the same library in another
version, whichever loads first wins: the other plugin then runs against
classes it was not built for, and either may fail in ways its tests never
saw. This is the usual reason WordPress plugins prefix their bundled
dependencies, and a reason a wordpress.org reviewer may ask about.

Measured on 2026-09-26 in a scratch copy of the build: Strauss 0.30.0
(MIT), run after `composer install --no-dev`, moves the verifier to
`vendor-prefixed/` under `Provemark\C2paCheck\Vendor\Provemark\C2paVerifier`,
rewrites the `use` lines in `src/`, removes `vendor/provemark`, and makes
`vendor/autoload.php` load `vendor-prefixed/autoload.php`. After it the
original class does not exist, the prefixed one does,
`InstalledVersions::getPrettyVersion('provemark/c2pa-verifier')` still
gives `v0.2.3`, and a check of `fixture-signed.jpg` gives `Valid`. The
Composer-installed Strauss could not run inside the build folder (its bin
script found the plugin's `vendor/autoload.php` first); the release's
`strauss.phar` (SHA-256 `08c1a8e5…c38c66c96`) could.

## Scope

**In scope**

- The prefixing in the build only (`tools/build.sh`), after `composer
  install --no-dev` and before trimming: the repository, the development
  and test environments and the unit and integration tests keep the
  verifier's own namespace.
- Strauss pinned: `strauss.phar` 0.30.0 fetched by the build from the
  project's GitHub release and checked against its SHA-256 before use;
  kept in `build/.tools/` (gitignored), never shipped.
- The Strauss settings in `composer.json` (`extra.strauss`): target
  `vendor-prefixed`, namespace prefix `Provemark\C2paCheck\Vendor\`, only
  `provemark/c2pa-verifier`, call sites `src`, the main file and
  `uninstall.php`, the original package deleted from `vendor/`.
- The trimming of SPEC-006 applied to `vendor-prefixed/provemark/c2pa-verifier`.
- SPEC-006 amendment 2: AC1's file list and AC4's scanned path follow the
  move.

**Out of scope** (each needs its own spec before it may be built)

- Prefixing anything else (there is nothing else at run time).
- Changing the verifier itself.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-009')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — the zip carries only the prefixed verifier**
  - Given `composer build`
  - When the zip is read
  - Then it has `vendor-prefixed/provemark/c2pa-verifier/src/…` and no
    `vendor/provemark/`; no PHP file in it declares or uses a namespace
    starting with `Provemark\C2paVerifier\`

- **AC2 — the prefixed build works**
  - Given the zip installed on the clean WordPress (SPEC-006)
  - When `fixture-signed.jpg` and the Pixel 10 photo are uploaded
  - Then they are `Valid` and `Trusted`, equal to the CLI, with `verifier`
    `v0.2.3` (SPEC-006 AC2, unchanged)

- **AC3 — another copy of the verifier does not interfere** *(error path)*
  - Given a must-use plugin on the clean WordPress that defines its own
    `Provemark\C2paVerifier\Verifier\Verifier` (whose `verify()` throws)
  - When `fixture-signed.jpg` is uploaded
  - Then its entry is `Valid`, as without that plugin

- **AC4 — a wrong or missing Strauss stops the build** *(error path)*
  - Given a `strauss.phar` whose SHA-256 differs from the pinned one
  - When `composer build` runs
  - Then it fails before prefixing, with a message naming the checksum,
    and leaves no zip

## References

- Strauss: https://github.com/BrianHenryIE/strauss (MIT), release 0.30.0.
- WordPress plugin handbook on bundling libraries and naming collisions
  (reasoned; to cite exactly in the README when built).
- Oracle: `unzip -Z1` of the zip; the release environment of SPEC-006; the
  verifier CLI. Measured in the scratch run described under Problem.

## API sketch

```json
"extra": {
    "strauss": {
        "target_directory": "vendor-prefixed",
        "namespace_prefix": "Provemark\\C2paCheck\\Vendor\\",
        "packages": ["provemark/c2pa-verifier"],
        "update_call_sites": ["src", "provemark-c2pa-check.php", "uninstall.php"],
        "delete_vendor_packages": true
    }
}
```

## Open questions

- **The prefix (non-blocker).** Proposal: `Provemark\C2paCheck\Vendor\`,
  the plugin's own namespace plus `Vendor`, as Strauss's documentation
  suggests.
- **Fetching Strauss in the build (non-blocker).** Proposal: download the
  pinned phar once per machine and check its SHA-256, rather than
  committing an 11.6 MB binary.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
