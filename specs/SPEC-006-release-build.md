# SPEC-006: The release build

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

Everything so far runs in the development environment, where `vendor/`
comes from `composer install` and the plugin is a mapped folder. A site
owner installs a zip. `notes/m5-packaging.md` measured what a zip built
naively would hold: the repository's tests, specs and tooling, and a
4.0 MB `vendor/` of which only the verifier's `src/` (680 KB), its
`LICENSE` and Composer's autoloader run. It also measured that Plugin Check
never scans `vendor/`, and that the WordPress Coding Standards' security
sniffs give 641 findings on the verifier's `src/`, none of which points at
a hole in how the plugin uses it (reasoned).

Decisions this spec follows (Maurice, 2026-09-26): the zip carries only
what runs; the shipped verifier is checked with the WPCS security sniffs
directly; findings go to Maurice, and to the verifier as issues when they
are real. `NOTES.md` (Temporary measures): the Plugin Check test's list of
excluded development files is replaced by checking the build.

## Scope

**In scope**

- `.gitattributes` with `export-ignore` for everything that does not ship.
- `composer build`: `git archive` of `HEAD` into
  `build/provemark-c2pa-check/`, `composer install --no-dev
  --optimize-autoloader` there, the verifier trimmed to `src/`, `LICENSE`
  and `composer.json`, and `build/provemark-c2pa-check.zip`.
- A second, clean wp-env (`.wp-env.release.json`, its own port) with no
  mapping: WordPress, Plugin Check, and the built zip as its only other
  plugin.
- Plugin Check on that build, with no excluded files (replacing the
  exclusion list in `tests/Integration/PluginCheckTest.php`).
- A WPCS security scan of the build's `vendor/provemark/c2pa-verifier/src`
  against a reviewed baseline (`tests/wpcs-verifier-baseline.json`: count
  per sniff for v0.2.3); a new sniff or a higher count fails.
- In CI: build, start the clean environment, run the release tests, on one
  PHP version.
- Screenshots of the column, the details and the settings page for the
  README (`docs/screenshots/`), not shipped.

**Out of scope** (each needs its own spec before it may be built)

