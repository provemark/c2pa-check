# AI log

This project is built with Claude Code (Anthropic), driven and reviewed by
Maurice van Loon. Every contribution the assistant produces is logged here,
newest at the bottom, in the same commit as the work it describes. What is
*measured* (a command was run) is kept apart from what is *reasoned* (a
conclusion from reading). Decisions are Maurice's; the assistant proposes.

The assistant is not listed as an author in commit metadata; this log and the
README are where the disclosure lives.

## 2026-09-26 — Repository created, start-up decisions (M0.1)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: read the local project brief and start the plugin carefully: walk
  the open decisions one at a time, then set up the repository.
- Produced: `.gitignore` (the local brief, `vendor/`, `node_modules/`, wp-env
  overrides, tool caches, build output, editor files, and `*.key`
  unconditionally); this `AI-LOG.md`; `NOTES.md` with the four decisions
  below and the facts still to be measured. One commit, no remote.
- Read: the verifier's README ("Use", "Public API") and
  `docs/trust-settings.md`; nothing else.
- Measured: the wordpress.org plugins API (`plugins/info/1.2`) returns
  "Plugin not found." for `provemark-c2pa-check`, `provemark-verifier`,
  `provemark-content-credentials-check`, `c2pa-verifier`,
  `content-credentials-check` and `provemark` (published plugins only;
  reserved or pending slugs are not visible there). Local tools: PHP 8.5.8,
  Composer 2.10.2, Node 26.7.0, Docker 29.6.2 with its daemon not running.
- Reasoned, not measured: the wordpress.org guidelines on licences,
  trademarks (guideline 17) and bundled data; that WordPress Playground runs
  PHP as WebAssembly, so its OpenSSL does not prove what a real host does;
  that WordPress's test library and Pest 4 need different PHPUnit versions.
- Decided by Maurice: (1) trust list: bundle the two official C2PA lists with
  their date, an admin-pasted settings JSON replaces them, the DigiCert
  Trusted Root G4 TSA anchor behind its own option, on by default;
  (2) distribution: built wordpress.org-ready, released on GitHub first,
  submission decided after M5; (3) name "Provemark C2PA Check", slug and text
  domain `provemark-c2pa-check`, namespace `Provemark\C2paCheck`, prefix
  `provemark_c2pa_`; (4) tests: Pest for units, wp-env for integration,
  driven through WP-CLI.

