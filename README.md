# Provemark C2PA Check

A WordPress plugin that verifies the Content Credentials (C2PA) of every
uploaded image with [provemark/c2pa-verifier](https://github.com/provemark/c2pa-verifier)
and shows the result in the Media Library. It only checks: it never signs,
never blocks an upload and makes no network calls.

Status: under construction; nothing works yet.

Requirements: WordPress 7.1 or later, PHP 8.3 or later with `ext-openssl`
and `ext-mbstring`.

This plugin is built with Claude Code; [`AI-LOG.md`](AI-LOG.md) records what
the assistant produced and what was decided by the maintainer.

## Licence

MIT, see [`LICENSE`](LICENSE).
