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

## 2026-09-27 — Icon and banner proposed

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: propose an icon and a banner for wordpress.org; then (Maurice,
  on the first version) "text runs out of the boxes" and "a more modern
  typeface".
- Measured: CI run for the screenshot commit green on every job. No logo
  or brand files exist in the projects; the verifier's demo site uses a
  dark background (`#0b0c0e`, `#131519`), accent `#2dd4bf`, and green,
  amber and red status colours. No SVG renderer is installed; headless
  Chrome writes a screenshot but does not exit, so the script waits for
  the file and stops it.
- Produced: `.wordpress-org/source/icon.svg` (an image frame with a
  turquoise check seal; not the C2PA "cr" mark, whose use has its own
  rules), `.wordpress-org/source/banner.html` (HTML/CSS so the badges size
  to their text; system-ui, i.e. SF Pro on macOS, with Inter and Helvetica
  as fallbacks), `.wordpress-org/source/render.sh`, and the rendered
  `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`,
  `banner-1544x500.png`. The first banner was an SVG with hand-measured
  badge widths, which let text run out of its boxes.
- Found after the first commit: `banner-772x250.png` was 2 KB, a broken
  image. The first, hung render job (killed only by name pattern later)
  had kept running and overwrote it with the deleted SVG banner; stopped,
  all four re-rendered and each looked at. Amended into the same commit.
- Decided by Maurice: a proposal; not yet accepted. Not pushed.

## 2026-09-27 — Release tests remove their uploads

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: make the release tests clean up their uploads.
- Measured: before, every `composer test:release` left its attachments in
  the release environment (58 had piled up). Now `releaseImport()` records
  each attachment ID and an `afterAll` in `ReleaseTest.php` deletes those,
  files included (`wp_delete_attachment( $id, true )`). Two runs: 5
  attachments before, 5 after, 9 tests green; `composer check` green. The
  first version (a static inside a function) failed PHPStan (`return.type`);
  a small class with a typed property fixed it.
- Reasoned: only what the run uploaded is removed, not everything, so the
  five screenshot examples in that environment stay. An upload in a test
  that fails half-way is still removed, as `afterAll` runs regardless; an
  interrupted run (Ctrl-C) can still leave some behind.
- Decided by Maurice: the cleanup; the choice to remove only the run's own
  uploads was mine. Not pushed.

## 2026-09-27 — Licences of the bundled trust files settled

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: work out whether the bundled trust lists' licence stands in the
  way of wordpress.org (an open item in `NOTES.md`).
- Measured: guideline 1 of wordpress.org's Detailed Plugin Guidelines
  (GPL or GPL-compatible for all code, data and images); the licence of
  `c2pa-org/conformance-public` via the GitHub API (`CC-BY-4.0`); gnu.org's
  licence list (CC BY 4.0 compatible with all GPL versions); the DigiCert
  root's SHA-256 fingerprint equal to the one in WordPress 7.1.2's
  `wp-includes/certificates/ca-bundle.crt`.
- Produced: the finding under Measured in `NOTES.md` (the open item marked
  resolved) and one sentence in `trust/README.md` on the DigiCert root.
  Documentation only.
- Reasoned: the wordpress.org reviewer decides; nothing found conflicts.
- Decided by Maurice: the proposal as given. Not pushed.

## 2026-09-27 — The verifier's command-line tool no longer ships (SPEC-006 amendment 4)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: look at the 641 WPCS findings in the shipped verifier; then
  (Maurice) step A: stop shipping what the plugin does not use.
- Measured: the findings per sniff and file: 608 `ExceptionNotEscaped`
  (exception messages, in 29 files), 13 in `src/Cli/Command.php`, 20
  elsewhere (`fread` on the image stream, `base64` for PEM/DER,
  `set_error_handler` around OpenSSL, `json_encode`). No reference to
  `Cli` outside `src/Cli/`. A scratch build that removes `src/Cli/` after
  `composer install` and regenerates the autoloader before Strauss: no
  autoload file mentions `Cli`, `fixture-signed.jpg` still `Valid`.
- Tests first, seen red: AC1 (forbidding `src/Cli/`) failed listing
  `src/Cli/Command.php`; AC4 with the lowered baseline failed on five
  sniffs above it. After the change to `tools/build.sh`: release suite 9
  passed, `composer check` green; the zip has no `Cli` file and no
  autoload entry for it (275 KB).
- Produced: SPEC-006 amendment 4 (AC1 and AC4), `tools/build.sh`,
  `tests/Release/ReleaseTest.php`, `tests/wpcs-verifier-baseline.json`
  (628 findings in 6 sniffs).
- Reasoned: the 608 exception findings do not belong fixed in the
  verifier, a plain PHP library without `esc_html()`; the plugin escapes
  where it shows a message (`SettingsPage`, `Display::text()`). This
  replaces my earlier advice to fix them in the verifier.
- Decided by Maurice: amendment 4 approved. Not pushed.

## 2026-09-27 — Notes for a wordpress.org review

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: step B: an explanation per WPCS sniff in the shipped verifier,
  ready to paste when a reviewer asks.
- Produced: `notes/wporg-review.md` (not shipped): what is bundled and how
  it is checked, then each of the 6 sniffs in the baseline with file and
  line, and a pointer to the licence findings.
- Measured, for the claims in it: a verifier exception's message is never
  stored (`src/Checker.php` stores a fixed reason); the one message shown
  is escaped (`src/SettingsPage.php`); every `set_error_handler` is undone
  in a `finally` right after the call; no `eval` in the verifier; the
  plugin opens the original read-only (`'rb'`) and does not call the
  verifier's `toJson()`; `notes/` is export-ignored. First draft said the
  plugin calls no `json_encode` at all; it does (`TrustConfig`), so the
  wording was narrowed.
- Reasoned: whether a reviewer accepts these explanations.
- Decided by Maurice: step B. Not pushed.

## 2026-09-27 — Icon: a clearer photo

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: make the picture with the mountain and sun clearer.
- Produced: `.wordpress-org/source/icon.svg` redrawn: two filled mountains
  (the smaller one lighter, on the left) instead of a zigzag line, the sun
  top right, a larger frame that clips the scene, and a slightly smaller
  seal that covers only the foot of the big mountain. Re-rendered the two
  icons and, as the banner uses the same SVG, the two banners.
- Measured: each of the four PNGs looked at after rendering, sizes as
  wordpress.org asks (128, 256, 772×250, 1544×500). A first redraw put the
  second mountain behind the seal, leaving a grey sliver; moved left.
- Decided by Maurice: still a proposal. Not pushed.

## 2026-09-27 — Icon and banner accepted

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: record that the icon and banner of `afd4b34` are accepted.
- Produced: decision 5 in `NOTES.md`.
- Decided by Maurice: icon and banner accepted. Not pushed.

## 2026-09-27 — Submission checklist

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a checklist for submitting to wordpress.org.
- Produced: `notes/wporg-submission.md` (not shipped): before submitting,
  preparing the release, submitting, the SVN repository after approval,
  and later releases; who does each step.
- Measured: read the handbook's pages on submitting, Subversion and plugin
  assets (2026-09-27): the form URL, review within about 14 business
  days, the slug cannot change, SVN layout and release steps, the separate
  SVN password, asset names and size limits; our asset files all under
  250 KB.
- Reasoned (marked so in the note): the expected slug, a possible
  trademark question about "C2PA", changing only `Tested up to` without a
  release.
- Decided by Maurice: the checklist. Not pushed.

## 2026-09-27 — Review fixes (SPEC-012)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: read the whole plugin as a wordpress.org reviewer; then (Maurice)
  SPEC-012 for what to fix.
- Measured before: the custom trust settings (autoload off) were read on
  every request outside the admin, as `registerSettings()` ran on `init`;
  direct requests to three files in `src/` gave HTTP 200 and an empty
  body.
- Tests first, seen red: AC1 (option cached, registered on `init`), AC2
  (no callback on `admin_init`), AC3 (no guard in any of the 11 files);
  AC4 green before and after, as a guard. Two test mistakes fixed on the
  way: AC3 counted 11 tokens instead of 12; `setOption()` used
  `update_option()`, which stores nothing for `false` on a missing option
  once the settings are not registered in WP-CLI, so it now deletes and
  adds.
- Found by AC2: saving custom settings turned their autoload from `off`
  to `auto` (autoloaded), a SPEC-004 defect: WordPress re-adds an option
  whose value equals its registered default. Fixed by registering no
  default for that option; measured through `options.php` as an
  administrator: autoload stays `off`. Recorded in `NOTES.md`.
- Produced: `SettingsPage` on `admin_init` without the registered default,
  the guard in every file in `src/`, `ABSPATH` for unit tests in
  `tests/Pest.php`, `tests/Integration/ReviewFixesTest.php`,
  `tests/Unit/DirectAccessTest.php`, SPEC-004 AC5's test registering
  through `admin_init`, the points left as they are in
  `notes/wporg-review.md`.
- After: `composer check` 52 passed, integration 104, multisite 5,
  release 9 (the release suite again after the commit, as it builds from
  `HEAD`).
