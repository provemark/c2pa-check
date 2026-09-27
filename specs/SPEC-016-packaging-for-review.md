# SPEC-016: Packaging for review

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | SPEC-006 AC1 in part: `README.md` no longer ships |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The final review of 2026-09-27 read the zip as a wordpress.org reviewer
would and found four things in the package, not in the code:

1. **A generated file that writes and runs PHP.** Strauss leaves
   `vendor/composer/autoload_aliases.php` in the build: its
   `AliasAutoloader::load()` writes PHP to `autoload_alias.php` in the
   plugin folder, `include`s it and deletes it. Measured in the zip of
   `3b29bea`: neither `vendor/autoload.php`, `autoload_real.php` nor
   `autoload_static.php` refers to it, so it never runs; but a reviewer who
   searches for `file_put_contents` next to `include` flags it, and it
   would alias the unprefixed verifier names that SPEC-009 prefixed away.
2. **A `README.md` that is not true in the zip.** Measured: it says
   "Status: … not released yet" and links to `specs/`, `notes/`, `tests/`,
   `.wordpress-org/` and `AI-LOG.md`, none of which ship. On wordpress.org
   `readme.txt` is the plugin's documentation; `README.md` is the GitHub
   page.
3. **`readme.txt` has an Upgrade Notice for the first version** ("First
   version."), which says nothing to anyone upgrading.
4. **Questions a reviewer may ask, not yet answered in
   `notes/wporg-review.md`**: the `.pem` files in `trust/` (not in the
   handbook's list of file types, plain-text certificates); the brand in
   the slug (guideline 17); the hook callbacks, which are first-class
   callables and so cannot be removed by another plugin. And the readme
   does not say that "AI-generated (signed)" next to "Intact: signer not
   trusted" is a claim nobody on the trust list vouches for.

## Scope

**In scope**

- `tools/build.sh` removes `vendor/composer/autoload_aliases.php` after
  Strauss.
- `.gitattributes`: `README.md export-ignore`; SPEC-006 AC1's list moves it
  from what must ship to what must not.
- `readme.txt`: the "Upgrade Notice" section removed; one sentence in the
  FAQ on "AI-generated (signed)": with "Intact: signer not trusted" the
  claim comes from a signer nobody on the trust list vouches for.
- `notes/wporg-review.md`: answers on the `.pem` files, the slug and the
  callbacks.

**Out of scope** (each needs its own spec before it may be built)

- A "Development" link in `readme.txt` (when the repository is public).
- Removing Composer's `platform_check.php` (the main file's PHP guard,
  SPEC-015, runs before it).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-016')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

(For this spec: in `tests/Release` against the built zip, and
`tests/Unit/ReadmeTest.php`.)

- **AC1 — no code in the zip writes PHP and includes it** *(error path:
  the finding)*: `vendor/composer/autoload_aliases.php` is not in the zip,
  and no PHP file in it contains both `file_put_contents(` and `include`;
  the release suite, SPEC-009 AC3 (another copy of the verifier) included,
  stays green.
- **AC2 — `README.md` does not ship**: it is not in the zip; SPEC-006 AC1
  otherwise unchanged.
- **AC3 — no Upgrade Notice, and the AI claim explained**: `readme.txt`
  has no `== Upgrade Notice ==` section, and its FAQ on "AI-generated
  (signed)" names "Intact: signer not trusted".
- **AC4 — the review answers are written**: `notes/wporg-review.md` has a
  section each on `trust/*.pem`, the slug and the callbacks (a test that
  the three headings exist).

## References

- WordPress plugin handbook: Detailed Plugin Guidelines 17 (trademarks in
  slugs); the review team's common issues (files that write and include
  code); "Plugin readmes" (sections of `readme.txt`).
- Oracle: `unzip -Z1` and `unzip -p` of the built zip.

## Open questions

None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Release/ReleaseTest.php` :: SPEC-016 AC1 | `tools/build.sh` (`autoload_aliases.php` removed after Strauss) |
| AC2 | `tests/Release/ReleaseTest.php` :: SPEC-016 AC2, AC1 (SPEC-006, `README.md` forbidden) | `.gitattributes` |
| AC3 | `tests/Unit/ReadmeTest.php` :: SPEC-016 AC3 | `readme.txt` |
| AC4 | `tests/Unit/ReadmeTest.php` :: SPEC-016 AC4 | `notes/wporg-review.md` |