- A GitHub release, a tag, or a wordpress.org submission (each needs
  Maurice's explicit go).
- Changing the verifier; its findings become issues there.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-006')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — the zip holds what runs, and nothing else**
  - Given `composer build` on a clean checkout
  - When the zip's file list is read
  - Then it has one top folder `provemark-c2pa-check/` with the main file,
    `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`, `composer.json`
    (amendment 1), `src/`, `trust/` and `vendor/` (Composer's autoloader and the verifier's `src/`,
    `LICENSE`, `composer.json`), and none of `tests/`, `specs/`, `notes/`,
    `.github/`, `AI-LOG.md`, `NOTES.md`, `package.json`, `composer.lock`,
    `.wp-env*.json`, `phpstan.neon`, `phpunit.xml`, `pint.json`, dotfiles,
    `*.key`, or the verifier's `src/Cli/` (amendment 4)

- **AC2 — the zip installs and works on a clean WordPress**
  - Given the clean environment with the built zip installed and active
  - When `fixture-signed.jpg` and the Pixel 10 photo are uploaded
  - Then their entries are `Valid` and `Trusted`, equal to the CLI with the
    default settings, and the settings page renders

- **AC3 — Plugin Check passes on the build, with nothing excluded**
  - Given the installed build
  - When `wp plugin check provemark-c2pa-check` runs without exclusions
  - Then it reports no errors and no warnings

- **AC4 — the shipped verifier matches its reviewed WPCS baseline** *(error path)*
  - Given the build's verifier `src/` and the baseline
  - When the WPCS security sniffs run
  - Then every sniff's count is at most its baseline count and no sniff
    outside the baseline appears; a planted `echo $_GET['x'];` in a copy
    makes the test fail

- **AC5 — a build without its verifier fails loudly** *(error path)*
  - Given a build whose `vendor/` is missing
  - When it is activated on the clean WordPress
  - Then it stays active, shows the "bundled libraries are missing" notice,
    and no upload gets an entry (M0.2's behaviour, now on the zip)

## References

- WordPress: [Plugin Check](https://wordpress.org/plugins/plugin-check/),
  [plugin readme](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/);
  WPCS 3 security sniffs; Composer `--no-dev`, `git archive` and
  `.gitattributes` `export-ignore`.
- Oracle: `unzip -l` of the zip; Plugin Check 2.1.0 on the clean
  environment; PHPCS with WPCS 3; the verifier CLI with the default
  settings. Measured in `notes/m5-packaging.md`.
- Reasoned: that wp-env installs a zip listed under `plugins` in a
  `--config` file and gives that environment its own Compose project; to
  be measured first.

## API sketch

```text
composer build          → build/provemark-c2pa-check.zip
npm run release:start   → wp-env start --config .wp-env.release.json
composer test:release   → pest --testsuite=Release (tests/Release/)
```

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed in the draft:

- WPCS as a dev dependency: `wp-coding-standards/wpcs` ^3 under
  `require-dev`, run by the release test on the build only.
- The release job in CI runs on PHP 8.3, the lowest supported.

## Amendments

1. **2026-09-26, approved by Maurice van Loon.** AC1 listed
   `composer.json` among what must not ship. Plugin Check 2.1.0 warns
   `missing_composer_json_file` on the build: a `vendor/` directory
   without `composer.json` (a wordpress.org review guideline, so reviewers
   can see the dependencies). `composer.json` now ships; `composer.lock`
   does not.

2. **2026-09-26, approved by Maurice van Loon** with SPEC-009. The
   verifier ships prefixed, in `vendor-prefixed/provemark/c2pa-verifier/`
   (`src/`, `LICENSE`, `composer.json`), not in `vendor/provemark/`. AC1's
   required and forbidden paths and AC4's scanned path
   (`vendor-prefixed/provemark/c2pa-verifier/src`) follow the move; the
   WPCS baseline is unchanged (641 findings in 10 sniffs).

3. **Withdrawn 2026-09-26 by Maurice van Loon**, the same day: the header
   was removed to keep the plugin wordpress.org-ready (decision 2), and
   AC3 is "no errors or warnings" again. What it said: approved with `Update URI: false`
   (see `NOTES.md`). Plugin Check reports that header as an ERROR,
   `plugin_updater_detected`, because wordpress.org does not allow it.
   While the header is in the plugin, AC3 requires exactly that one
   finding and nothing else (ignoring it would make Plugin Check print
   nothing, not even "Checks complete"). Before a wordpress.org
   submission the header goes and AC3 is "no errors or warnings" again.

4. **2026-09-27, approved by Maurice van Loon.** The
   verifier's command-line tool (`src/Cli/`, one file, `Command.php`) no
   longer ships. The plugin never uses it (measured: no reference in
   `src/`, the main file or the rest of the verifier's `src/`), and it
   holds 13 of the 641 WPCS findings (`fopen`/`fwrite`/`fclose`,
   `file_get_contents`, two `set_error_handler`). The build removes it
   from `vendor/provemark/c2pa-verifier/src/` after `composer install`
   and regenerates the autoloader before Strauss runs, so no classmap or
   alias refers to it (measured in a scratch build on 2026-09-27: no
   autoload file mentions `Cli`; `fixture-signed.jpg` still `Valid`).
   - AC1: `vendor-prefixed/provemark/c2pa-verifier/src/Cli/` is among what
     must not ship.
   - AC4: the baseline drops to 628 findings in 6 sniffs
     (`ExceptionNotEscaped` 608, `base64_encode` 5, `base64_decode` 3,
     `set_error_handler` 4, `fread` 6, `json_encode` 2); verifier v0.2.3.

5. **2026-09-27, approved by Maurice van Loon after his review.** The
   bundled verifier moves to v0.2.4, a security release. AC4's baseline
   rises from 628 to 636 findings in the same 6 sniffs:
   `ExceptionNotEscaped` 608 → 616, every other count unchanged, verifier
   v0.2.4. Measured by running the sniff over the verifier's `src/` at
   both tags: all eight new findings are in `Manifest/Manifest.php`, four
   on each of two new exception messages (a JSON assertion over the size
   limit, and one over the item budget). They are exception messages,
   not output; the plugin shows no verifier exception message except
   `TrustException`'s, through `esc_html`.

6. **2026-09-27, approved by Maurice van Loon** with SPEC-016. `README.md`
   no longer ships: it is the GitHub page, and in the zip it said "not
   released yet" and linked to folders that do not ship. AC1 moves it from
   what must ship to what must not; `readme.txt` is the plugin's
   documentation on wordpress.org.

7. **2026-09-27, approved by Maurice van Loon after his review.** The
   bundled verifier moves to v0.2.5, a security release. AC4's baseline
   rises from 636 to 641 findings in the same 6 sniffs:
   `ExceptionNotEscaped` 616 → 621, every other count unchanged, verifier
   v0.2.5. Measured by running the sniff over the verifier's `src/` at
   both tags:
   - three findings in `Cose/CoseSign1.php`, the chain-placement
     refusals of the verifier's SPEC-047 (a missing-chain message
     replaced by a longer one);
   - two in `Trust/CertificateExtensions.php`, a certificate whose
     extensions cannot be read.

   They are exception messages, not output. The plugin shows no verifier
   exception message except `TrustException`'s, through `esc_html`.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Release/ReleaseTest.php` :: AC1 | `.gitattributes`, `tools/build.sh` |
| AC2 | `tests/Release/ReleaseTest.php` :: AC2 | the built zip; `.wp-env.release.json` |
| AC3 | `tests/Release/ReleaseTest.php` :: AC3 | the built zip (Plugin Check 2.1.0, nothing excluded) |
| AC4 | `tests/Release/ReleaseTest.php` :: AC4 | `tests/wpcs-verifier-baseline.json`; `wpcsFindings()`, `beyondBaseline()` in `tests/Pest.php` |
| AC5 | `tests/Release/ReleaseTest.php` :: AC5 | `provemark-c2pa-check.php` (the missing-vendor notice) |
| Screenshots | — (made by hand in Chrome on the clean environment) | `docs/screenshots/`, `README.md` |