- Decided by Maurice: SPEC-012 approved. Not pushed.

## 2026-09-27 — SPEC-012 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-012 implemented and push.
- Produced: the status line; Traceability was filled in `03269e1`.
- Decided by Maurice: SPEC-012 implemented; push.

## 2026-09-27 — Two open points from the verifier review

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: record the plugin's two points from the review of the bundled
  verifier in `NOTES.md`.
- Produced: "Open, each for a later spec" in `NOTES.md`: refuse the legacy
  trust format in custom settings; keep a check that dies from breaking the
  upload. Plus what to do when a fixed verifier is released.
- Measured (in the verifier review): the legacy-format `Trusted` case, a
  4 MB JSON assertion exhausting 256 MB, a minute-long check. Reasoned:
  the effect on the upload, from the order in `media_handle_upload()`; not
  yet measured with such a file.
- Decided by Maurice: record both. Not pushed.

## 2026-09-27 — The bundled verifier to v0.2.4

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "werk de plugin bij naar 0.2.4"; after the review of the new WPCS
  findings, "akkoord, baseline 616 gereviewd, en markeer punt 1 als
  vervallen".
- Produced: `composer.json` requires `provemark/c2pa-verifier` `^0.2.4`
  (was `^0.2.3`), `composer.lock` at v0.2.4 (`7740353`);
  `tests/wpcs-verifier-baseline.json` (`ExceptionNotEscaped` 616, verifier
  v0.2.4); SPEC-006 amendment 5; `notes/wporg-review.md` (counts and
  the line numbers in `Manifest/ManifestStore.php`); `NOTES.md` (open
  point 1 lapsed, the upgrade step done).
- Measured: `composer check` (52 passed); `composer test:integration`
  (104 passed) with `wp eval` showing `v0.2.4` inside wp-env;
  `composer test:multisite` (5 passed); `composer test:release` first 8
  passed and AC4 failed (`ExceptionNotEscaped: 616 (baseline 608)`), then,
  after the reviewed baseline, 9 passed. The eight findings were located
  by running the sniff over the verifier's `src/` at `v0.2.3` and
  `v0.2.4`: all in `Manifest/Manifest.php`, lines 325 and 335.
- Decided by Maurice: baseline 616 reviewed and accepted; open point 1
  lapsed. Not pushed.

## 2026-09-27 — The check runs in the background (SPEC-013)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: open point 2, a check that dies must not break the upload; then
  (Maurice) option B, WP-Cron, with the label "Check pending".
- Measured before: with a must-use plugin that exhausts memory during the
  check, a REST upload answered HTTP 200 with a fatal error in the body,
  `async-upload.php` HTTP 500; the attachment kept its file but got no
  metadata and no image sizes; the entry was `error` / `interrupted`. In
  the REST route the check ran under a 128 MB limit.
- Tests first, seen red: SPEC-013 AC1–AC8 (AC1: the upload stored an
  entry; AC3: HTTP 200 instead of 201), and SPEC-001 AC7/AC8 once they
  called the new handler.
- Produced: `UploadHook` (marker and one WP-Cron event on upload;
  `runScheduled()` with the admin memory limit on the original file, not
  `-scaled`; `checkAndStore()` clears the marker), "Check pending" in
  `Display` and `MediaScreens`, the filter option in `MediaSort`, the badge
  colour, `uninstall.php`, the readme (background check, an FAQ on
  "Check pending" and `DISABLE_WP_CRON`), SPEC-001 amendment 3, SPEC-007
  amendment 2, `NOTES.md` point 2 resolved. Test helpers: imports run the
  due check, as WP-Cron would; HTTP uploads through REST and
  `async-upload.php`; a must-use plugin for a check that dies.
- Test mistakes on the way: `attachmentWithEntry()` and `sevenGroups()`
  made JPEG attachments that now got a marker and an event, so they clear
  both; AC3 needs two cron runs, as a check that dies ends its cron
  request; AC4 first called `wp-cron.php` without the lock key and nothing
  ran (not explained), now it calls it as `spawn_cron()` does; AC1 holds
  WP-Cron with a fresh lock, as any request, WP-CLI included, spawns it;
  SPEC-007 AC6 made its "new unindexed entry" by an upload, now it stores
  one.
- Measured after: the result about 2 s after an upload starts, when a page
  load follows (three runs); `composer check` 52 passed, integration 112,
  multisite 6; the release suite after the commit (it builds from HEAD).
- Decided by Maurice: SPEC-013 approved, option B, "Check pending". Not
  pushed.

## 2026-09-27 — SPEC-013 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-013 implemented and push.
- Produced: the status line; Traceability was filled in `e05b32f`.
- Decided by Maurice: SPEC-013 implemented; push.

## 2026-09-27 — SPEC-013 amendment 1, from a review of its code

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a short review of the new code; then (Maurice) the four fixes
  as amendment 1.
- Measured: the path chosen at check time can be `-scaled`: core 7.1.2's
  `_wp_image_meta_replace_original()` switches the attached file before
  the metadata names the original; reproducing that state made the Pixel
  10 photo `none`. The test environment's `max_execution_time` is 0, so
  the batch time limit (a late check dying as `interrupted`) was reasoned,
  then reproduced with a must-use plugin (limit 3 s, two checks of 2 s
  CPU).
- Tests first, seen red: AC9 (`none` instead of `Trusted`), AC10 (the
  second check not `Valid`), AC11 (the event left), AC12 ("Check pending"
  on a two-hour marker).
- First built with `set_time_limit()` per check: Plugin Check 2.1.0 warned
  (`Squiz.PHP.DiscouragedFunctions.Discouraged`), so the release suite was
  red, and hosts can disable the function. Changed (Maurice) to deferral:
  past half the host's limit a check waits for the next cron request.
  AC10 now also asks that it waits; red on the `set_time_limit()` version,
  green on the deferral.
- Produced: the event carries the original's path relative to the
  uploads folder, used only when it resolves inside it; a check deferred
  to the next cron request past half the host's limit (`timer_float()`),
  never with no limit; `checkAndStore()`
  removes a scheduled check of that attachment; `$now` defaults to
  `time()`. Test helpers match events on their first argument.
- After: `composer check` 52 passed, integration 116, multisite 6; CI of
  `91b41d2` green on every job, the HTTP upload tests included.
- Decided by Maurice: amendment 1 approved; deferral instead of
  `set_time_limit()`. Not pushed.

## 2026-09-27 — Contributors, and step 2 of the submission checklist

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: Maurice's wordpress.org username is `mauricevanloon`.
- Produced: `Contributors: mauricevanloon` in `readme.txt`; `NOTES.md` and
  the checklist's ticks.
- Measured: `profiles.wordpress.org/mauricevanloon/` HTTP 200; the latest
  WordPress 7.1.2 (api.wordpress.org), so `Tested up to: 7.1` stands;
  every suite after the commit (release suite and Plugin Check on the
  zip included).
- Not done: the online readme validator (it sends the readme to
  wordpress.org; asked first).
- Decided by Maurice: the username. Not pushed.

## 2026-09-27 — The readme validator

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: run wordpress.org's readme validator.
- Measured: the readme of `8083358`, pasted into the validator in Chrome
  and submitted with its own button (a plain POST with `curl` returned
  the page without a result): no errors, no warnings; notes only, "The
  following tags are not widely used: c2pa, provenance" and "No donate
  link was found".
- Produced: the tick in `notes/wporg-submission.md`.
- Decided by Maurice: run the validator. Not pushed.

## 2026-09-27 — One readme sentence

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: fix "the checks run when the site's own cron job runs it" in the
  FAQ on "Check pending".
- Produced: "the checks run with the site's own cron job".
- Measured: `composer check` (the readme test) before the commit.
- Decided by Maurice: the wording; push.

## 2026-09-27 — A verdict belongs to one file (SPEC-014)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a thorough final review before submission (three review agents:
  security, wordpress.org guidelines, behaviour); then (Maurice) four
  specs, SPEC-014 first.
- Measured before: an image rotated in WordPress's editor kept its `Valid`
  while its file had no credential any more (a re-check gave `none`); an
  edit before the background check made the check read the uploaded file.
  Read in core 7.1.2: `wp_save_image()` and `wp_restore_image()` call
  `update_attached_file()` before `wp_update_attachment_metadata()`.
- Tests first, seen red: AC1–AC7 (AC1: the old entry stayed; AC4: no
  "Changed since its check"; AC5: no `file` recorded).
