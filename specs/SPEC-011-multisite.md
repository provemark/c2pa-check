# SPEC-011: Multisite

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The readme says the plugin "has not been tested on multisite". Measured on
2026-09-27 in a multisite wp-env (WordPress 7.1.2, PHP 8.3,
`.wp-env.multisite.json`, port 8894), with the plugin network-activated
and a second site:

- An upload on the main site and one on the subsite were each checked and
  stored on their own site (`Valid` and `Trusted`), with their index.
- `wp provemark-c2pa check <id> --url=<subsite>` worked on the subsite.
- Options are per site: the DigiCert option set on each site separately.
- **Uninstall cleaned only the main site.** After `uninstall_plugin()`,
  the main site had 0 entries and no options; the subsite kept its 3
  plugin meta rows and its DigiCert option. `uninstall.php` runs once, in
  the main site's context.

Decision this spec follows (Maurice, 2026-09-27): support multisite.

## Scope

**In scope**

- `uninstall.php` on multisite: for every site of the network, delete the
  plugin's meta keys and options, switching to each site in turn; on a
  single site, unchanged.
- A multisite test environment (`.wp-env.multisite.json`, `npm run
  multisite:start`) and a `Multisite` test suite (`composer
  test:multisite`), and a CI job for it on PHP 8.3.
- The readme: "Works on multisite; each site has its own settings and
  results", replacing "not tested".

**Out of scope** (each needs its own spec before it may be built)

- Network-wide settings (one trust setting for all sites).
- A network admin screen.
- `wp provemark-c2pa check` across all sites at once (use `--url` per site,
  or `wp site list` with a loop).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-011')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

(For this spec: in `tests/Multisite`, against the multisite environment.)

- **AC1 — each site checks and stores its own uploads**
  - Given the plugin network-activated and two sites
  - When a signed image is uploaded on each
  - Then each site has its own entry and index for its own attachment ID,
    equal to the CLI with the default settings

- **AC2 — settings are per site**
  - Given custom trust settings on the subsite only
  - When `fixture-signed.jpg` is uploaded on both sites
  - Then it is `Trusted` (`trust` `custom`) on the subsite and `Valid` on
    the main site

- **AC3 — uninstall cleans every site** *(error path: the measured gap)*
  - Given entries, index keys and options on both sites, and an unrelated
    option and post meta on the subsite
  - When the plugin is uninstalled
  - Then no plugin meta and no plugin option remains on any site; the
    unrelated ones and the attachments remain

- **AC4 — a site created after activation works**
  - Given the plugin network-activated
  - When a new site is created and an image uploaded on it
  - Then it is checked and stored there

- **AC5 — the command works per site**
  - Given an image on the subsite
  - When `wp provemark-c2pa check <id> --url=<subsite>` runs
  - Then that site's entry is refreshed and the main site is untouched

## References

- WordPress: [Multisite](https://developer.wordpress.org/advanced-administration/multisite/),
  `switch_to_blog()`, `get_sites()`, `uninstall_plugin()` (read in core
  7.1.2); wp-env's `multisite` option.
- Oracle: the database rows per site as WP-CLI reads them, and the verifier
  CLI.

## API sketch

```php
// uninstall.php
$sites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
foreach ($sites as $site) {
    switch_to_blog($site);   // multisite only
    // delete the three meta keys and four options, as now
    restore_current_blog();
}
```

## Open questions

None. Resolved by Maurice on 2026-09-27, as proposed: uninstall visits
every site in one request; the readme says so.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Multisite/MultisiteTest.php` :: AC1 | `UploadHook` (per site, unchanged) |
| AC2 | `tests/Multisite/MultisiteTest.php` :: AC2 | `SettingsPage::trustConfig` (options per site, unchanged) |
| AC3 | `tests/Multisite/MultisiteTest.php` :: AC3 | `uninstall.php` (every site via `get_sites()` / `switch_to_blog()`) |
| AC4 | `tests/Multisite/MultisiteTest.php` :: AC4 | network activation (unchanged) |
| AC5 | `tests/Multisite/MultisiteTest.php` :: AC5 | `RecheckCommand` with `--url` (unchanged) |
