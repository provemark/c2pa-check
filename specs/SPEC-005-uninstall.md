# SPEC-005: Clean up on uninstall

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The plugin stores one post-meta entry per checked image (SPEC-001) and
three options (SPEC-004: `provemark_c2pa_digicert`,
`provemark_c2pa_custom_trust`, `provemark_c2pa_trust_failed`). A site owner
who deletes the plugin expects it to leave nothing behind; WordPress's
plugin handbook asks plugins to remove their data on uninstall
([Uninstall Methods](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/)).
Deactivating is not uninstalling: a deactivated plugin keeps its data, so
that reactivating it shows the same results.

## Scope

**In scope**

- An `uninstall.php` in the plugin root, guarded by `WP_UNINSTALL_PLUGIN`,
  that deletes every `_provemark_c2pa_result` post-meta entry and the three
  options.
- Nothing on deactivation.

**Out of scope** (each needs its own spec before it may be built)

- Multisite: cleaning every site of a network (the plugin is not tested on
  multisite).
- Touching attachments or files: the images stay, only the plugin's data
  goes.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-005')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — uninstall removes the plugin's data, and only that**
  - Given uploaded images with entries, the three options set, and an
    unrelated post-meta key and option
  - When the plugin is uninstalled (`uninstall.php` runs as WordPress runs
    it)
  - Then no `_provemark_c2pa_result` entry and none of the three options
    remain; the attachments, their files and the unrelated key and option
    are untouched

- **AC2 — deactivation keeps everything**
  - Given the same state
  - When the plugin is deactivated and activated again
  - Then every entry and option is still there, and the column shows the
    same headlines

- **AC3 — the file does nothing when loaded directly** *(error path)*
  - Given `uninstall.php` loaded without `WP_UNINSTALL_PLUGIN` defined
  - When it runs
  - Then it exits before deleting anything

## References

- WordPress: [Uninstall Methods](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/),
  [`delete_post_meta_by_key()`](https://developer.wordpress.org/reference/functions/delete_post_meta_by_key/),
  [`delete_option()`](https://developer.wordpress.org/reference/functions/delete_option/).
- Oracle: the database as WP-CLI reads it (`wp post meta list`,
  `wp option get`) before and after, on WordPress 7.1.2 in wp-env.
- Measured instead of reasoned: the tests call `uninstall_plugin()`, which
  WordPress runs before deleting a plugin's files (read in
  `wp-admin/includes/plugin.php`: it defines `WP_UNINSTALL_PLUGIN`,
  includes `uninstall.php`, and deletes no files). `wp plugin uninstall`
  is not used: without `--skip-delete` it removes the plugin folder, which
  in wp-env is the working tree.

## API sketch

```php
// uninstall.php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_post_meta_by_key('_provemark_c2pa_result');
delete_option('provemark_c2pa_digicert');
delete_option('provemark_c2pa_custom_trust');
delete_option('provemark_c2pa_trust_failed');
```

## Open questions

- None.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/UninstallTest.php` :: AC1 | `uninstall.php` |
| AC2 | `tests/Integration/UninstallTest.php` :: AC2 | (no deactivation hook) |
| AC3 | `tests/Unit/UninstallFileTest.php` :: AC3 | `uninstall.php` (`WP_UNINSTALL_PLUGIN` guard) |
