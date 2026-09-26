# M5.0: what a release zip would contain

Measured 2026-09-26. Input for the M5 specs.

## A build from the repository

`git archive HEAD` into a folder `provemark-c2pa-check/`, then
`composer install --no-dev --optimize-autoloader` there:

- `vendor/` is 4.0 MB: `provemark/c2pa-verifier` v0.2.3 as Packagist
  delivers it, plus Composer's autoloader. The verifier's own
  `.gitattributes` keeps its 63 MB of fixtures out, and ships, on purpose,
  `src/` (680 KB), `bin/` (564 KB), `docs/` (196 KB), `notes/` (1.0 MB),
  `specs/` (940 KB), `AI-LOG.md` (421 KB), `NOTES.md` (99 KB), `README.md`,
  `CHANGELOG.md`, `LICENSE` and more. At run time the plugin needs only
  `src/`, Composer's autoloader and, for the MIT licence, `LICENSE`.
- The repository's tracked files include what never ships: `tests/` (31
  files), `specs/`, `notes/`, `.github/`, `AI-LOG.md`, `NOTES.md`,
  `package.json`, `package-lock.json`, `composer.lock`, `.wp-env.json`,
  `phpstan.neon`, `phpunit.xml`, `pint.json`, `.gitignore`. There is no
  `.gitattributes` yet.

## Plugin Check and vendor/

`wp help plugin check` (Plugin Check 2.1.0): `<plugin>` is a plugin name
(no path or zip); `vendor`, `vendor_prefixed`, `vendor-prefixed`,
`node_modules` and `.git` are always excluded from file-based scans, and no
option turns that off; `--slug=<slug>` sets the slug to check against. So a
build can be checked by mapping it into wp-env under another folder name
and passing `--slug=provemark-c2pa-check`, but the bundled verifier is never
scanned by Plugin Check (the probe of 2026-09-26 in `NOTES.md` agrees).

## Open (for Maurice)

- Whether the zip carries the verifier as Packagist delivers it (4.0 MB)
  or only what runs (`src/`, `LICENSE`, autoloader).
- How the shipped verifier code is checked, given that Plugin Check never
  looks at `vendor/`.
