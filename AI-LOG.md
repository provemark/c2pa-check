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
