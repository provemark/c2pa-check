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

## 2026-09-26 — SPEC-002 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: take the three proposals and mark SPEC-002 approved.
- Produced: SPEC-002 status `approved`, open questions resolved as proposed
  (no codes for `Valid`; `signed_at` shown as is, escaped; wording as
  drafted).
- Measured: nothing.
- Decided by Maurice: SPEC-002 approved with the three proposals.

## 2026-09-26 — SPEC-002 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: implement the approved SPEC-002, tests first and seen red.
- Produced: `src/Display.php` (wording, reading a stored entry strictly,
  `text()`: controls and direction characters to U+FFFD, then `esc_html`),
  `src/MediaScreens.php` (column and attachment-details row, output also
  through `wp_kses_post`); typed class constants in `UploadHook` and
  `Outcome`; `tests/Integration/DisplayTest.php`; the WP-CLI helpers moved
  from `UploadTest.php` to `tests/Pest.php` (shared by two files, so
  parallel runs see them) with render helpers (`columnHtml`,
  `detailsHtml`, `visibleText`, `activeMarkup` via DOMDocument).
  Traceability filled.
- Measured: before `src/Display.php` and `src/MediaScreens.php`, the 25
  SPEC-002 tests **red** (empty output). After: integration 41 passed,
  `composer check` green (unit 21, also parallel), Plugin Check clean.
  Removing `esc_html` and the character filter from `Display::text()` made
  AC6 (3 cases) and AC7 **red**, so those tests bind the escaping; restored.
- Found on the way: Plugin Check requires the text domain as a string
  literal in every call (`NonSingularStringLiteralDomain`), so no constant;
  wp-env appends its status line to output without a trailing newline,
  now stripped in `wpCli()`; PHPStan could not narrow types through a
  boolean helper, so the entry fields are checked inline with `is_string`.
- Also: PhpStorm reported `it` / `expect` as undefined in the tests: the
  `vendor/` packages are excluded folders in the IDE project but missing
  from its PHP include path (only the two wp-env WordPress folders are
  there). Maurice to enable "Add packages as libraries" under Settings →
  PHP → Composer; `.idea/` is not edited by the assistant.
- Decided by Maurice: nothing new in this step.

## 2026-09-26 — IDE warnings in the tests

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: no yellow (undefined) functions in the test files in PhpStorm.
- Produced: in the untracked `.idea/` (backups kept outside the repo): the
  62 `vendor/` packages PhpStorm had excluded added to its PHP include path,
  and `tests/` marked as a test source root. In the repository: `ext-dom`
  and `ext-libxml` under `require-dev` (the tests use `DOMDocument`); a
  character class instead of a one-character alternation in `wpCli()`;
  needless braces in interpolated strings removed; the deliberately
  hostile HTML constant in `DisplayTest` marked `// language=TEXT`.
- Measured, through PhpStorm's own inspections: `UploadTest.php`,
  `DisplayTest.php`, `OutcomeTest.php`, `src/Display.php`,
  `src/MediaScreens.php` report nothing; `tests/Pest.php` reports only
  "Can be replaced with 'array'" on four `array<mixed>` docblocks, kept
  because PHPStan at level max requires the value type. `composer check`
  green, integration 41 passed.
- Decided by Maurice: no undefined functions shown in the IDE.

