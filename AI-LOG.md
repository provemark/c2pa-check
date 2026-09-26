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