## 2026-09-26 — M0.2: an empty plugin

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a plugin skeleton that loads the verifier and nothing else.
- Produced: `composer.json` (`provemark/c2pa-check`, type `wordpress-plugin`,
  MIT, `php ^8.3`, the only runtime dependency `provemark/c2pa-verifier
  ^0.2.3`, PSR-4 `Provemark\C2paCheck\` from `src/`, `config.platform.php`
  8.3.0 so the lock file resolves for the lowest supported PHP);
  `composer.lock`; `provemark-c2pa-check.php` (plugin header, `ABSPATH`
  guard, Composer autoloader; without `vendor/` one escaped admin notice and
  no fatal error); `LICENSE` (MIT, copied from the verifier); `README.md`
  with the disclosure sentence; `NOTES.md` updated.
- Measured: `composer validate --strict` valid; `composer install` installs
  `provemark/c2pa-verifier` v0.2.3; `php -l` clean. Outside WordPress, with
  stubbed `add_action` / `esc_html__`: with `vendor/` the verifier class
  loads; without it, requiring the file registers one `admin_notices`
  callback that prints the notice, and nothing fatal; without `ABSPATH` the
  file exits before doing anything. The handbook sets no PHP floor for
  plugins; `wp_get_original_image_path()` since 5.3.0; current WordPress
  7.1.2.
- Not measured: activation in a real WordPress. Docker's daemon was not
  running, so that proof moves to M0.3 (wp-env).
- Decided by Maurice: M0.2 as proposed. `Requires at least: 7.1` (only what
  is tested) is the assistant's choice within that approval, open to change.

## 2026-09-26 — M0.2 addendum: activation measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: run Docker through Colima and prove that the empty plugin
  activates in a real WordPress, recorded apart from M0.3.
- Produced: no code. Local environment only: Colima started; a symlink
  `~/.docker/cli-plugins/docker-compose` to Homebrew's `docker-compose`, so
  that `docker compose` (which wp-env calls) resolves.
- Measured, with `WP_ENV_PHP_VERSION=8.3 npx @wordpress/env start` and no
  `.wp-env.json`: WordPress 7.1.2 on PHP 8.3.35. `wp plugin deactivate` and
  `wp plugin activate` both succeed; after activation
  `class_exists(Provemark\C2paVerifier\Verifier\Verifier::class)` is true.
  With `vendor/` moved aside the plugin stays active, nothing fatal, and
  `do_action('admin_notices')` prints the one notice. `vendor/` restored.
- Found: without a config file wp-env mounts the plugin under the folder
  name (`C2PA_Verifier_WP`), not the slug; `.wp-env.json` in M0.3 maps it
  to `provemark-c2pa-check`.
- Decided by Maurice: Colima instead of Docker Desktop; the compose symlink;
  this measurement as its own commit.

## 2026-09-26 — M0.3: test setup and code quality, local

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the verifier's tooling plus what WordPress needs, and a first
  integration test, seen red before green. CI is M0.4.
- Produced: dev dependencies `pestphp/pest` 4.7.8, `laravel/pint` 1.32.1,
  `phpstan/phpstan` 2.2.16, `szepeviktor/phpstan-wordpress` 2.0.4 (with
  `php-stubs/wordpress-stubs` 7.1.0, so PHPStan knows WordPress functions
  without `ignoreErrors`); `pint.json` copied from the verifier;
  `phpstan.neon` (level max, no ignoreErrors); `phpunit.xml` with suites
  Unit and Integration; `tests/Pest.php` with a `wpCli()` helper that runs
  WP-CLI through the local wp-env binary; `tests/Integration/ActivationTest.php`;
  `package.json` pinning `@wordpress/env` 11.16.0; `.wp-env.json` (plugin
  mapped as `provemark-c2pa-check`, PHP 8.3, no tests environment);
  composer scripts `format`, `lint`, `analyse`, `test`, `test:integration`,
  `check` (= lint, analyse, test). Pint reformatted the main plugin file
  (spacing only; the header is unchanged).
- Measured: the first version of the test only asserted `is-active`; with
  `.wp-env.json` in place it stayed red because wp-env does not activate a
  mapped plugin. The test now deactivates, activates and checks
  `is-active`, which is the claim itself. That version: **red** without
  `.wp-env.json` ("Warning: The 'provemark-c2pa-check' plugin could not be
  found. Error: No plugins activated.", 1 failed); **green** with it (1
  passed, 2 assertions), PHP 8.3.35 in the container. `composer check`:
  lint clean, PHPStan max "No errors", unit suite "No tests found" with
  exit 0 under the flag below. Without that flag Pest exits 1 on the empty
  suite. npm 11 blocked the install script of `fs-ext-extra-prebuilt` (a
  wp-env dependency); not approved, and wp-env works without it.
- Decided by Maurice: M0.3 as proposed; for the empty unit suite, the
  temporary `--do-not-fail-on-empty-test-suite` flag, to be removed in M1
  (recorded in `NOTES.md`).

## 2026-09-26 — M0.4: spec template

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the verifier's spec template, adapted to this plugin.
- Produced: `specs/TEMPLATE.md`, copied from the verifier with the same
  structure (Problem, Scope, Behavior, References, API sketch, Open
  questions, Traceability) and four changes: Problem points at `NOTES.md`
  and the WordPress developer documentation as well as the C2PA
  specification; Behavior says which test suite a criterion belongs in and
  spells out this plugin's fail-closed and escaping rules; References gives
  the verifier's `c2pa-verify` as the example oracle, with the WordPress and
  PHP version measured on; the API sketch namespace is `Provemark\C2paCheck`.
- Not taken over: the verifier's `bin/spec-check.php` (a traceability
  checker with its own tests). Reasoned: with an estimated five or six specs
  the Traceability table can be kept by hand; it can be added later.
- Measured: nothing; documentation only.
- Decided by Maurice: M0.4 as proposed, without `spec-check.php`.

## 2026-09-26 — M0.5: Plugin Check and readme.txt

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: run WordPress's Plugin Check as a test, seen red first, and add a
  `readme.txt` in wordpress.org format.
- Produced: Plugin Check 2.1.0 pinned in `.wp-env.json` by download URL;
  `tests/Integration/PluginCheckTest.php` (no ERROR or WARNING rows, and
  "Checks complete" in the output), with development files excluded by
  name; `readme.txt` (no `Contributors` line: no wordpress.org account);
  `NOTES.md` updated.
- Measured: Plugin Check on the working tree without `readme.txt`: it read
  `README.md` as the readme and gave three errors (`missing_readme_header_tested`,
  `no_license`, `no_stable_tag`) and one warning (short description over
  150 characters); it also flagged `.wp-env.json`, `.gitignore`, `.idea/`
  (hidden files) and `AI-LOG.md`, `NOTES.md` and a local gitignored file (unexpected
  markdown). With the exclusions the test was **red** on the readme
  findings only (1 failed); with `readme.txt` **green** (2 passed,
  4 assertions), "Success: Checks complete. No errors found."; `License: MIT`
  accepted. A probe file with an unescaped `echo $_GET[...]` gave one error
  and four warnings in `src/` and nothing in `vendor/`: Plugin Check does
  not scan `vendor/`, so the shipped verifier is not covered (open for M5).
  `wp dist-archive` is not available in wp-env's WP-CLI. `composer check`
  green.
- Decided by Maurice: M0.5 as proposed; no wordpress.org account, GitHub
  only for now; exclude development files in the test until M5 checks the
  built release.

## 2026-09-26 — M0.6: CI on GitHub

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: CI with the same commands as locally on PHP 8.3, 8.4 and 8.5, in
  a private repository `provemark/c2pa-check`.
- Produced: `.github/workflows/ci.yml` with three jobs: `check` (composer
  validate, install from the committed lock, `composer check`) and
  `integration` (wp-env with `WP_ENV_PHP_VERSION` from the matrix, a step
  that fails unless the container's PHP equals the matrix version, then
  `composer test:integration`), both on 8.3/8.4/8.5 with `fail-fast: false`
  on `ubuntu-24.04`; and `all-green`, which succeeds only when both
  succeeded. `npm ci --ignore-scripts` so that every npm version behaves as
  npm 11 did locally. Action versions: checkout v7, cache v6 and
  setup-php v2 as in the verifier; setup-node v7 (latest release, measured).
- Also, before the first push: the Plugin Check test named a local
  gitignored file in its exclusion list, and the M0.5 log entry named it
  too. The test now excludes whatever git ignores in the plugin root
  (`git ls-files --others --ignored --exclude-standard`) and `.github/`;
  the M0.5 commit was amended, as it had never been pushed.
- Measured locally: the version check prints `8.3` in the container and
  `grep -x '8.5'` on it exits 1; YAML parses; integration tests green,
  `composer check` green. The CI run itself is measured after the push and
  recorded in the next entry.
- Decided by Maurice: M0.6 as proposed; the repository private under
  `provemark`.

## 2026-09-26 — M0.6: first CI runs

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: get the first CI run green; nothing reverted silently.
- Measured: run 36221589081 (`e152697`): `integration` **green** on PHP
  8.3, 8.4 and 8.5, and the version step passed, so `WP_ENV_PHP_VERSION`
  does override `.wp-env.json`; `check` **red** on all three: "Path
  .../src does not exist" (git keeps no empty directory; locally `src/`
  existed). Fix one, `src/.gitkeep` (`bc88fee`): `composer check` green in
  a clean clone, but run 36221758918 then had `check` green and
  `integration` **red** on all three: Plugin Check "ERROR, hidden_files" on
  `src/.gitkeep`, which would ship. The clean-clone check had covered only
  `composer check`, not the integration suite.
- Produced: `src/.gitkeep` removed again; `src` taken out of
  `phpstan.neon`'s paths until M1 adds the first class (recorded under
  Temporary measures in `NOTES.md`). Locally, with no `src/` present as in
  CI: `composer check` green, integration 2 passed.
- Decided by Maurice: nothing new; a fix within M0.6.

## 2026-09-26 — M1.0: which file is the original at upload

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: measure, before SPEC-001, which hook and function give the
  untouched uploaded file on every upload route, including WordPress
  7.1's client-side media processing.
- Produced: `notes/m1-original-file.md` (method, results, conclusion, the
  probe's source); `notes` added to the Plugin Check test's excluded
  development directories (reasoned: notes never ship; not seen red).
  The probe itself ran as a must-use plugin through an untracked
  `.wp-env.override.json` and was removed afterwards, with the fixture
  copies and the application password used for the REST route.
- Measured: see the note. In short: at `add_attachment`,
  `get_attached_file()` was the uploaded file, byte-identical, on all 23
  uploads over WP-CLI, REST, Media → Add New and the block editor with
  client-side processing on and off; later hooks see `-scaled`;
  `wp_get_original_image_path()` returns `-scaled` in some intermediate
  metadata hooks; `wp_handle_upload` fires for every image size the
  browser sideloads. Client-side processing is on by default for HTTPS
  sites in Chromium 137+ on block editor screens (read in core, and
  `crossOriginIsolated` measured in Chrome 153). Verification took
  12–40 ms and 6 MB peak memory in the container.
  Also: CI run 36221904321 on `ac72565` (end of M0) was green on all
  jobs.
- Not measured: the `-rotated` case (no fixture with an EXIF
  orientation); the Media Library modal inside the block editor; HEIC
  (out of scope; converted in the browser before upload, read in core).
- Browser: Chrome through the browser extension, logged in to the local
  wp-env site with wp-env's default test account.
- Decided by Maurice: the measurement as proposed.

## 2026-09-26 — SPEC-001 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: write SPEC-001 (verify on upload, store the result) from the M1.0
  measurement, status `draft`, for Maurice to approve.
- Produced: `specs/SPEC-001-verify-on-upload.md`: `add_attachment` on
  `get_attached_file()`, JPEG/PNG/WebP only, no trust settings until
  SPEC-004; the stored entry's keys; nine acceptance criteria (four error
  paths); an illustrative API (`Outcome`, `Checker`, `UploadHook`); three
  non-blocking open questions (remote manifests, hook priority, codes for
  `Valid`).
- Measured, for the spec: with v0.2.3 an unsigned file reports
  `hasManifest` false and state `Invalid` with no statuses; a 12-byte
  truncated JPEG reports `hasManifest` true, `Invalid` with
  `StatusCode::GeneralError`; `signatureInfo` holds `alg`, `issuer`,
  `common_name`, `cert_serial_number` and `time` only when the timestamp
  validated; the CLI prints `signature_info` inside the active manifest,
  not at the top level; `InstalledVersions::getPrettyVersion()` gives
  `v0.2.3`.
- Decided by Maurice: pushing to the private repository without asking
  for each commit within M1. The spec itself awaits approval.

## 2026-09-26 — SPEC-001 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: take the three proposals and mark the spec approved.
- Produced: SPEC-001 status `approved`; the three open questions resolved
  as proposed (remote manifest: `none` plus `remote_manifest_url`; hook
  priority 10; `codes` for `Invalid` only); a `remote_manifest_url` key and
  AC10 for it.
- Measured: `writers/adobe-20260304-photoshop-remote-manifest.jpg` gives
  `has_manifest` false, state `Invalid`, no statuses, and a
  `remoteManifestUrl` on `cai-manifests.adobe.com`, with v0.2.3.
- Decided by Maurice: SPEC-001 approved, with the three proposals.

## 2026-09-26 — SPEC-001 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: implement the approved SPEC-001, tests first and seen red.
- Produced: `src/Outcome.php`, `src/Checker.php`, `src/UploadHook.php`;
  the main plugin file registers the hook (and lost its global variable);
  unit tests `OutcomeTest`, `CheckerTest`, `NoNetworkTest`; integration
  test `UploadTest`; oracle helpers in `tests/Pest.php` (the CLI's report
  turned into the expected entry); fixtures copied from the verifier with
  a README naming source and licence (one CC BY-SA 4.0, one MIT with its
  licence file); `tests/tmp/` gitignored. `src` back in `phpstan.neon` and
  the empty-suite flag removed, as `NOTES.md` required. Traceability filled.
- Measured: before any `src/` code, unit **red** (20 failed, "Class ... not
  found") and integration **red** (12 failed); AC5 and the no-network check
  were green before code, as a criterion about what must not happen cannot
  fail when nothing happens. After: `composer check` green (21 unit tests,
  also with `--parallel`), integration 16 passed, Plugin Check clean.
- Found on the way, each fixed at the source:
  - Pest defines `fixture()`; the helper is `fixturePath()`.
  - wp-env wraps WP-CLI output in status lines; `wpCli()` drops them.
  - `result->statuses` holds successes and informational codes too; the
    CLI's `validation_status` (which AC3 compares with) holds failures
    only, active manifest first. `codes` now comes from
    `toArray()['validation_status']`. The spec's table row still says
    "every status": an amendment is proposed to Maurice, not made.
  - Plugin Check flagged `fopen`/`fclose` (WP_Filesystem has no stream API;
    the verifier takes a stream): a `phpcs:ignore` on those two lines with
    the reason. And the main file's global variable: removed.
  - Pest's `arch()->not->toUse()` did not see a namespaced call to
    `wp_remote_get` (a planted call left it green); replaced by a token scan,
    seen red with the planted call and green without.
  - PHPStan max: mixed values from JSON and `toArray()` narrowed with
    checks; the verifier default is a typed static method, not an inline
    `@var`.
- Decided by Maurice: SPEC-001 approved earlier; the `codes` amendment and
  the `phpcs:ignore` are put to him.

## 2026-09-26 — SPEC-001 implemented; M1 done

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: apply the `codes` amendment and mark SPEC-001 implemented.
- Produced: SPEC-001 amendment 1 (`codes` are the report's
  `validation_status` failures, as the CLI gives them); status
  `implemented`.
- Measured: CI run 36223978023 on the SPEC-001 commit green on all jobs
  (check and integration, PHP 8.3 / 8.4 / 8.5).
- Decided by Maurice: amendment 1; the `phpcs:ignore` on `fopen`/`fclose`
  in `Checker` (WP_Filesystem has no stream API); SPEC-001 implemented.

## 2026-09-26 — M2.0 measured, SPEC-002 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: measure where the result can be shown, then draft SPEC-002.
- Produced: `notes/m2-where-to-show.md`; `specs/SPEC-002-show-the-result.md`
  (status `draft`): a list-mode column and an attachment-details row,
  wording per state, `Display::text()` that replaces control and Unicode
  direction characters by U+FFFD before `esc_html`, nine criteria (two
  error paths plus a malformed-entry path), three non-blocking open
  questions.
- Measured: `manage_media_columns` / `manage_media_custom_column` give a
  visible column in list mode; `attachment_fields_to_edit` gives a row on
  Edit Media, in the Media Library modal and in the block editor's
  `wp.media` modal. Read in core: `get_compat_media_markup()` inserts an
  `html` field and its label unescaped.
- Also: PhpStorm on this machine did not resolve WordPress functions; the
  WordPress stubs file (5.7 MB) is above PhpStorm's default 2.5 MB indexing
  limit. `idea.max.intellisense.filesize=8000` was added to Maurice's
  PhpStorm custom properties, and Maurice enabled PhpStorm's WordPress
  integration pointing at wp-env's WordPress; afterwards PhpStorm reported
  no undefined WordPress functions in `src/UploadHook.php`.
- Decided by Maurice: M2 approach as proposed; pushing within M2 without
  asking per commit. The spec awaits approval.