## 2026-09-26 — Faster integration tests

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the integration jobs in CI take long; make them faster.
- Produced: `wpCli()` in `tests/Pest.php` runs `docker exec` on this
  project's wp-env cli container (found by its Compose project label)
  instead of `wp-env run cli`; same user and working directory (the
  container's own, as `wp-env run` uses).
- Measured: one `wp eval` call 1.09 s through `wp-env run`, 0.23 s through
  `docker exec`. Local integration suite 84 s → 27 s (41 passed both
  times). CI before (run 36225996963): integration jobs 202–236 s, of
  which `env:start` 91 s and the tests 86 s (PHP 8.3). After (run
  36226386706): jobs 153–175 s, `env:start` 88 s, tests 41 s. Starting
  wp-env is left as it is.
- Not in the commit that made the change (`Run WP-CLI in the tests through
  docker exec`): this entry, which needed the CI measurement; added here.
- Decided by Maurice: the CI speed-up first.

## 2026-09-26 — SPEC-001 amendment 2: backslashes kept

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: fix the lost backslashes in stored entries, with a test first.
- Produced: `UploadHook` stores both the provisional and the final entry
  through `wp_slash()`; SPEC-001 amendment 2 and AC11; a test file made at
  run time (`backslashRemoteManifestJpeg()`: the remote-manifest fixture
  with one `/` of its XMP URL replaced by `\`, same length, no signature
  involved); AC11 in `tests/Integration/UploadTest.php`; Traceability row.
- Measured: the verifier returns the URL with the backslash. Before the
  fix AC11 **red**: stored `.../manifestsurn-c2pa-...` against
  `.../manifests\urn-c2pa-...`. After: integration 42 passed,
  `composer check` green.
- Decided by Maurice: start with the backslash fix; commit locally, do not
  push until he says so.

## 2026-09-26 — SPEC-002 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-002 implemented (Traceability was filled when it was
  built).
- Produced: SPEC-002 status `implemented`.
- Measured: nothing new; the SPEC-002 build and CI results are in the
  entries above.
- Decided by Maurice: SPEC-002 implemented; not pushed yet.

## 2026-09-26 — M3.0: AI source types measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare M3 (the AI label).
- Produced: `notes/m3-ai-label.md`.
- Measured: see the note: where `digitalSourceType` sits in `toArray()`;
  one `Valid` AI fixture (OpenAI PNG), one `Invalid` one (Amazon Titan
  PNG), `compositeWithTrainedAlgorithmicMedia` only in an `Invalid` c2pa-rs
  fixture; every IPTC URI in both fixture trees uses `http://cv.iptc.org/`.
- Decided by Maurice: prepare M3; not pushed.

## 2026-09-26 — SPEC-003 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: draft SPEC-003 (the AI label) from the M3.0 measurement.
- Produced: `specs/SPEC-003-ai-label.md` (status `draft`): an `ai` key in
  the stored entry from `toArray()`, the label gated on `Trusted` /
  `Valid`, seven criteria (three error paths), two non-blocking open
  questions (wording and place; keeping schema 1).
- Measured: nothing new.
- Decided by Maurice: only the exact `trainedAlgorithmicMedia` URI counts
  (not composite); it counts in any action of the active manifest. Not
  pushed.

## 2026-09-26 — SPEC-003 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: take the two proposals and mark SPEC-003 approved.
- Produced: SPEC-003 status `approved`, open questions resolved as proposed.
- Measured: nothing.
- Decided by Maurice: SPEC-003 approved (label wording and place; schema
  stays 1). Not pushed.

## 2026-09-26 — SPEC-003 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: implement the approved SPEC-003, tests first and seen red.
- Produced: `Outcome` stores `ai` (the exact IPTC trainedAlgorithmicMedia
  URI in any action of the active manifest's `c2pa.actions` /
  `c2pa.actions.v2`, read from `toArray()`); `Display` shows
  "AI-generated (signed)" only for `Trusted` / `Valid` with `ai` true, and
  treats a non-boolean `ai` as unreadable. Tests `tests/Unit/AiTest.php`,
  `tests/Integration/AiLabelTest.php`; the oracle `expectedEntry()` now
  derives `ai` from the CLI's output; `sampleEntry()` moved to
  `tests/Pest.php` for both display test files; `tamperedOpenAiPng()`
  (one IDAT byte changed, CRC recomputed); fixtures OpenAI PNG (MIT),
  Amazon Titan PNG (Apache-2.0) and c2pa-rs `ocsp.jpg` (Apache-2.0 OR MIT)
  with their licence files; fixture README updated. Traceability filled.
- Measured: the tampered OpenAI PNG is `Invalid` at the CLI
  (`assertion.dataHash.mismatch`). Before the code: unit 15 failed,
  integration 13 failed (the new criteria, and SPEC-001's comparisons now
  expecting `ai`); the "no label" cases and the `->store` scan were green,
  as nothing was shown. After: `composer check` green (unit 29, also
  parallel), integration 51 passed. Removing the state gate from
  `showsAiLabel()` made AC2, AC3 and AC5 (Invalid, none, error) **red**;
  a planted `$report->store` made AC7 **red**; both restored.
- Decided by Maurice: SPEC-003 approved earlier; not pushed.

## 2026-09-26 — SPEC-003 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-003 implemented.
- Produced: SPEC-003 status `implemented` (Traceability filled when built).
- Measured: nothing new.
- Decided by Maurice: SPEC-003 implemented; not pushed.

## 2026-09-26 — M4.0: trust lists measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare M4 (trust settings).
- Produced: `notes/m4-trust-settings.md`. The lists and the DigiCert root
  were fetched into a scratch directory, not into the repository.
- Measured: see the note: list sizes and commit (`99927ca`, 2026-08-14),
  DigiCert fingerprint equal to the verifier's docs; verdicts without
  settings, with the C2PA lists, and with DigiCert added (Pixel 10 and
  OpenAI become Trusted; Amazon Titan and c2pa-rs `ocsp.jpg` become Valid
  only with DigiCert); the CLI agrees; about 10 ms to parse the settings.
- Decided by Maurice: prepare M4; not pushed.

## 2026-09-26 — SPEC-004 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: draft SPEC-004 (trust settings) from the M4.0 measurement.
- Produced: `specs/SPEC-004-trust-settings.md` (status `draft`): bundled
  lists in `trust/` (commit `99927ca`, 2026-08-14) with DigiCert, custom
  JSON replacing them, `trust` recorded and shown per image, a settings
  page, an admin notice when a check ran without settings, SPEC-003
  amendment 1 in scope; nine criteria (two error paths); two
  non-blocking open questions.
- Measured: nothing new.
- Decided by Maurice: SPEC-003 AC2 to run with DigiCert off; without
  buildable settings, check without them and record it; record and show
  the trust source; a settings page of its own. Not pushed.

## 2026-09-26 — SPEC-004 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: take the two proposals and mark SPEC-004 approved.
- Produced: SPEC-004 status `approved`, open questions resolved as proposed.
- Measured: nothing.
- Decided by Maurice: SPEC-004 approved (183 days; warning on the
  settings page only). Not pushed.

## 2026-09-26 — SPEC-004 built (tests first); SPEC-003 amendment 1

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: implement the approved SPEC-004, tests first and seen red.
- Produced: `trust/` (the two C2PA lists from commit `99927ca`,
  2026-08-14, and the DigiCert Trusted Root G4, with `trust/README.md`:
  source, commit, CC BY 4.0 attribution, fingerprint); `src/TrustConfig.php`
  (custom settings replace everything, else the bundled lists with or
  without DigiCert, by the verifier's recipe; `none` when nothing can be
  built; `isStale()` at 183 days); `src/SettingsPage.php` (Settings → C2PA
  Check, settings registered on `init` so validation also covers
  `update_option()`, custom option created with autoload off, the notice
  while checks run without settings); `Checker` and `Outcome` take the
  settings and record `trust`; `UploadHook` writes the provisional entry
  before reading the trust lists; `Display` names the trust source and
  treats an unknown `trust` value as unreadable; `readme.txt` gains a
  "Trust lists" section with the attribution and a current description.
  Tests `tests/Unit/TrustConfigTest.php`, `tests/Integration/TrustTest.php`;
  the integration oracle now runs the CLI with the default settings file;
  SPEC-003 AC2 runs with DigiCert off (amendment 1). Fixtures: the Pixel 10
  photo (public domain) and the c2pa-rs public test roots. Traceability
  filled.
- Measured: custom settings with only the test roots make
  `fixture-signed.jpg` Trusted and leave the Pixel photo Invalid (CLI).
  Before the code: unit 8 failed, integration 22 failed; AC7 without a
  `trust` key, AC9 and SPEC-003 AC2 were green before the code. After:
  `composer check` green (unit 37, also parallel), integration 66 passed,
  Plugin Check clean. Mutations, each restored and checked with `cmp`:
  `isStale()` always true made AC9 **red**; skipping validation made both
  AC5 cases **red**; dropping `esc_textarea` made AC8 **red**.
- Found on the way: registering the settings on `admin_init` would leave
  `update_option()` outside the page unvalidated, and `wp eval` never runs
  `admin_init`; the provisional entry was first written after reading the
  trust lists, moved before them.
- Decided by Maurice: SPEC-004 approved earlier, SPEC-003 amendment 1
  chosen earlier; not pushed.

## 2026-09-26 — SPEC-004 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-004 implemented.
- Produced: SPEC-004 status `implemented` (Traceability filled when built).
- Measured: nothing new.
- Decided by Maurice: SPEC-004 implemented; not pushed.

## 2026-09-26 — M5.0: packaging measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare M5 (packaging).
- Produced: `notes/m5-packaging.md`.
- Measured: a `git archive` build with `composer install --no-dev` has a
  4.0 MB `vendor/`, most of it the verifier's docs, notes, specs, bin and
  logs, which its `.gitattributes` ships on purpose; the repository has no
  `.gitattributes` yet, so an archive would carry tests and tooling;
  Plugin Check takes a plugin name only, always excludes `vendor/`, and
  has `--slug`.
- Decided by Maurice: SPEC-004 implemented; prepare M5; not pushed.

## 2026-09-26 — SPEC-005 and SPEC-006 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare M5 after Maurice's two decisions.
- Produced: `specs/SPEC-005-uninstall.md` (draft: `uninstall.php` removes
  the entries and the three options; deactivation keeps them; three
  criteria) and `specs/SPEC-006-release-build.md` (draft: `.gitattributes`,
  `composer build` with the verifier trimmed to what runs, a clean second
  wp-env with the zip, Plugin Check on the build with nothing excluded, a
  WPCS baseline for the shipped verifier, CI, screenshots; five criteria;
  two non-blocking open questions); `notes/m5-packaging.md` extended with
  the WPCS scan and the decisions.
- Measured: WPCS 3 security sniffs on the verifier's `src/` (v0.2.3): 641
  findings in 10 sources, 608 of them exception messages with interpolated
  values; `wp-env start --config` exists.
- Decided by Maurice: the zip carries only what runs; the shipped verifier
  is checked with the WPCS security sniffs directly. Not pushed.

## 2026-09-26 — SPEC-005 and SPEC-006 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark both approved, with the two proposals of SPEC-006.
- Produced: SPEC-005 and SPEC-006 status `approved`; SPEC-006's open
  questions resolved as proposed (WPCS under `require-dev`; release job on
  PHP 8.3).
- Measured: nothing.
- Decided by Maurice: both approved. Not pushed.

## 2026-09-26 — SPEC-005 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-005, tests first.
- Produced: `uninstall.php` (guarded by `WP_UNINSTALL_PLUGIN`; deletes
  every `_provemark_c2pa_result` entry and the three options), in
  `phpstan.neon`'s paths; `tests/Integration/UninstallTest.php`,
  `tests/Unit/UninstallFileTest.php`; SPEC-005 Traceability filled; the
  status stays `approved` until Maurice marks it implemented.
- Measured: WordPress's `uninstall_plugin()` defines the constant,
  includes `uninstall.php` and deletes no files; the tests use it, never
  `wp plugin uninstall` (which deletes the plugin folder, here the working
  tree). Before the file: AC1 and AC3 **red**, AC2 green (nothing deletes
  on deactivation). After: integration 68 passed, `composer check` green
  (unit 38). Found on the way: `$wpdb->get_var()` returns null for an
  existing empty value, so the first version of AC1 could not see a
  leftover DigiCert row stored as ''; now read with `get_row()`. With the
  DigiCert `delete_option` removed, AC1 is **red**; restored.
- Decided by Maurice: SPEC-005 approved; not pushed.

## 2026-09-26 — SPEC-006 in progress: build, clean environment, release tests

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-006, tests first.
- Produced: `.gitattributes` (export-ignore for everything that does not
  ship); `tools/build.sh` (`composer build`: `git archive` of HEAD with the
  working tree's attributes, `composer install --no-dev` from the lock,
  the verifier trimmed to `src/`, `LICENSE`, `composer.json`, `vendor/bin`
  removed, zipped); `.wp-env.release.json` (a clean WordPress on port 8890
  with Plugin Check, `build/` and `tests/Fixtures` mapped); `npm run
  release:start`; a `Release` test suite (`tests/Release/ReleaseTest.php`,
  `composer test:release`); `tests/wpcs-verifier-baseline.json`;
  `wp-coding-standards/wpcs` 3.4.1 under `require-dev`; `cliContainer()`
  and `wpCli()` take the environment variant; a `release` CI job (PHP 8.3)
  that `all-green` waits for; the development Plugin Check test and its
  exclusion list removed (Plugin Check now runs on the build); `NOTES.md`
  temporary measures closed; SPEC-006 amendment 1 proposed.
- Measured: wp-env names a `--config` variant's project
  `wp-env-<folder>-<variant>-<hash>` (read in its `load-config.js`), and
  the two environments run side by side. Release tests **red** before the
  build existed (the zip could not be installed). The zip is 244 KB
  (928 KB unpacked, against 4.0 MB for Packagist's verifier). Plugin Check
  on the build warned `missing_composer_json_file`, so `composer.json`
  ships (amendment 1, proposed). The WPCS baseline recorded from the build
  is 641 findings in 10 sniffs, equal to the reviewed scan. All six release
  tests green, including the planted `echo $_GET` breaking the baseline.
- Still open in SPEC-006: screenshots; CI not yet run (not pushed).
- Decided by Maurice: SPEC-006 approved; amendment 1 awaits his approval.

## 2026-09-26 — Details as badge and rows (SPEC-002 amendment 1, SPEC-004 amendment 1)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: Maurice found the verifier text under the attachment details
  poorly laid out (Edit Media); he chose "badge and rows" from two
  mock-ups.
- Produced: `Display` renders the verdict as a badge with a Dashicon
  (decoration, `aria-hidden`) and the AI label as a second badge, and the
  facts as a `<dl>` (Signer, Signed at, Codes, Refers to, Reason, Checked,
  Trust list); `assets/admin.css` (WordPress admin palette), enqueued by
  `MediaScreens::enqueueStyle()` on admin screens with `dashicons`; tests
  updated to the new texts, plus two amendment tests; amendments in
  SPEC-002 and SPEC-004.
- Measured: with the tests updated first, 15 **red**; after the change,
  integration 69 passed and `composer check` green. In Chrome, Edit Media
  for a Trusted AI image and an Invalid one, and the list column, render
  as intended. Firing the whole `admin_enqueue_scripts` in WP-CLI triggers
  core warnings (no admin screen), so the test calls the plugin's own
  callback. Also seen: the uninstall test (SPEC-005 AC1) deletes every
  stored result in the development environment, so earlier uploads there
  show "Not checked" after a test run.
- Decided by Maurice: badge and rows. Not pushed.

## 2026-09-26 — SPEC-006 finished: screenshots, README, amendment 1

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: finish SPEC-006; Maurice approved amendment 1 (`composer.json`
  ships, as Plugin Check requires next to `vendor/`).
- Produced: three screenshots in `docs/screenshots/` (the Media Library
  column, the attachment details of the OpenAI image in the media modal,
  the settings page), made in Chrome on the clean release environment with
  five fixtures uploaded under plain titles; `README.md` rewritten (what
  the plugin does, the screenshots, how to build and test); SPEC-006
  amendment 1 marked approved and Traceability filled. Earlier, in a
  separate commit: `assets/admin.css` added to AC1's required files.
- Measured: an empty fixed layer with the highest z-index covered part of
  the list in the first screenshots; it disappeared on reload and was not
  part of the plugin; the list was taken again with two WordPress columns
  hidden for the release environment's test user. Release tests re-run on
  a build of this commit (below, in the reply).
- Decided by Maurice: finish SPEC-006 first; amendment 1 approved. Not
  pushed.

## 2026-09-26 — SPEC-005 and SPEC-006 implemented; M5 done

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-005 and SPEC-006 implemented, and push.
- Produced: both specs `implemented` (Traceability filled when built).
- Measured: before this commit, locally: `composer check` green (unit 38),
  integration 69 passed, release 6 passed on a build of `14c9675`. The CI
  result of the push is recorded in the next entry.
- Decided by Maurice: both implemented; push the local commits to the
  private repository.

## 2026-09-26 — Pushed; SPEC-007 preparation measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: Maurice cannot sort by Content Credentials yet; start SPEC-007.
- Measured: CI run 36229356436 on the pushed commits: every job green,
  including the new release job (120 s) and integration on PHP 8.3, 8.4
  and 8.5 (205–226 s). For SPEC-007, see `notes/m6-sort-filter.md`: the
  list screen id is `upload`, `restrict_manage_posts` fires above the
  list, grid mode filters over AJAX; writing one meta value costs
  0.23 ms per attachment (1 173 in 266 ms), a sorted page of 20 takes 3 ms.
- Produced: `notes/m6-sort-filter.md`.
- Decided by Maurice: SPEC-005 and SPEC-006 implemented; pushed; start
  SPEC-007. This commit is not pushed.

## 2026-09-26 — SPEC-007 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: draft SPEC-007 (sort and filter by Content Credentials).
- Produced: `specs/SPEC-007-sort-and-filter.md` (status `draft`): two index
  keys written with every entry, a sortable column (good to nothing), a
  filter select in list mode, a batched backfill (500 per admin request),
  SPEC-005 amendment 1 for uninstall; seven criteria (one error path); one
  non-blocking open question.
- Measured: nothing new.
- Decided by Maurice: list mode only; automatic backfill in batches; sort
  order from good to nothing. Not pushed.

## 2026-09-26 — SPEC-007 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-007 approved with the proposal for the select's wording.
- Produced: SPEC-007 status `approved`.
- Measured: nothing.
- Decided by Maurice: SPEC-007 approved. Not pushed.

## 2026-09-26 — SPEC-007 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-007, tests first.
- Produced: `src/Index.php` (the two index keys, derived through
  `Display::classify()` so they match what the display shows; a batched
  backfill), `src/MediaSort.php` (sortable column, the select on
  `restrict_manage_posts`, the query change in `pre_get_posts` with an
  allowlist, the ORDER BY built from constants with `%i` / `%s`
  placeholders, the backfill on `admin_init`); `UploadHook` indexes each
  stored entry; `uninstall.php` removes the index (SPEC-005 amendment 1);
  `tests/Integration/SortFilterTest.php`; the uninstall test extended;
  test helpers `indexOf()`, `listedIds()`, and `attachmentWithEntry()` now
  indexes. Traceability filled; status stays `approved` until Maurice
  marks it implemented.
- Measured: in the media list `restrict_manage_posts` fires with
  `$which === 'bar'` (read in core). Before the code, 15 SPEC-007 tests
  **red**; the "ignore" cases and "no select on other post types" were
  green, as nothing happened. After: integration 87 passed, `composer
  check` green. Letting `chosen()` accept any string turned the SQL and
  state-name cases of AC4 **red**; using the raw AI flag in `classify()`
  turned AC1 **red**; both restored.
- Found on the way: failed AC6 runs left 3 600 test attachments behind
  (removed by title in SQL); 1 200 attachments with one title made
  WordPress search ever longer for a free slug (121 s), so each gets a
  unique `post_name` (5 s) and the test cleans up in `finally`; PHPStan
  needed `wpdb` narrowed with `instanceof` and table names through `%i`.
- Decided by Maurice: SPEC-007 approved earlier. Not pushed.

## 2026-09-26 — SPEC-007: Plugin Check on the build

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: (continuing SPEC-007) the release tests after the build.
- Measured: Plugin Check on the build warned about `$_GET` without
  `wp_unslash()` / sanitizing, a direct database query in the backfill,
  and then a possibly slow `meta_query`. Changed: the three request values
  are read in one place as `sanitize_text_field(wp_unslash($_GET[...]))`
  (text, not `sanitize_key()`, which lowercases and would have let
  `Trusted` through as `trusted`, against AC4: a new test through the real
  request, seen **red** with `sanitize_key()`); the backfill uses
  `get_posts()` with a meta query (`phpcs:ignore` on its slow-query sniff,
  with the reason). After: release 6 passed, integration 88 passed,
  `composer check` green. This entry is amended into the commit
  "Satisfy Plugin Check on the request and the backfill query"; the commit
  before it ("Sanitize the list request…") has no entry of its own.
- Decided by Maurice: nothing new. Not pushed.

## 2026-09-26 — SPEC-007 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-007 implemented and push.
- Produced: SPEC-007 status `implemented`.
- Measured: locally before this commit: `composer check` green (unit 38),
  integration 88 passed, release 6 passed. The CI result of the push goes
  in the next entry.
- Decided by Maurice: SPEC-007 implemented; push.

## 2026-09-26 — Integration tests in their own environment

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: move the integration tests out of Maurice's development
  environment (the uninstall test emptied it on every run, and the tests
  left thousands of attachments there).
- Produced: `.wp-env.test.json` (port 8892, the plugin mapped, activated
  by an `afterStart` lifecycle script, since wp-env does not activate a
  mapped plugin); `npm run test:start` / `test:stop`; `wpCli()` defaults to
  the `test` environment (the development one is never the default);
  the CI integration job starts and version-checks the test environment
  (`wp-env run --config`); `.gitattributes` leaves the new file out of the
  zip; `README.md` lists the three environments.
- Measured: `wp-env run` accepts `--config`; the test environment's
  project is `wp-env-c2pa_verifier_wp-test-<hash>`; without the lifecycle
  script the plugin was `inactive` there, with it `active`. Integration 88
  passed on the test environment (107 s); Maurice's environment counted
  2 114 attachments and 5 entries before and after the run.
  `composer check` green. Also: CI run 36231049596 (SPEC-007, pushed) was
  green on every job.
- Decided by Maurice: move the integration tests. Not pushed.

## 2026-09-26 — Pushed; a tie in the sort order fixed; dev environment cleaned

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: push the integration-environment change, and clean Maurice's
  development environment.
- Measured: CI run 36232610171 on `a351197`: `check` and `release` green,
  integration green on PHP 8.4 but **red** on 8.3 and 8.5, each on SPEC-007
  AC4 "orders ascending when the order is not asc or desc". Cause
  (reasoned, then confirmed): the image without an entry and the PDF share
  a state and were made in the same second, so they tie on `post_date` and
  MySQL may order them either way between two queries; it had passed
  locally and in the previous CI run by chance.
- Produced: the ORDER BY ends with `ID DESC`; AC2 now asserts the tie's
  order (the PDF, made last, first); SPEC-007 amendment 1 records it.
  Without `ID DESC`, AC2 is **red**; with it, the SPEC-007 tests passed
  three runs in a row; `composer check` green.
- Cleaned, at Maurice's request: 2 109 test attachments deleted from the
  development environment with their files; the five examples (IDs
  12926–12930) kept; posts, pages and options untouched; no orphaned meta
  left.
- Decided by Maurice: push; clean the development environment. This
  commit is not pushed yet.

## 2026-09-26 — SPEC-008 drafted (WP-CLI re-check)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: start the WP-CLI command to check existing images again.
- Measured: CI run 36233188792 on `e359787` green on every job. WP-CLI
  2.12.0 in wp-env; for the Pixel photo in the development environment
  `get_attached_file()` is the `-scaled` copy and
  `wp_get_original_image_path()` the original; checks take 0–56 ms; with
  `Checker` alone (no trust settings) the Pixel photo is `Invalid` and the
  OpenAI image `Valid`, so a re-check must take the upload's path.
- Produced: `specs/SPEC-008-wp-cli-recheck.md` (status `draft`): one
  shared check-and-store path, `wp provemark-c2pa check` with an explicit
  selection, `--dry-run`, table/JSON output; eight criteria (three error
  paths); one non-blocking open question (the command name).
- Decided by Maurice: images always chosen explicitly; a `--dry-run`; a
  table and a summary. Not pushed.

## 2026-09-26 — SPEC-008 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-008 approved with the proposed command name.
- Produced: SPEC-008 status `approved`.
- Measured: nothing.
- Decided by Maurice: SPEC-008 approved, `wp provemark-c2pa check`. Not
  pushed.

## 2026-09-26 — SPEC-008 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-008.
- Produced: `UploadHook::checkAndStore()` (the one check-and-store path,
  used by the upload hook with `get_attached_file()` and by the command
  with `wp_get_original_image_path()`); `src/RecheckCommand.php`
  (`wp provemark-c2pa check`: IDs / `--all` / `--unchecked` /
  `--state=`, `--dry-run`, table + summary or `--format=json`, a progress
  bar over 20 images, pages of 500 fetched before checking); registration
  only under WP-CLI, inside a function so the plugin adds no global
  variable; `tests/Integration/RecheckTest.php`; `wp-cli/wp-cli` 2.12.0
  under `require-dev` for PHPStan (`php-stubs/wp-cli-stubs` requires
  WordPress stubs up to 6.x and conflicts with 7.1), its `utils.php` in
  PHPStan's `scanFiles`; PHPStan's memory limit raised to 1 GB (it crashed
  at 512 MB reading WP-CLI). Traceability filled; status stays `approved`.
- Measured: before the code all ten SPEC-008 tests **red**. After:
  integration 98 passed, `composer check` green. Mutations: the command
  using `get_attached_file()` turned AC2 **red**; ignoring `--dry-run`
  turned AC7 **red**; both restored. Test bugs found and fixed: `--all`
  ran before `--unchecked` and left nothing unchecked; `?? 'x'` turned a
  real `null` into `'x'`. Plugin Check on the build flagged the two
  `meta_query` uses as possibly slow: annotated (on demand, in WP-CLI, in
  pages).
- Decided by Maurice: SPEC-008 approved earlier. Not pushed.

## 2026-09-26 — SPEC-008 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-008 implemented and push.
- Produced: SPEC-008 status `implemented`.
- Measured: locally before this commit: `composer check` green (unit 38),
  integration 98 passed, release 6 passed; in the development environment
  `wp provemark-c2pa check --all --dry-run` listed the five examples and
  changed nothing, and the command without a selection exited 1. The CI
  result of the push goes in the next entry.
- Decided by Maurice: SPEC-008 implemented; push.

## 2026-09-26 — Faster integration suite (1)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: make the integration suite faster.
- Measured: CI run 36241861862 (SPEC-008, pushed) green on every job.
  Integration job on PHP 8.3 there: `npm run test:start` 108 s, `composer
  test:integration` 190 s. Locally: 134 s over 98 tests; RecheckTest AC3
  took 17 s because `--all` checked the 732 attachments the test
  environment had accumulated over local runs; the twelve SortFilterTest
  cases took 2.4–2.8 s each, building eight attachments with eight WP-CLI
  calls (each one boots WordPress).
- Produced: `sevenGroups()` builds its eight attachments in one request;
  every integration test file starts from an empty test environment
  (`emptyTestEnvironment()` in a `beforeAll` for `tests/Integration`:
  attachments, their files, the plugin's meta and options; the test
  environment only, never the development one).
- Measured after: 98 passed in 101 s and 104 s on two local runs in a row
  (sum of test times 102 s); AC3 2.7 s; the slowest test 5.2 s (AC6, 1 200
  attachments); 67 of 98 tests under a second; 15 attachments left after a
  run. `composer check` green. CI not measured yet (not pushed).
- Decided by Maurice: make the suite faster.

## 2026-09-26 — Faster suite in CI; wp-env start measured; no Docker cache

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: push the faster suite; then try caching Docker images in CI.
- Measured: CI run 36247535962 green; integration tests 137 / 170 / 177 s
  (PHP 8.3 / 8.4 / 8.5, from 190 s on 8.3), jobs 280–300 s (from
  303–335 s), `test:start` still 94–111 s. On a temporary branch with
  `--debug`, see `notes/ci-wp-env-start.md`: 52 of about 114 s go to
  building wp-env's images, which a restored image cache would not skip.
- Produced: `notes/ci-wp-env-start.md`. The branch `ci-measure-wp-env`
  was deleted locally and on GitHub at Maurice's request.
- Decided by Maurice: no Docker cache; start on prefixing the bundled
  verifier's namespace.

## 2026-09-26 — SPEC-009 drafted (prefix the bundled verifier)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: start on prefixing the bundled verifier's namespace.
- Measured, in a scratch copy of the build: the Composer-installed
  Strauss 0.30.0 could not run inside the build folder (its bin script
  loads the plugin's `vendor/autoload.php` first); the release's
  `strauss.phar` (11.6 MB, SHA-256 08c1a8e5…c38c66c96) moved the verifier
  to `vendor-prefixed/` under `Provemark\C2paCheck\Vendor\`, rewrote the
  `use` lines in `src/`, and wired its autoloader into
  `vendor/autoload.php`; afterwards the original class did not exist, the
  prefixed one did, `InstalledVersions` still gave `v0.2.3`, and a check of
  `fixture-signed.jpg` gave `Valid`.
- Produced: `specs/SPEC-009-prefix-bundled-verifier.md` (status `draft`):
  prefixing in the build only, Strauss pinned by version and checksum,
  four criteria (two error paths, including another copy of the verifier
  loaded first), SPEC-006 amendment 2; two non-blocking open questions.
- Decided by Maurice: start with the prefix. Not pushed.

## 2026-09-26 — SPEC-009 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-009 approved with both proposals.
- Produced: SPEC-009 status `approved`.
- Measured: nothing.
- Decided by Maurice: prefix `Provemark\C2paCheck\Vendor\`; the pinned
  phar downloaded once per machine and checked. Not pushed.

## 2026-09-26 — SPEC-009 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-009.
- Produced: `extra.strauss` in `composer.json`; `tools/build.sh` fetches
  `strauss.phar` 0.30.0 once into `build/.tools/`, checks its SHA-256
  and stops on a mismatch, runs it after `composer install --no-dev`, and
  trims `vendor-prefixed/provemark/c2pa-verifier`; the release tests follow
  the move (SPEC-006 amendment 2) and gain SPEC-009 AC1, AC3 and AC4.
  Traceability filled; status stays `approved`.
- Measured: before the build change, the release tests **red** on the new
  paths and criteria; SPEC-009 AC3 red for the reason the spec gives: with
  a must-use plugin defining the unprefixed `Verifier` (whose `verify()`
  throws), the plugin's upload was `error` instead of `Valid`. A first
  build still shipped `vendor/provemark/`: the build archives HEAD, where
  `extra.strauss` was not yet committed; committed first, then rebuilt.
  After: release 9 passed (collision case `Valid`; a fake phar stops the
  build with "SHA-256" and no zip), `composer check` green, the WPCS
  baseline unchanged.
- Decided by Maurice: SPEC-009 approved earlier. Not pushed.

## 2026-09-26 — SPEC-009 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-009 implemented and push.
- Produced: SPEC-009 status `implemented`.
- Measured: locally before this commit: `composer check` green, release 9
  passed. The CI result (the release job downloads Strauss on a runner
  for the first time) goes in the next entry.
- Decided by Maurice: SPEC-009 implemented; push.

## 2026-09-26 — Stale lock hash fixed; validate in composer check

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: (Maurice) `composer check` fails in CI.
- Measured: CI run 36249516135 on `582ea99`: integration and release green
  (the release job downloaded Strauss on a runner and passed), but the
  `composer check` jobs **red** at `composer validate --strict`: "The lock
  file is not up to date with the latest changes in composer.json". The
  `extra.strauss` block had been added to `composer.json` by hand without
  refreshing the lock's content hash; locally only `composer check` ran,
  which did not include `validate`.
- Produced: `composer update --lock` (only the `content-hash` line of
  `composer.lock` changes, no package versions); `composer check` now
  starts with `composer validate --strict`, as CI does. `composer check`
  green locally.
- Decided by Maurice: fix it. Not pushed yet.

## 2026-09-26 — Update URI: false

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: add the `Update URI` header, so a plugin that someone else
  registers as `provemark-c2pa-check` on wordpress.org can never be
  offered as an update to this one.
- Measured: CI run 36249900369 on `f9fdc8c` green on every job. Read in
  core (`wp-includes/update.php`): every plugin is still sent to
  api.wordpress.org; the WordPress 5.8 dev note (make.wordpress.org/core,
  2021-06-29) says the API then "will not return any result" for a plugin
  whose `Update URI` is not its wordpress.org URL, recommends `false` or a
  URI with a unique hostname, and says the plugin team keeps the header
  out of wordpress.org-hosted plugins. The new test was **red**
  (`UpdateURI` empty) before the header, green after. Wrong at first: a
  search of Plugin Check's source (leaving out its `vendor/`) found no
  mention of the header, but Plugin Check on the build reports it as an
  ERROR, `plugin_updater_detected`; the release test caught it.
- Produced: `Update URI: false` in the plugin header; a test in
  `tests/Integration/ActivationTest.php` that reads it through
  `get_plugin_data()`; the reason and "remove before submitting to
  wordpress.org" in `NOTES.md`.
- Decided by Maurice: add the header. The value `false` (not a GitHub URL,
  whose shared hostname other updaters hook) is the assistant's choice.
  Not pushed.
- Decided afterwards by Maurice: keep the header while the plugin is only
  on GitHub, with the release test adjusted for it. Measured then: with
  `--ignore-codes=plugin_updater_detected` Plugin Check prints nothing at
  all (not even "Checks complete", exit 0), so the test cannot see that
  the check ran; instead it runs Plugin Check unfiltered and requires
  exactly one finding, `ERROR,plugin_updater_detected`. SPEC-006
  amendment 3 records it; header and expectation go before a
  wordpress.org submission.

## 2026-09-26 — Update URI header removed again; wordpress.org-ready

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: (Maurice) why generate a header that wordpress.org does not
  allow; then: remove it and stay wordpress.org-ready.
- Answered: WordPress core allows the header (it exists for plugins
  outside wordpress.org); wordpress.org's directory does not. The
  assistant proposed it for the GitHub-only situation, but should have put
  its conflict with decision 2 ("built as if for wordpress.org") to
  Maurice before adding it, and had wrongly concluded that Plugin Check
  ignores it. With the repository private and no release, the risk it
  guards against has no users to affect yet.
- Produced: the header, its integration test and SPEC-006 amendment 3
  removed (amendment 3 marked withdrawn); the release test's AC3 back to
  "no errors or warnings, Checks complete"; `NOTES.md` keeps what was
  found (core behaviour, the 5.8 dev note, Plugin Check's
  `plugin_updater_detected`) for when a release outside wordpress.org is
  decided. `composer check` green. The two earlier commits stay in the
  history, unpushed until now.
- Decided by Maurice: remove the header; stay wordpress.org-ready.

## 2026-09-27 — SPEC-010 drafted (privacy policy text)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: start on the privacy text; after asking what it is for, Maurice
  chose the suggested text only (no exporter or eraser).
- Measured: CI run 36258905640 on `61dd988` green on every job. Read in
  core 7.1.2: `wp_add_privacy_policy_content()` works only from
  `admin_init` in the admin (else `_doing_it_wrong`), and
  `privacy-policy-tutorial` marks guidance left out of the copied text;
  core's "WordPress Media" personal-data exporter exports only the URLs of
  a user's attachments.
- Produced: `specs/SPEC-010-privacy-policy-text.md` (status `draft`): the
  text, where it is registered, a test per claim not tested elsewhere
  (including "deleted when the image is deleted"); one non-blocking open
  question (the wording).
- Decided by Maurice: the suggested text only. Not pushed.

## 2026-09-27 — SPEC-010 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-010 approved with the drafted wording.
- Produced: SPEC-010 status `approved`.
- Measured: nothing.
- Decided by Maurice: SPEC-010 approved, wording as drafted. Not pushed.

## 2026-09-27 — SPEC-010 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-010.
- Produced: `src/PrivacyPolicy.php` (registered on `admin_init`, adds the
  text only in the admin, guidance in a `privacy-policy-tutorial`
  paragraph); `tests/Integration/PrivacyTest.php`. Traceability filled;
  status stays `approved`.
- Measured: before the code AC1 and AC2 **red**; AC3 ("deleted when the
  image is deleted") green without any plugin code: WordPress's
  `wp_delete_attachment()` removes the entry and both index keys, so the
  claim holds. Test mistakes found and fixed: firing all of `admin_init`
  outside the admin made core's own `wp_add_privacy_policy_content()` call
  raise the notice, so AC2 calls only the plugin's callback;
  `get_suggested_policy_text()` also lists texts of earlier requests
  marked `removed`, so AC2 reads this request's
  `$wp_privacy_policy_content`. Without the `is_admin()` guard AC2 is
  **red**; restored. After: SPEC-010 3 passed, `composer check` green.
- Decided by Maurice: SPEC-010 approved earlier. Not pushed.

## 2026-09-27 — SPEC-010 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-010 implemented and push.
- Produced: SPEC-010 status `implemented`.
- Measured: locally before this commit: `composer check` green,
  integration 101 passed, release 9 passed. The CI result goes in the
  next entry.
- Decided by Maurice: SPEC-010 implemented; push.

## 2026-09-27 — A complete readme.txt

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: write the complete `readme.txt` for wordpress.org.
- Measured: CI run 36296202033 on `3f067e0` (SPEC-010) green on every job.
  Read in the plugin handbook ("How your readme.txt works", markdown
  version): 1 to 5 tags, no competitors' names; a short description of at
  most 150 characters without markup; a readme over 10 KB may cause
  errors; `Stable tag` in SemVer; `Tested up to` ignores minor versions;
  `Contributors` are wordpress.org user names (none yet, so left out);
  custom sections in moderation.
- Produced: `readme.txt` rewritten (5.8 KB): what the plugin does, the
  five verdicts in words, what it does not do, installation, ten FAQs,
  three screenshot captions, the "Trust lists" section with the CC BY 4.0
  attribution, changelog and upgrade notice for 0.1.0;
  `tests/Unit/ReadmeTest.php` for the hard limits (size, tags, short
  description, `Stable tag` equal to the plugin's `Version`, required
  sections). Before the new text the test was **red** on the old readme;
  after, green; a sixth tag turns it **red** (restored).
- Reasoned, not measured: that HEIC images arrive without their Content
  Credentials (the browser converts them to JPEG, read in core's
  `upload-media.js`); that most services strip Content Credentials when
  sharing.
- Decided by Maurice: write the complete readme. Not pushed.

## 2026-09-27 — Multisite measured; SPEC-011 drafted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: start on multisite.
- Measured: CI run 36296664497 on `d1d452d` (readme) green on every job.
  A multisite wp-env (`.wp-env.multisite.json`, port 8894, network-
  activated plugin, a second site): uploads on both sites were checked and
  stored on their own site (`Valid`, `Trusted`); the WP-CLI command worked
  with `--url`; options are per site; `uninstall_plugin()` cleaned only
  the main site (0 entries, no options there; the subsite kept 3 meta rows
  and its DigiCert option).
- Produced: `.wp-env.multisite.json` (export-ignored);
  `specs/SPEC-011-multisite.md` (status `draft`): uninstall on every site,
  a multisite test suite and CI job, the readme updated; five criteria;
  one non-blocking open question (very large networks).
- Decided by Maurice: support multisite. Not pushed.

## 2026-09-27 — SPEC-011 approved

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-011 approved with the proposal for large networks.
- Produced: SPEC-011 status `approved`.
- Measured: nothing.
- Decided by Maurice: SPEC-011 approved. Not pushed.

## 2026-09-27 — SPEC-011 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build the approved SPEC-011.
- Produced: `uninstall.php` cleans every site (`get_sites()`,
  `switch_to_blog()`), unchanged on a single site; a `Multisite` test suite
  (`tests/Multisite/MultisiteTest.php`, `composer test:multisite`) against
  `.wp-env.multisite.json` (network-activated by an `afterStart` script,
  `npm run multisite:start`), with `networkCli()`/`networkEval()`/
  `networkImport()`/`networkEntry()` helpers; a `multisite` CI job on
  PHP 8.3 that `all-green` waits for; `readme.txt` and `README.md`
  updated. Traceability filled; status stays `approved`.
- Measured: before the change only AC3 **red** (the measured gap); AC1,
  AC2, AC4 and AC5 describe behaviour that already worked. The first
  version of AC3 still failed after the fix: counting in a second request
  let the still-active plugin re-create its empty custom option on the
  main site (as in SPEC-005); uninstall and count now run in one request.
  After: 5 passed; with the multisite loop removed AC3 is **red**
  (restored); SPEC-005 tests and `composer check` green.
- Decided by Maurice: SPEC-011 approved earlier. Not pushed.

## 2026-09-27 — SPEC-011 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-011 implemented; do not push yet.
- Produced: SPEC-011 status `implemented`.
- Measured: locally before this commit: multisite 5 passed, SPEC-005 2
  passed, `composer check` green, release 9 passed. The new `multisite`
  CI job has not run yet (not pushed).
- Decided by Maurice: SPEC-011 implemented; not pushed.

## 2026-09-27 — Accessibility measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: check the accessibility of the badges (and continue while the
  SPEC-011 push ran in the background).
- Measured: see `notes/accessibility.md`: every badge 5.67–10.03:1
  (AA 4.5:1); the verdict is text, the icon `aria-hidden`; the filter
  select has a screen-reader label; the column header and cells carry
  their name; at 320 px (in an iframe, the window not resized) nothing
  overflows, in the list or in the details. Not measured: a real screen
  reader, high contrast mode.
- Produced: `notes/accessibility.md`; `tests/Unit/ContrastTest.php`,
  recomputing each badge's contrast from the CSS (a lighter blue for
  Intact turns it **red**; restored). No change to the plugin was needed.
- Decided by Maurice: push SPEC-011 in the background and continue.
  Not pushed.

## 2026-09-27 — Screenshots for wordpress.org

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare the screenshots.
- Produced: `.wordpress-org/screenshot-1.png` … `screenshot-4.png`, the
  names wordpress.org's plugin directory expects (they belong in its SVN
  `assets/`, never in the zip; `.wordpress-org` is export-ignored and in
  the release test's forbidden paths). 1–3 are the earlier screenshots,
  moved from `docs/screenshots/` (now gone; README.md points to the new
  files); 4 is new: the Media Library list filtered to "AI-generated
  (signed)", showing SPEC-007's select, taken in Chrome on the release
  environment. `readme.txt` has a fourth caption; `tests/Unit/ReadmeTest.php`
  checks that captions and files match (without `screenshot-4.png` it is
  **red**; restored).
- Measured: the five examples in the release environment had been checked
  before SPEC-007 and had no index ("before" empty in
  `wp provemark-c2pa check 13 14 15 16 17`), so the AI filter would have
  shown nothing until the backfill ran; re-checked, the filter showed the
  OpenAI image. The release environment held 58 attachments left by the
  release tests (that suite does not clean up); deleted, the five kept.
  `composer check` green (41 unit tests).
- Decided by Maurice: the screenshots first. Not pushed.
