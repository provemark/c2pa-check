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

## WordPress Coding Standards on the shipped verifier

PHPCS 3.13.6 with WPCS 3 (in a scratch project), sniffs
`WordPress.Security.EscapeOutput`, `.ValidatedSanitizedInput`,
`.NonceVerification`, `WordPress.WP.AlternativeFunctions`,
`WordPress.PHP.DiscouragedPHPFunctions`, `.DevelopmentFunctions`,
`WordPress.DB.RestrictedFunctions`, `WordPress.WP.DiscouragedFunctions`,
on `vendor/provemark/c2pa-verifier/src` (v0.2.3): 641 findings in 10
sources.

| sniff | count | what it is (reasoned from reading the lines) |
|---|---|---|
| `Security.EscapeOutput.ExceptionNotEscaped` | 608 | exception messages with an interpolated value; the plugin never outputs a verifier exception message except `TrustException`'s on the settings page, through `esc_html` |
| `WP.AlternativeFunctions.file_system_operations_*` | 17 | `fopen` / `fread` / `fwrite` / `fclose` on streams, the verifier's input |
| `PHP.DevelopmentFunctions.error_log_set_error_handler` | 6 | catching OpenSSL warnings around its calls |
| `PHP.DiscouragedPHPFunctions.obfuscation_base64_*` | 8 | PEM and DER certificate encoding |
| `WP.AlternativeFunctions.json_encode_json_encode` | 2 | not WordPress code |
| `WP.AlternativeFunctions.file_get_contents_file_get_contents` | 1 | a local file |

None of them points at a hole in how the plugin uses the verifier
(reasoned); zero findings is not a reachable bar for a library written
without WordPress.

## Decisions

Decided by Maurice (2026-09-26): the zip carries only what runs
(`src/`, `LICENSE`, the autoloader); the shipped verifier is checked with
the WPCS security sniffs directly, and findings go to him, and to the
verifier as issues when they are real.
