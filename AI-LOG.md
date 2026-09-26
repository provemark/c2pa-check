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