- Produced: the file to check kept in `_provemark_c2pa_source` (no longer
  in the event's arguments), updated in the `wp_update_attachment_metadata`
  filter; a checked image whose original changes is checked again; the
  entry records `file`, `size`, `modified`; the display shows "Changed
  since its check" (no verdict, signer or AI label) when they no longer
  match; the plugin's own relative-path helper replaces core's private
  `_wp_relative_upload_path()`; uninstall, badge colour, readme FAQ.
  `stable()` in the tests leaves the three new fields out, as the verifier
  CLI does not produce them.
- After: SPEC-014 7 passed; `composer check` 52, integration 123,
  multisite 6; the release suite after the commit.
- Decided by Maurice: SPEC-014 approved. Not pushed.

## 2026-09-27 — SPEC-014 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-014 implemented and push.
- Produced: the status line; Traceability was filled in `775790a`.
- Decided by Maurice: SPEC-014 implemented; push.

## 2026-09-27 — Robustness fixes from the final review (SPEC-015)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: SPEC-015, the second of four specs from the final review.
- Measured before: a GIF's bytes gave `none` ("No Content Credentials"),
  though the verifier reported `general.error`, unsupported file type;
  `Display::text('Reuters&#x202E;gnp.exe')` returned the entity unchanged;
  a PDF's column said "Not checked"; the verifier's version sits in the
  plugin's own `vendor/composer/installed.php` (repository and zip).
- Tests first, seen red: AC1–AC11 except AC6, which was green: without
  arguments `do_action()` passes `''`, so the reviewed fatal error did not
  exist; with `null` it did, and the test covers both.
- Produced: `Outcome` (`error` / `unsupported` or `unreadable` when the
  verifier failed without a manifest; `bounded()`: 256 characters per
  text, 50 codes plus `codes_omitted`); `Checker::version()` from
  `installed.php`; `htmlspecialchars()` in `Display::text()`; "and N more";
  nothing shown or filtered for other formats; a PHP 8.3 guard in the main
  file (`version_compare()`, as PHPStan calls `PHP_VERSION_ID < 80300`
  always false); optional `renderSelect()` parameters; a deactivation hook
  (every site when network-deactivated); no rows for an attachment deleted
  during its check; `wp_raise_memory_limit()` in the command; the index
  backfill removed; SPEC-007 amendment 3.
- Changed while building: `wp_json_encode()` in `TrustConfig` broke 21
  integration and 2 unit tests, as the tests build the CLI's settings with
  that class outside WordPress; `json_encode()` stays there with a
  reasoned `phpcs:ignore`, and AC11 says so. AC10's test lowers the limit
  in WP-CLI's `before_invoke`, as WP-CLI resets `--exec` and
  `plugins_loaded` settings to -1.
- After: `composer check` 55, integration 131, multisite 7; the release
  suite after the commit.
- Decided by Maurice: SPEC-015 approved. Not pushed.

## 2026-09-27 — SPEC-015 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-015 implemented and push.
- Produced: the status line; Traceability was filled in `e755bd9`.
- Decided by Maurice: SPEC-015 implemented; push.

## 2026-09-27 — Packaging for review (SPEC-016)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: SPEC-016, the third of four specs from the final review.
- Measured before, in the zip of `3b29bea`: Strauss's
  `vendor/composer/autoload_aliases.php` writes PHP into the plugin folder
  and includes it, and nothing loads it (`vendor/autoload.php`,
  `autoload_real.php`, `autoload_static.php` do not name it); `README.md`
  said "not released yet" and linked to folders that do not ship.
- Tests first, seen red: SPEC-016 AC1–AC4, and SPEC-006 AC1 once
  `README.md` moved to what must not ship.
- Produced: `tools/build.sh` removes the alias autoloader; `README.md`
  export-ignored (SPEC-006 amendment 6); `readme.txt` without the Upgrade
  Notice and with a sentence on the AI claim of an untrusted signer;
  `notes/wporg-review.md` on the `.pem` files, the slug and the callbacks.
  AC3's test joins the readme's lines first ("Intact: signer" wraps).
- After: `composer check` 57; the release suite after the commit.
- Decided by Maurice: SPEC-016 approved. Not pushed.

## 2026-09-27 — SPEC-016 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-016 implemented and push.
- Produced: the status line; Traceability was filled in `4afb31e`.
- Decided by Maurice: SPEC-016 implemented; push.

## 2026-09-27 — One queue for the background checks (SPEC-017)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: SPEC-017, the last of four specs from the final review.
- Tests first, seen red: AC1–AC7 (AC7 first red on `__()` too, as the
  test did not yet exclude core's translation functions; then on
  `_get_cron_array` alone).
- Produced: one event without arguments; `runQueue()` schedules a safety
  run 60 s ahead, checks up to 20 pending images oldest first while half
  of the time limit is left, and schedules itself again only while images
  are pending; `scheduleQueue()`; "Check pending" and its filter count any
  marker while the queue is scheduled; `unscheduleCheck()` and
  `_get_cron_array()` removed. Test helpers: one queue run per call,
  counting cleared markers; SPEC-013 amendment 2.
- Found while building: scheduling the queue only when none was scheduled
  let a new upload wait up to a minute behind a safety run; a later run is
  now brought forward. `get_posts()`'s stub types `post_mime_type` as a
  string, so the types go comma-separated. Plugin Check reported an
  explicit `'suppress_filters' => true` as an ERROR
  (`WordPressVIPMinimum.Performance.WPQueryParams`); `get_posts()` sets it
  by default, so the line went and the query is the same.
- After: `composer check` 58, integration 136, multisite 7; the release
  suite after the commit.
- Decided by Maurice: SPEC-017 approved. Not pushed.

## 2026-09-27 — SPEC-017 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-017 implemented and push.
- Produced: the status line; Traceability was filled in `e7eaf0d`.
- Decided by Maurice: SPEC-017 implemented; push.

## 2026-09-27 — Fixes from the review of SPEC-014 to SPEC-017 (SPEC-018)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: rebuild the final zip, check the screenshots, and a short review
  of the code added since `3b29bea` (two review agents); then (Maurice)
  SPEC-018, with decision A: a verdict describes the file visitors see.
- Measured: the zip of `4dc346a` (release suite green; the screenshots
  still true, no PDF rows). The review: a symlinked uploads folder made
  every verdict "Changed since its check" and every metadata save re-check
  (reproduced by the reviewer, confirmed in the code); an edited scaled
  image kept `Trusted` (reproduced by the reviewer); a short invalid UTF-8
  text was not stored; the main file used PHP 8.1 syntax before its PHP
  guard.
- Tests first, seen red: AC1–AC9 (AC1 an absolute path; AC3 the old
  result stored; AC4 five checks for three images; AC5 none checked).
- Produced: `UploadHook::shownFile()` / `fileToCheck()`, one rule for
  which file a verdict describes, used by the filter, the display, the
  queue and the command; `relativeToUploads()` compares as given and
  resolved; `checkAndStore()` keeps nothing when the file changed during
  the check; the queue claims an image by deleting its marker and checks
  at least one per run; `mb_scrub()` in `Outcome::bounded()`;
  `[UploadHook::class, 'deactivate']`; notices for `activate_plugins`
  only; a reason on every `phpcs:ignore`; a FAQ sentence on migrations
  and external storage.
- After: `composer check` 62, integration 141, multisite 7; the release
  suite after the commit.
- Decided by Maurice: SPEC-018 approved, option A. Not pushed.

## 2026-09-27 — SPEC-018 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark SPEC-018 implemented and push.
- Produced: the status line; Traceability was filled in `5cc7392`.
- Decided by Maurice: SPEC-018 implemented; push.

## 2026-09-27 — SPEC-018 amendment 1, from a last review of its change

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a last short review of SPEC-018's change (one review agent);
  then (Maurice) amendment 1.
- Measured (by the reviewer, confirmed in the code): with
  `image_editor_output_format` mapping JPEG to WebP, WordPress's copy is
  `name-scaled.webp` while `original_image` is `name.jpg`; `shownFile()`
  compared whole names and the queue checked the copy: `none` for a photo
  that is `Valid` otherwise, a regression of SPEC-018. Reasoned: an
  attachment deleted as its check starts could leave rows, as the new
  early return came before the deleted-post check.
- Tests first, seen red: AC10 (`none` instead of `Trusted`, copy
  `-scaled.webp`), AC11 (2 rows left).
- Produced: `shownFile()` compares names without their extension; the
  deleted-post check moved before the changed-file return.
- After: SPEC-018 11 passed; `composer check` 62, integration 143,
  multisite 7; the release suite after the commit.
- CI of `da84e45` failed once, not green as this entry first said: SPEC-017
  AC4's test compared state counts with `toBe()`, which also compares key
  order, and which image dies first depends on markers set in the same
  second. The counts were right (1 `error`, 2 still pending). The test now
  sorts them first; the plugin is unchanged by it.
- Decided by Maurice: amendment 1 approved. Not pushed.

## 2026-09-27 — The submission overview, and the zip to submit

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the final zip after a green CI; the overview for wordpress.org's
  submission form; record both.
- Measured: CI of `d938e6c` green on every job; the zip built from it,
  release suite green (Plugin Check clean), 279,526 bytes, SHA-256
  `aa393b53…7a57b7d6`, version 0.1.0, verifier v0.2.4.
- Produced: the overview text and the zip's hash in
  `notes/wporg-submission.md`.
- Decided by Maurice: record it and push. Submission is his.

## 2026-09-27 — Two open points recorded

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: whether the plugin still has problems; then record two of the
  risks named in `NOTES.md`.
- Produced: open points 3 (media offloaded right after upload: the
  background check finds no file) and 4 (the lower verifier findings of
  its step 157, still open in v0.2.4). Both reasoned, not measured.
- Decided by Maurice: record them. Not pushed.

## 2026-09-27 — The submission form as it is

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: help fill in wordpress.org's submission form; then correct the
  checklist.
- Measured: the form, read in Chrome while logged in (nothing filled in,
  nothing ticked, nothing uploaded): eight confirmations, each a statement
  by the account owner, and a zip upload of at most 10 MB; no overview
  field; 185 plugins awaiting a first review, "1 and 10 days"; ownership
  of a brand name is verified through the account's e-mail address, which
  is not on a Provemark domain.
- Produced: `notes/wporg-submission.md` corrected (the form's contents,
  the review time, the overview kept for later); the ownership decision.
- Decided by Maurice: option B, submit as is and show the GitHub
  organisation if asked. The confirmations and the submission are his.
  Not pushed.

## 2026-09-27 — Submitted to wordpress.org

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: Maurice submitted the plugin; record it.
- Measured: the zip in `build/` still had the SHA-256 recorded for the
  submission (`aa393b53…7a57b7d6`); a copy with the same hash kept in
  `build/submitted/` (ignored by git, not cleared by `tools/build.sh`).
- Produced: the submission ticked in `notes/wporg-submission.md`.
- Decided by Maurice: the submission (done by him). Not pushed.

## 2026-09-27 — A pre-public audit, and the README brought up to date

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: whether to make the repository public; a read-only audit first;
  then (Maurice) update the README, and fix the open verifier point in the
  verifier before going public.
- Measured over all 110 commits: no private key (five header variants), no
  assistant attribution in messages, authors or committers, no local paths
  (`/Users/…`, `/private/tmp`) now or in history, no tokens; no GitHub
  secrets, issues, pull requests or releases; the fixtures' sources and
  licences listed in `tests/Fixtures/README.md`. Found: `NOTES.md` open
  point 4 describes an unfixed verifier weakness (in history since
  `50be222`); `README.md` still said "not released yet"; every commit
  carries the maintainer's e-mail address as author (his decision).
- Produced: `README.md`: the status (submitted, awaiting review), the
  background check and which file it checks, re-checks on edits, sort and
  filter, WP-CLI, multisite, a pointer to `readme.txt`, four wp-env
  environments (it said three), how `composer build` and Strauss make the
  zip.
- Decided by Maurice: update the README; fix the verifier point there
  first. Not pushed. README.md does not ship, so the submitted zip stands.

## 2026-09-27 — The bundled verifier to v0.2.5

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the verifier's "push en release 0.2.5", including the plugin's
  update; after the review, "baseline 621 gereviewd, optie a".
- Produced: `composer.json` requires `^0.2.5`, `composer.lock` at v0.2.5
  (`0e1da23`); `tests/wpcs-verifier-baseline.json` (`ExceptionNotEscaped`
  621, verifier v0.2.5); SPEC-006 amendment 7; SPEC-015 amendment 1 and
  `bundledVerifierVersion()` in `tests/Integration/RobustnessTest.php`
  (AC2 follows `vendor/composer/installed.php`); `notes/wporg-review.md`
  (counts and line numbers); `NOTES.md` (open point 4 mostly closed, the
  upgrade step done for v0.2.5).
- Measured: `composer check` (62 passed); `composer test:multisite` (7
  passed); `composer test:integration` twice: the first run had five
  failures in `BackgroundCheckTest` and `DisplayTest` (a WebP's entry where
  a JPEG's was expected), and the second run had none of them and one
  failure, RobustnessTest AC2 on its pinned `v0.2.4`. The two files alone
  passed 38 of 38. The order dependence is not explained.
  `tests/Integration/RobustnessTest.php` after the change: 9 passed;
  `composer test:release` first 10 passed and AC4 failed
  (`ExceptionNotEscaped: 621 (baseline 616)`), then 11 passed. The five
  findings were located by running the sniff over the verifier's `src/`
  at `v0.2.4` and `v0.2.5`.
- Decided by Maurice: baseline 621 reviewed and accepted; option a for
  AC2. Not pushed.

## 2026-09-27 — A new version uploaded for review

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: Maurice uploaded the zip with verifier v0.2.5 on the submission
  page; record it.
- Measured: every suite on `3566af8` (`composer check` 62, integration
  143, multisite 7, release 11 with Plugin Check clean) and its CI green;
  the zip in `build/` still had the hash recorded, and a copy with the same
  hash is kept in `build/submitted/` next to the first submission.
- Produced: the upload recorded in `notes/wporg-submission.md`.
- Decided by Maurice: the upload (done by him). Not pushed.

## 2026-09-27 — The Provemark sites, and a Development section (SPEC-019)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: after Maurice made the repository public, mention the plugin on
  the general Provemark sites; then SPEC-019, a link to the development
  location in `readme.txt` (guideline 4).
- Produced, in the other repositories, pushed on Maurice's go: a
  c2pa-check block in the organisation profile (`provemark/.github`
  `03782d5`, and `f58156d`: c2pa-verifier's install line, as it is on
  Packagist) and a c2pa-check card on provemark.github.io (`eed7244`), both
  saying it is submitted and awaiting review. Here: SPEC-019 and a
  `== Development ==` section naming this repository, `composer build` with
  Strauss, and the verifier's repository.
- Measured: both sites live with the plugin (GitHub Pages build green);
  guideline 4's wording read again; SPEC-019 AC1 red first, then green;
  `composer check` 63; the release suite after the commit.
- Decided by Maurice: the site texts; SPEC-019 approved. Not pushed.

## 2026-09-27 — A flaky queue test, and the submission frozen

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: whether to upload the zip with SPEC-019 as well (Maurice: repeated
  uploads look odd); then fix the failing CI and freeze the submission.
- Measured: CI of `445480d` (notes only; the code of `3566af8`, which was
  green) failed on PHP 8.5 in SPEC-017 AC4, which took 62.9 s: the test
  compared the safety run (60 s after the run began) with "now". Reasoned:
  its fixed `ini_set("memory_limit", "32M")` is refused when more is in
  use, so the run filled memory until Docker stopped it. Locally the test
  now takes about 4 s (was 6 s), twice green.
- Produced: the test sets the limit 8 MB above the memory in use and
  compares the safety run with the run's start; the plugin is unchanged.
  `notes/wporg-submission.md`: the submission frozen until the reviewer
  replies; SPEC-019 waits for that reply or 0.1.1.
- Decided by Maurice: no upload of the SPEC-019 zip; the test fix. Not
  pushed.

## 2026-09-28 — Renamed to Tracefern Image Check for C2PA (SPEC-020)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: work through wordpress.org's first review; it questioned the name
  ("ProveMark" possibly someone else's brand, "C2PA" not after "for").
  Then find English name candidates without a Dutch second meaning.
- Measured: Blockchain Commons publishes "Provenance Marks" under
  `developer.blockchaincommons.com/provemark/`. About twenty coined names
  checked against the directory API (`plugin_information`,
  `query_plugins`) and a web search; most were companies already
  (Tracewell, Credwise, Rootline, Sealwise, Provenly, Heronsight).
  "tracefern": no plugin, no product; only a housing subdivision.
- Produced: the repository renamed on GitHub to
  `provemark/tracefern-image-check` (Maurice's go) and `origin` updated;
  SPEC-020; the main file `tracefern-image-check-for-c2pa.php`; slug, text
  domain, namespace `Tracefern\ImageCheck` (Strauss prefix too), prefix
  `tracefern_` for options, meta, cron event and settings group, CSS
  classes and `wp tracefern`, in code, tests, CI, build and docs; the
  naming sections in `notes/`; the banner re-rendered with the new name
  (title 64 px, one line; looked at). The verifier keeps its own name.
- Tests first, seen red: `tests/Unit/NameTest.php` (the main file missing;
  54 files with the old name), then green. After: `composer check` 65,
  integration 143, multisite 7; the release suite after the
  commit.
- Reasoned: no migration of stored data, as no version was released; not a
  legal trademark search; reserved slugs do not show in the API.
- Not yet done: the plugin's block on the organisation profile and the
  card on provemark.github.io still say "c2pa-check" (other repositories,
  Maurice's go needed); the reply to the reviewer.
- Decided by Maurice: the name Tracefern (not his own name; not "Keel…",
  which means throat in Dutch); rename the repository. Not pushed.

## 2026-09-28 — Named sanitize callbacks (SPEC-021)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the review's "Sanitization for register_setting()" point.
- Reasoned: both settings already had a sanitize callback, a closure and
  a first-class callable; the review's scanner recognises a callback by
  name.
- Tests first, seen red: SPEC-021 AC1 (neither callback named), AC2
  (`'false'` gave `true`). After: `'rest_sanitize_boolean'` and
  `[self::class, 'sanitizeCustom']`; SPEC-021, SPEC-004 and SPEC-012
  tests 20 passed; `composer check` 65.
- Decided by Maurice: the fix. Not pushed.

## 2026-09-28 — The trust notice only on its screens (SPEC-022)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the review's guideline 11 point (notices limited in scope).
- Tests first, seen red: SPEC-022 AC2 (the notice on the dashboard, the
  posts list and the plugins screen); AC1 green before and after, as a
  guard. SPEC-004 AC6's test now sets the Media Library screen.
- Produced: `trustNotice()` returns unless the screen is `upload`,
  `media`, `attachment` or the settings page; `ON_SCREEN` and
  `ON_MEDIA_LIBRARY` in `tests/Pest.php`.
- Measured: SPEC-022 and SPEC-004 tests 22 passed; `composer check` 65.
  The full suites follow with SPEC-023.
- Decided by Maurice: the notice on those screens; no dismiss button. Not
  pushed.

## 2026-09-28 — A code review as the reviewer would; the settings page renamed (SPEC-023)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: review the whole plugin for what the (pre-)reviewer may find;
  then (Maurice) "ga maar door".
- Measured (read: the main file, `uninstall.php`, all of `src/`,
  `readme.txt`, `trust/README.md`): the settings page was still "C2PA
  Check", C2PA first; the shipped `composer.json` named the package
  `provemark/…`. Nothing new on escaping, sanitizing, nonces, prefixes or
  direct access. Points left as they are, with reasons: the stylesheet on
  every admin screen (the media modal opens anywhere), no `ABSPATH` guard
  in the bundled verifier (classes only), `$_GET` without a nonce in the
  read-only list filter, `@fopen`.
- Tests first, seen red: SPEC-023 in `tests/Unit/NameTest.php` (four files
  with "C2PA Check"; the menu label; the Composer name).
- Produced: menu label "Tracefern", heading "Tracefern Image Check for
  C2PA", "Settings → Tracefern" everywhere, `composer.json` name
  `mauricevanloon/tracefern-image-check`; the release test expects the new
  heading.
- Measured after: `composer check` 66, integration 152 (SPEC-021 and
  SPEC-022 included), multisite 7; the release suite after the commit, 11 passed (Plugin Check clean),
  with the old `provemark-c2pa-check` removed from the release environment,
  where it was still active; screenshot 3 taken again in Chrome (menu
  "Tracefern", the new heading; looked at, no cursor).
- Decided by Maurice: SPEC-022, SPEC-023 and the Composer name. Not pushed.

## 2026-09-28 — Pushed; the Provemark sites follow the new name

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: push, and update the two other repositories.
- Produced, pushed on Maurice's go: the organisation profile
  (`provemark/.github` `ccae384`) and the homepage card
  (`provemark.github.io` `d483304`) name "Tracefern Image Check for C2PA"
  and link to `provemark/tracefern-image-check`; both still say it awaits
  review. This repository pushed up to `432a64b`.
- Measured: both remotes at those commits; the GitHub Pages build of
  `d483304` built, and the live homepage links to the new repository.
- Decided by Maurice: push and update both. The upload of the new zip and
  the reply to the reviewer are his.

## 2026-09-28 — A paragraph on the bundled library, ready to paste

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: whether the plugin will pass review (no: the manual review is
  still to come); then a short paragraph on the verifier's WPCS findings,
  to send only if the reviewer asks.
- Produced: that paragraph at the top of `notes/wporg-review.md`; in the
  same file, the options renamed, `tracefern_index_done` dropped (nothing
  reads it since SPEC-015), the verifier no longer called "third-party".
- Measured, for the paragraph's claims: `src/Checker.php` stores a fixed
  reason, `src/SettingsPage.php` escapes the one message it shows; the
  counts in `tests/wpcs-verifier-baseline.json` (v0.2.5).
- Decided by Maurice: prepare the paragraph. Pushed on his earlier go.

## 2026-09-28 — The corrected version uploaded, the reply sent

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: put the reply on the clipboard; then record the upload and the
  reply (both done by Maurice).
- Measured: the zip in `build/` still had the SHA-256 of `432a64b`'s build
  (`66335707…07a804af`, 290,831 bytes); CI of `432a64b` green. A copy
  kept in `build/submitted/`.
- Produced: the first review and the second upload recorded in
  `notes/wporg-submission.md`.
- Decided by Maurice: the upload and the reply (done by him). Not pushed.

## 2026-09-28 — SPEC-019 to SPEC-023 implemented

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: mark the specs in the uploaded zip implemented, note that
  SPEC-019 went along, and push.
- Produced: the status lines of SPEC-019 to SPEC-023 (Traceability was
  filled when each was built); the frozen item in
  `notes/wporg-submission.md` updated.
- Measured: CI of `81187f2` and `975d295` green; the verifier, demo and
  Content Credentials repositories no longer name the old plugin.
- Decided by Maurice: SPEC-019 to SPEC-023 implemented; push.

## 2026-09-28 — 0.1.0 released in the plugin directory

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: after the approval, install Subversion, prepare the SVN working
  copy, and check the public page once Maurice had committed.
- Produced: the working copy (outside the repository): `trunk/` from the
  approved zip, `tags/0.1.0/` by `svn cp`, the eight images in `assets/`;
  section 3 and 4 of `notes/wporg-submission.md` updated.
- Measured: the approved zip (downloaded from the review's link) equals
  the zip kept in `build/submitted/`; no shipped file changed between
  `432a64b` and `c3c972d`; after r3716634 the public download unpacks to
  the same files as the approved zip, and the plugin API reports 0.1.0
  with four screenshots, two banners and two icons.
- Reasoned: search results can take up to 72 hours (the approval email).
- Decided by Maurice: the SVN commit (done by him). Not pushed.

## 2026-09-28 — Tag v0.1.0

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: push, and tag v0.1.0.
- Produced: the annotated tag `v0.1.0` on `432a64b`, the commit whose
  build is the released zip (the zip names it in
  `vendor/composer/installed.php`); the checklist item ticked.
- Measured: `c6cdbfc` and the tag on `origin`.
- Decided by Maurice: push and tag.

## 2026-09-28 — GitHub release 0.1.0

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: a GitHub release for v0.1.0.
- Produced: the release, its notes from the `readme.txt` changelog, and
  the approved zip attached; the checklist item updated.
- Measured: the attached zip is 290,831 bytes, the same file as in
  `build/submitted/`.
- Decided by Maurice: make the release. Not pushed.

## 2026-09-28 — SPEC-024 drafted: a Live Preview

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: how to bring the plugin to people's attention; then look into
  the directory's Live Preview and write the spec.
- Produced: `specs/SPEC-024-live-preview.md` (draft).
- Measured: the handbook page "Previews and Blueprints"; a draft
  blueprint in Playground CLI installing 0.1.0 from the directory (PHP
  8.3.33, WordPress 7.1.2, openssl and mbstring present; five images
  checked after the due cron events, results as the integration tests
  expect; 12.6 s); CORS headers of the fixture URLs.
- Reasoned: the browser Playground behind the button, its load time, and
  WP-Cron for a visitor's own upload; to be measured with "Test Preview".
- Decided by Maurice: look into it and draft the spec. Not approved yet.

## 2026-09-28 — SPEC-024 built: the Live Preview's blueprint

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: approve SPEC-024; write the tests, then the blueprint.
- Produced: `.wordpress-org/blueprints/blueprint.json`;
  `tests/Unit/BlueprintTest.php`, `tests/Release/PreviewTest.php`, two
  helpers in `tests/Pest.php`; `@wp-playground/cli` 3.1.55 pinned; CI:
  full history in the check job (the test reads tag v0.1.0), and the one
  install script Playground CLI needs in the release job; section 4a in
  `notes/wporg-submission.md`; the spec's Traceability.
- Measured: the five tests red first (no blueprint), then green;
  `composer check` (67 tests) and the whole Release suite (15 tests) green
  locally; the committed blueprint, installing 0.1.0 from the directory,
  runs to the end in Playground CLI. With `npm ci --ignore-scripts` alone
  Playground CLI fails ("Failed to load fs-ext native module"); after
  `npm rebuild fs-ext-extra-prebuilt` it runs.
- Reasoned: the browser Playground behind the button; measured with "Test
  Preview" after the SVN commit.
- Decided by Maurice: SPEC-024 approved; build it. Not pushed.

## 2026-09-28 — The Live Preview measured in the browser

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: check the Test Preview once the blueprint was committed (r3716729).
- Measured: the directory imported the blueprint (served content equals
  git); no Test Preview button on the page while the account asks for
  two-factor authentication; the preview link opened in Chrome shows the
  five verdicts after about 12 s; an image uploaded in the preview stays
  "Check pending" (WP-Cron does not run there, `wp-cron.php` answers 503).
- Reasoned: that the missing two-factor authentication hides the button,
  from the directory's `Template::is_preview_available()`.
- Produced: section 4a of `notes/wporg-submission.md` updated.
- Decided by Maurice: nothing yet; how to handle uploads in the preview
  is open (SPEC-024, open question 1). Not pushed.

## 2026-09-28 — SPEC-024 amendment 1 proposed: checks in the preview

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: write the amendment for checking a visitor's own upload in the
  preview.
- Produced: amendment 1 in `specs/SPEC-024-live-preview.md` (proposed):
  a must-use plugin written by the blueprint that runs the plugin's
  `tracefern_check` queue on admin page loads when it is due; AC6, AC7,
  and an addition to AC1.
- Measured: see the previous entry (WP-Cron does not run in the browser
  Playground; the browser uploader redirects to the Media Library).
- Reasoned: that the drag-and-drop uploader's image is checked on the
  next admin page; that `admin_init` runs before the upload creates the
  attachment.
- Decided by Maurice: write the amendment. Not approved yet; not pushed.

## 2026-09-28 — SPEC-024 amendment 1 built: checks in the preview

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: approve amendment 1; tests, then the must-use plugin; correct
  AC6's wording (approved by Maurice).
- Produced: the blueprint writes `mu-plugins/tracefern-preview-checks.php`
  (on `admin_init`, runs `tracefern_check` when it is due); tests for AC1
  (amendment), AC6 and AC7 with a harness that loads `wp-admin/upload.php`
  through `admin.php` as the logged-in admin and logs each admin load;
  AC6's wording corrected; Traceability; checklist in
  `notes/wporg-submission.md`.
- Measured: Playground CLI 3.1.55 skips the `request` step ("no longer
  supported"); inside `runPHP`, Playground's auto-login redirects on
  `init` unless its cookie is set, and the user must be resolved again
  after setting the auth cookies. AC1 (amendment) and AC6 red first for
  the right reason, AC7 green before and after (it guards against harm);
  all green after; `composer check` (68 tests) and the Release suite (17
  tests) green; the committed blueprint runs on its own.
- Reasoned: that the browser Playground behaves like the CLI here; to be
  measured after the SVN commit.
- Decided by Maurice: amendment 1 approved; AC6 wording corrected. Not
  pushed.

## 2026-09-28 — SPEC-025 drafted: say what sets the plugin apart

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: make it clear on the plugin page that this plugin differs from
  plugins that label AI images without verifying; write the spec.
- Produced: `specs/SPEC-025-say-what-sets-it-apart.md` (draft): a new
  short description, a first Description paragraph and a first FAQ
  question, no other plugin named.
- Measured: four AI-label plugins from the directory in Playground with
  the SPEC-024 fixtures (the changed AI image labelled as the intact one
  by three; the camera photo labelled AI by two); a code search of
  thirteen that read C2PA (no signature or hash check found); the
  handbook pages on the stable tag and on Subversion.
- Reasoned: that a readme-only edit of the stable tag needs no new
  version.
- Decided by Maurice: write the spec; do not write the measurements into
  `notes/`. Not approved yet; not pushed.

## 2026-09-28 — The preview checks a visitor's upload (measured)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: check the preview in the browser after the SVN commit r3716856.
- Measured: the directory served the new blueprint from 12:43 (32
  minutes after the commit), same content as git; in Chrome, the five
  verdicts after about 25 s; an image uploaded through the browser
  uploader got "Intact: signer not trusted" (`Valid`) on the redirect to
  the Media Library.
- Reasoned: the drag-and-drop uploader (checked on the next admin page);
  not measured.
- Produced: the checklist item in `notes/wporg-submission.md`.
- Decided by Maurice: the SVN commit (done by him). Not pushed.

## 2026-09-28 — SPEC-025 built: the readme says what sets the plugin apart

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: approve SPEC-025 with option (a) and without plugin names; the
  tests, then the text.
- Produced: the short description, the first Description paragraph and
  the first FAQ question in `readme.txt`; four tests in
  `tests/Unit/ReadmeTest.php`; the spec anonymised and its Traceability.
  The two unpushed commits that named the measured plugins were rewritten
  before any push, so the names are not in the published history.
- Measured: AC1–AC3 red first (text absent), AC4 green before and after
  (a guard) and red with a planted link to another plugin; all green
  after two fixes in the test code (an answer regex that stopped at the
  first line end; an allow-list that tripped the old-name test); `composer
  check` (72 tests) and the Release suite with Plugin Check (17 tests)
  green; `readme.txt` 8,900 bytes.
- Decided by Maurice: SPEC-025 approved, (a) a readme-only update of
  trunk and tags/0.1.0, names anonymised. Not pushed.

## 2026-09-28 — SPEC-026 drafted: other plugins can read the verdict

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: how much work a connection to WordPress's C2PA Monitor would be;
  then a spec for the first option, a readable verdict for any plugin.
- Produced: `specs/SPEC-026-verdict-for-other-plugins.md` (draft): a
  `tracefern_verdict` filter returning a documented array built by the
  column's own code; a FAQ entry.
- Measured: the Monitor's code in WordPress/ai#459 (no filters or actions
  of its own; column `wpai_c2pa`; meta `_wpai_monitor_record`).
- Reasoned: that a filter with a `null` default needs no dependency; that
  the Monitor or label plugins could use it.
- Decided by Maurice: write the spec. Not approved yet; not pushed.

## 2026-09-28 — SPEC-026 built: other plugins can read the verdict (0.1.1)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: approve SPEC-026 (version 0.2.0, changed the same day to
  0.1.1); tests, then the code.
- Produced: `src/Verdict.php` (the `tracefern_verdict` filter);
  `Display::verdict()` built from the column's own reading of the entry,
  and `Display::visible()` split from `Display::text()`; three
  `MediaScreens` helpers made public; the FAQ entry; version 0.1.1 in the
  header, `Stable tag` and changelog; `tests/Integration/VerdictTest.php`
  and a readme test; the spec's Traceability.
- Measured: the eight tests red first (no filter, no FAQ), then green
  after one fix in the test code (a message passed to `toContain()`,
  which takes several needles, became a second needle); `composer check`
  (74 tests), the integration suite (159), the Release suite with Plugin
  Check (17) and the multisite suite (7) green locally.
- Decided by Maurice: SPEC-026 approved; 0.1.1. Not pushed; not released.

## 2026-09-28 — 0.1.1 released

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: prepare the 0.1.1 release; after Maurice's SVN commit, check it,
  tag `v0.1.1` and make a GitHub release.
- Produced: the build from `46e9f47` in `trunk/` and `tags/0.1.1` of the
  SVN working copy; the tag `v0.1.1`; the GitHub release with that zip;
  the release recorded in `notes/wporg-submission.md` (also the
  readme-only update of SPEC-025).
- Measured: the Release suite green on the committed build (an earlier
  local run preceded the commit and so built the previous one); the
  plugin API reported 0.1.1 at 14:53, 39 minutes after r3717131; the
  public download unpacks to the same files as the build.
- Decided by Maurice: the SVN commit (done by him), the tag and the
  GitHub release. Not pushed.

## 2026-09-28 — The bundled verifier to v0.2.6

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the verifier's "ja, breng de plugin en de demo naar 0.2.6"; after
  the review, "baseline naar 4 akkoord".
- Produced: `composer.json` requires `^0.2.6`, `composer.lock` at v0.2.6
  (`32ab4eb`); `tests/wpcs-verifier-baseline.json`
  (`obfuscation_base64_decode` 4, verifier v0.2.6); SPEC-006 amendment
  8; `NOTES.md` (the upgrade step done for v0.2.6).
- Measured: `composer check` (74 passed); `composer test:multisite` (7
  passed); `composer test:integration` (159 passed);
  `composer test:release` first 16 passed and AC4 failed
  (`obfuscation_base64_decode: 4 (baseline 3)`), then 17 passed. The
  sniff over the bundled verifier's `src/` without `Cli/`: 642 findings,
  every count equal to the new baseline; the new one is
  `Trust/Certificate.php:305`, `rsaExponent()`.
- Decided by Maurice: baseline 4 reviewed and accepted.

## 2026-09-28 — 0.1.2 prepared

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "bereid 0.1.2 voor".
- Produced: version 0.1.2 in the plugin header and `Stable tag`; the
  changelog entry; the build from this commit in `trunk/` and
  `tags/0.1.2` of the SVN working copy (not committed).
- Measured: `composer check` (74 passed); the Release suite on the
  committed build (see the next entry).
- Decided by Maurice: prepare 0.1.2. The SVN commit, tag and GitHub
  release wait for him.

## 2026-09-28 — 0.1.2 released

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "svn commit gedaan, controleer de release"; then "probeer
  opnieuw" after the session's tool check failed four times.
- Produced: the release recorded in `notes/wporg-submission.md`.
- Measured: `svn log` (r3717314 by Maurice, 15:58); `svn ls tags/` (0.1.0,
  0.1.1, 0.1.2); `Stable tag: 0.1.2` in `trunk/` and `tags/0.1.2/`; the
  plugin API reports 0.1.2; the public download unpacks to the same files
  as the build (`diff -r` empty) and names verifier v0.2.6; the plugin
  page returns 200.
- Found done by Maurice: `main` pushed to `ebbcb62`, the annotated tag
  `v0.1.2` on `ebbcb62` and the GitHub release 0.1.2 with its zip. The
  release asset differs in bytes from `build/submitted/`'s copy
  (rebuilt at 16:07, 15:55 for the copy) but unpacks to the same files
  (`diff -r` empty).
- Decided by Maurice: the SVN commit, the push, the tag and the GitHub
  release (all done by him).

## 2026-09-28 — The 0.1.2 record corrected

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: correct the 0.1.2 entry in `notes/wporg-submission.md` and push.
- Produced: the entry now says the tag and the GitHub release were made
  on Maurice's go, after the entry was written, and that the release's
  zip holds exactly the files of SVN `tags/0.1.2` (SHA-256 `5ad5a395…`).
- Measured: the public 0.1.2 download unpacks to the same files as SVN
  `tags/0.1.2` (16:08); a rebuild of `ebbcb62` differs from it only in
  Composer's autoloader class name, which changes with every build.
- Decided by Maurice: the correction and the push.

## 2026-09-28 — SPEC-027 drafted: AI in the image's history

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: test the sample files C2PA staff shared, then draft a spec for
  what they showed.
- Produced: `specs/SPEC-027-ai-in-the-history.md` (draft). `ai` also
  follows `parentOf` ingredients; a new `ai_edited` key and label for
  `compositeWithTrainedAlgorithmicMedia` and for AI in `componentOf` or
  `inputTo` ingredients; an ingredient is followed only when its failures
  are at most `signingCredential.untrusted`.
- Measured: with verifier v0.2.6 and the bundled trust settings, both
  shared samples are `Trusted` with no ingredient failures, and neither
  gets a label today. One has AI origin two `parentOf` steps down; the
  other has `compositeWithTrainedAlgorithmicMedia` in the active
  manifest. `c2pa-rs-ocsp.jpg` is `Valid`, with a `componentOf` AI
  ingredient whose only failure is `signingCredential.untrusted`. A scan
  of all JPEG, PNG and WebP fixtures here and in the verifier found no
  other licensed file with AI in an ingredient. The samples are not in
  the repository: their licence is unknown.
- Reasoned: the meaning given to the three relationships, and the
  ingredient bar (see the spec's References).
- Decided by Maurice: that the spec is written. Its open questions are
  his.

## 2026-09-28 — SPEC-027 implemented: AI in the image's history

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: build SPEC-027 as approved (proposals 2–4; a fixture of our
  own), then amendments 1 and 2.
- Produced: `Outcome::aiHistory()` (two walks: the parent line, then the
  whole history), the stored key `ai_edited`, the label "AI-edited
  (signed)" in the column and the details, `ai_edited` in the
  `tracefern_verdict` filter, the readme text; tests in
  `tests/Unit/AiHistoryTest.php` and `tests/Integration/AiHistoryTest.php`
  with an independent oracle in `tests/Pest.php`; the fixture
  `c2pa-verifier-ai-parent-chain.png`, copied from the verifier's step 178
  (`0cf13e4`, local).
- Measured: tests first, seen red. Unit: 19 failed (no `aiHistory()`),
  then the two amendment 2 cases failed. Integration: 13 failed (no
  `ai_edited`, no label, no fixture). Now: `composer check` green (95 unit
  tests, PHPStan max, Pint), integration 172 passed. With the bundled
  settings, `c2pa-rs-ocsp.jpg` gets "AI-edited (signed)", and its
  tampered copy is `Invalid` with no label. The new fixture is `Valid`
  and gets "AI-generated (signed)". While making it: an ingredient's
  recorded `validation_results` differ from the verifier's own
  `ingredientDeltas` (amendment 2).
- Reasoned: the relationship meanings and the ingredient bar (the spec's
  References).
- Decided by Maurice: approval with the proposals and his own fixture;
  amendments 1 and 2. Release as 0.1.3 still to come.

## 2026-09-28 — Prepare 0.1.3

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "ga door", after the offer to prepare 0.1.3 for SPEC-027.
- Produced: `Version: 0.1.3` in the plugin header, `Stable tag: 0.1.3`,
  and a 0.1.3 changelog entry. The SPEC-027 readme text was shortened to
  keep `readme.txt` under the 10 KB its test enforces (10,549 → 10,140
  bytes).
- Measured: `composer check` green (95 unit tests); integration 172
  passed; `composer test:release` 17 passed; `composer test:multisite` 7
  passed. The zip is built from this commit, because `tools/build.sh`
  packs `HEAD`.
- Decided by Maurice: preparing the release. The SVN commit, push, tag
  and GitHub release are his, or wait for his go.

## 2026-09-28 — 0.1.3 released

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, push en zet SVN klaar"; then "akkoord, leg vast en
  maak tag v0.1.3 met release".
- Produced: `main` pushed to `0737d8f` (and the verifier to `0cf13e4`).
  The SVN working copy had `trunk/` replaced by the build and
  `tags/0.1.3` copied. The annotated tag `v0.1.3` on `0737d8f`; the
  GitHub release 0.1.3 with the zip; the record in
  `notes/wporg-submission.md`.
- Measured: CI green on `0737d8f`. SVN r3717745 by Maurice (20:26). The
  plugin API reported 0.1.3 by 20:36. The public download, the build and
  SVN `tags/0.1.3` hold the same files (`diff -r` empty).
- Decided by Maurice: the push, the SVN commit (done by him), the tag and
  the release.

## 2026-09-29 — Image optimizers measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "kan je kijken naar wat er nog met deze plugin zou moeten
  gebeuren?"; then, after the proposal to measure an image optimizer next
  to the plugin, "akkoord, begin met B".
- Produced: `notes/image-optimizer.md`; `NOTES.md` "Open" point 5, and
  the client-side processing item under "To measure" marked resolved (it
  was measured on 2026-09-26 in `notes/m1-original-file.md`, but never
  ticked off). No plugin code.
- Measured: EWWW Image Optimizer 8.8.0 at its defaults in wp-env
  (WordPress 7.1.2, PHP 8.3.35, aarch64; its x86-64 tools copied in by
  hand for the second round), six signed fixtures via WP-CLI. Its upload
  resize replaced the two large JPEGs before the check (stored `none`);
  its background PNG optimization after the check kept the manifest and
  broke its hash (shown "Changed since its check"; `wp tracefern check`
  then stored `Invalid`, `assertion.dataHash.mismatch`, shown "Does not
  verify"). The small JPEG and the WebP were left alone.
- Reasoned: the order of the two background queues is a race; other
  optimizers were not tested; a check or hash in `wp_handle_upload`
  before priority 10 would see the uploaded bytes.
- Decided by Maurice: to measure this. Which option, if any, becomes a
  spec is his.

## 2026-09-29 — Readme: image optimizers

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, doe a en stel b voor als spec" (a: say it in the
  readme; b: a spec for recording the upload's fingerprint).
- Produced: the FAQ answer "Why does a genuine photo say "Does not
  verify"?" names image optimizers, and says that one resizing the
  original on upload removes the Content Credentials; a readme test
  for it (group `image-optimizer`). No spec: Maurice asked for the text
  directly; it follows `notes/image-optimizer.md`.
- Measured: the new test failed first (no mention of optimizers), then
  passed; `composer check` green (96 tests). `readme.txt` is 10,205
  bytes, under the 10,240 its test allows.
- Decided by Maurice: this readme text.

## 2026-09-29 — Draft SPEC-028: changed after upload

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: the same request, part b.
- Produced: `specs/SPEC-028-changed-after-upload.md`, status `draft`: a
  SHA-256 fingerprint of the upload taken at the earliest priority of
  `wp_handle_upload`, stored at `add_attachment`, compared by the check;
  the display adds "Changed after upload" next to the verifier's verdict.
  Eight acceptance criteria, three open questions (wording, readme room,
  a size limit for hashing).
- Measured: nothing new; it rests on `notes/image-optimizer.md` and
  `notes/m1-original-file.md`. Read in core 7.1.2: `_wp_handle_upload()`
  applies `wp_handle_upload` for sideloads too (`file.php:1074`).
- Reasoned: that the earliest priority runs before optimizers resizing
  there; the hashing cost.
- Decided by Maurice: to propose this as a spec. Approval is his.

## 2026-09-29 — Changelog to the GitHub releases; SPEC-028 questions

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "tekst akkoord; verplaats oude changelog naar GitHub releases".
- Produced: `readme.txt` keeps the 0.1.3 changelog entry and links the
  earlier ones to the GitHub releases; a readme test for that (group
  `changelog`). SPEC-028's two blocking questions marked resolved.
- Measured: the GitHub releases 0.1.0, 0.1.1 and 0.1.2 exist and hold the
  removed changelog text (`gh release view`), so nothing was published.
  The new test failed first, then passed; `composer check` green (97
  tests). `readme.txt` is 9,571 bytes.
- Decided by Maurice: the SPEC-028 wording; the changelog move.

## 2026-09-29 — EXIF rotation measured

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, begin met meting A" (a signed photo with EXIF
  rotation, the `-rotated` case `notes/m1-original-file.md` left open).
- Produced: `notes/exif-rotation.md`; `NOTES.md` "Open" point 6, and the
  `-rotated` case under "To measure" marked measured. No plugin code.
- Measured: three signed fixtures with orientation 6 from the verifier's
  tree (`c2pa-rs/no_alg.jpg`, two Truepic photos) via WP-CLI, REST and
  the block editor in Chrome 153 with client-side processing. WP-CLI and
  REST: the plugin checks the upload and equals the CLI (state and
  failure codes). Block editor: it stores `none`, having checked the
  browser's `-rotated-1` / `-scaled-1` copy; for the large photo
  `original_image` itself names the `-rotated-1` copy. Controls without
  rotation on the block editor route were correct.
- Reasoned: the cause, `UploadHook::shownFile()` matching only exact
  `-scaled` / `-rotated` names, so `onMetadataUpdate()` moves the kept
  path to the copy.
- Decided by Maurice: to measure this. The fix needs a spec.

## 2026-09-29 — Draft SPEC-029: the browser's copies

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, stel SPEC-029 op".
- Produced: `specs/SPEC-029-browser-copies.md`, status `draft`: a
  metadata update made while serving the finalize route does not move
  the kept path; a kept `-rotated-<n>` / `-scaled-<n>` copy is resolved to
  its upload when that file exists, so `wp tracefern check` repairs
  images stored wrongly; two fixtures to add. Seven acceptance criteria.
  A line added to `notes/exif-rotation.md`.
- Measured: the `_wp_sideloaded_file` rows after finalize (none, none and
  two for the three block-editor uploads), so core's own record cannot
  identify a copy later; a first draft relied on it and was rewritten.
- Reasoned: the browser's request sequence and the `-1` suffix, read in
  core 7.1.2 (`sideload_item()`, `finalize_item()`,
  `filter_wp_unique_filename()`, `upload-media.js`).
- Decided by Maurice: to draft this spec. Approval is his.

## 2026-09-29 — SPEC-029 built (tests first), with two amendments

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, SPEC-029 approved, bouw hem"; then, after each finding
  while building, "akkoord, wijziging 1 approved, bouw hem", "akkoord,
  doe die meting" and "akkoord, wijziging 2 approved, bouw hem".
- Produced: `UploadHook` notes the block editor's `sideload` and
  `finalize` requests (`rest_request_before_callbacks`); a finalize
  metadata update no longer moves the kept path; copies attached in those
  requests are recorded in `_tracefern_browser_copies`, and
  `fileToCheck()` uses the kept upload for them (display and
  `wp tracefern check`); uninstall removes the new key. Tests:
  `tests/Integration/BrowserCopyTest.php` and a REST replay of the
  browser, `browserUpload()`, in `tests/Pest.php`. Fixtures
  `c2pa-rs-no_alg.jpg` and `truepic-20230212-camera.jpg` with their rows
  in `tests/Fixtures/README.md`. SPEC-029 status `implemented`, with
  Traceability; `notes/exif-rotation.md` extended; `NOTES.md` point 6
  closed.
- Measured: amendment 1 — the approved name rule picked another
  attachment's file for two uploads with the same name (test environment,
  attachments 34093/34096). Amendment 2 — in Chrome 153 the browser sent
  its sideloads twice and no `finalize` for the large rotated photo
  (temporary server probe, removed). Red first: AC1, AC2, AC5, AC6, AC8
  and then AC9 failed before the code, AC3 and AC4 passed (unchanged
  behaviour). Green: the 8 SPEC-029 tests; integration 180 passed;
  `composer check` green (97 unit tests). By hand in Chrome 153: both
  fixtures stored `Invalid` with the CLI's codes on the upload, column
  "Does not verify".
- Reasoned: that the REST replay matches the browser for the cases it
  replays (checked by hand for two uploads only).
- Decided by Maurice: the spec and both amendments. The fixture name in
  the spec was changed after approval (`no_alg.jpg` →
  `c2pa-rs-no_alg.jpg`) and reported to him.

## 2026-09-29 — SPEC-028 draft brought up to date

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "eerst 28" (SPEC-028 before pushing).
- Produced: SPEC-028's open question on a size limit answered with a
  measurement and a proposal (no limit); a paragraph on the block editor
  route now that SPEC-029 keeps the upload. Still `draft`.
- Measured: `hash_file('sha256', …)` in the wp-env container (PHP 8.3.35,
  aarch64), best of five: 26.7 ms for 5.6 MB, 242 ms for 50 MB.
- Decided by Maurice: approval of SPEC-028 is his.

## 2026-09-29 — No Composer time limit for the test scripts

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "kan de integratiesuite niet wat sneller, het duurt lang
  altijd"; then "akkoord, doe 1 en 2" (1: run only the affected groups
  while building, the full suite once before a commit; 2: this).
- Produced: `"process-timeout": 0` in `composer.json`'s `config`, so
  `composer test:integration` is no longer stopped after 300 seconds.
- Measured: before, `composer test:integration` was stopped by Composer's
  300-second limit locally while Pest alone passed in 295 s; after,
  through Composer, 187 passed in 317 s. A WP-CLI call in the test
  container costs 0.2–0.3 s; that is where the time goes.
- Reasoned: CI runs the same command, so it would have hit the limit as
  the suite grew. Parallel runs are unsafe (one shared database and
  check queue).
- Decided by Maurice: 1 and 2; a faster harness is left for later.

## 2026-09-29 — SPEC-028 built (tests first)

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, SPEC-028 approved, bouw hem".
- Produced: `UploadHook::fingerprint()` on `wp_handle_upload` at
  `PHP_INT_MIN` (SHA-256 and size of a JPEG, PNG or WebP as it arrives),
  stored at `add_attachment` in `_tracefern_upload` with its path;
  `checkAndStore()` records `changed_after_upload` when the file at that
  path differs; `Display` shows "Changed after upload: this is not the
  file that was uploaded" next to the verdict, and one sentence in the
  details; a `--altered` badge style; the readme FAQ names the line;
  uninstall removes the key. Tests: `ChangedAfterUploadTest` (integration
  and unit), a readme test, and `recompressedPng()` in `tests/Pest.php`
  (the shared `keptPath()` and `sameAsHostFile()` moved there too).
  SPEC-028 status `implemented`; `NOTES.md` point 5 closed.
- Measured: `recompressedPng()` keeps the pixels and the manifest and
  gives the CLI's `assertion.dataHash.mismatch`. Red first: 8 of 9
  SPEC-028 tests failed before the code (AC5, which checks that nothing
  happens, passed). Green: the 9 tests; `composer check` (99 unit tests);
  the affected groups (SPEC-001, -002, -005, -008, -014, -018, -028,
  -029: 83 passed in 139 s); the full integration suite once before this
  commit (187 passed in 317 s).
- Decided by Maurice: the spec, its wording and no size limit.

## 2026-09-29 — Spec housekeeping

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, rond 1 en 2 af" (after the list of open findings).
- Produced: SPEC-024, SPEC-025 and SPEC-026 set to `implemented`; SPEC-029's
  open question on `--state=none` marked lapsed by its amendment 1.
- Measured: the three specs' Traceability tables are filled, and their
  work is committed (`b481283`/`6c72a54`, `7582abf`, `46e9f47`).
- Decided by Maurice: this housekeeping.

## 2026-09-29 — Prepare 0.1.4

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, bereid 0.1.4 voor".
- Produced: `Version: 0.1.4` in the plugin header, `Stable tag: 0.1.4`,
  and the 0.1.4 changelog entry (SPEC-029, with the advice to upload
  again rotated photos from the block editor; SPEC-028) in place of
  0.1.3's, which is in its GitHub release. `readme.txt` is 9,800 bytes.
- Measured: `composer check` green (99 unit tests). The suites on the
  build follow in the release record.
- Decided by Maurice: preparing the release. The SVN commit, push, tag
  and GitHub release are his, or wait for his go.

## 2026-09-29 — 0.1.4 released

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, zet SVN klaar na groene CI"; "gecommit, revisie";
  "akkoord, maak tag en release".
- Produced: after CI went green on `10d5f01`, a fresh SVN working copy
  with `trunk/` replaced by the build and `tags/0.1.4` copied; the
  annotated tag `v0.1.4` on `10d5f01`; the GitHub release 0.1.4 with the
  zip; the record in `notes/wporg-submission.md`.
- Measured: CI green on `10d5f01` (all nine jobs). SVN r3718293 by
  Maurice (07:35), found in the SVN log. An export of SVN `tags/0.1.4`,
  the public download and the build hold the same files (`diff -r`
  empty); the plugin API reported 0.1.4 by 07:36; the plugin page
  returns 200.
- Decided by Maurice: the SVN commit (done by him), the tag and the
  release.

## 2026-09-29 — Rotated large photo reproduced on a clean WordPress

- Model: Claude Opus 5.5, Claude Code CLI
- Asked: "akkoord, begin met stap 1" (reproduce the core behaviour found
  during SPEC-029 without this plugin, before any report to WordPress).
- Produced: a section in `notes/exif-rotation.md`. No plugin code. The
  release environment was restored (probe removed, plugins reactivated).
- Measured: in WordPress 7.1.2 with no plugins active, Chrome 153, the
  Truepic photo (EXIF 6, 4032×3024) was processed twice and never
  finalized, 2 of 2 runs: no image sizes and no `original_image` in its
  metadata, `wp_get_original_image_path()` returning `-scaled-2`, 16
  unreferenced files. Controls finalized correctly: a large photo without
  rotation, and a small rotated one.
- Reasoned: none beyond the note's wording.
- Decided by Maurice: to reproduce this. Whether and where to report it
  is his.
