# SPEC-024: A Live Preview in the plugin directory

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Few images carry Content Credentials yet. A visitor of the plugin's page on
wordpress.org who wants to see what the plugin does needs a signed image,
an AI image and a tampered one, and a WordPress to try them in. Most will
not have those at hand, so they read the screenshots and move on.

The plugin directory can offer a "Preview" button beside "Download" that
opens WordPress Playground, a WordPress running in the visitor's browser,
set up by a blueprint file the plugin provides
([Previews and Blueprints](https://developer.wordpress.org/plugins/wordpress-org/previews-and-blueprints/)):

- the blueprint is committed to SVN as `assets/blueprints/blueprint.json`;
- with a valid blueprint, committers see a "Test Preview" button; the
  button is shown to everyone only after a committer sets the preview to
  "public" in the plugin's Advanced view;
- the blueprint installs the plugin itself (the handbook's example installs
  the plugin with an `installPlugin` step from `wordpress.org/plugins`).

With a blueprint that imports a handful of known images, the preview opens
on a Media Library in which every verdict the plugin can give is already
visible.

## Scope

**In scope**

- `.wordpress-org/blueprints/blueprint.json`, kept in git beside the other
  directory assets (already `export-ignore`, so not in the zip) and
  committed to SVN as `assets/blueprints/blueprint.json`.
- The blueprint: PHP 8.3 and the latest WordPress; log in; install and
  activate the plugin from the directory; fetch four fixtures and make one
  altered copy; import the five; run the due cron events so every image is
  checked before the preview opens; open the Media Library in list view.
- The images, fetched from this repository's `tests/Fixtures/` **at tag
  `v0.1.0`**, so a later change on `main` cannot break the preview:

  | image | licence | verdict with the default settings |
  |---|---|---|
  | `google-20250919-pixel10-npld-picnic-table.jpg` | public domain | `Trusted`, Pixel Camera |
  | `openai-20260826-c2pa_2x.png` | MIT | `Trusted`, AI label |
  | `amazon-20240925-titan-g1.png` | Apache-2.0 | `Valid` (expired signer, DigiCert timestamp), AI label |
  | a copy of the OpenAI PNG, one byte of image data changed | MIT | `Invalid`, `assertion.dataHash.mismatch`, no AI label |
  | `fixture-unsigned.jpg` | MIT | `none` |

  The altered copy is made in the blueprint the same way as
  `tamperedOpenAiPng()` in `tests/Pest.php`. It shows what the plugin is
  for: the same AI image, once intact and once changed, and the label gone
  from the changed one.
- Each attachment's caption names its source and licence, so the preview
  credits the images as the fixtures README does.
- A Release test that runs the blueprint in Playground CLI, with the
  plugin taken from the local build zip instead of the directory, and
  compares each stored result with the verifier CLI's (the oracle).
- The SVN checklist in `notes/wporg-submission.md`: the blueprint goes to
  `assets/blueprints/`; "Test Preview" first, then public.

**Out of scope** (each needs its own spec before it may be built)

- Any change to the plugin's code or to the shipped zip.
- A second blueprint (the directory supports one, per the handbook).
- Demo content outside the Media Library (posts, pages, a theme).
- `tests/Fixtures/README.md` still says Amazon Titan is `Invalid`; since
  SPEC-004 amendment 1 it is `Valid` with DigiCert on (the default), as
  `tests/Integration/TrustTest.php` AC3 checks. A separate one-line fix.

## Behavior

- **AC1 — The blueprint is well formed and pinned**
  - Given `.wordpress-org/blueprints/blueprint.json`
  - When a Unit test reads it
  - Then it is valid JSON; `preferredVersions.php` equals `Requires PHP`
    in the plugin header; the plugin is installed from
    `wordpress.org/plugins` with the slug `tracefern-image-check-for-c2pa`
    and activated; every `url` resource is
    `https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/<file>`
    for a `<file>` that exists at tag `v0.1.0` (`git cat-file -e`); the
    landing page is `/wp-admin/upload.php?mode=list`.

- **AC2 — Every image shows the verifier's verdict**
  - Given the blueprint with the plugin replaced by the local build zip
  - When Playground CLI (version pinned in `package.json`) runs it
  - Then each of the five attachments has a stored result whose state,
    AI flag and status codes equal the verifier CLI's for the same file
    with the default settings (`expectedEntry()`), and the table in Scope
    holds.

- **AC3 — The AI label only where it may be shown**
  - Given the run of AC2
  - When the Media Library column is rendered for each attachment
  - Then "AI-generated (signed)" appears for the OpenAI and Amazon images
    and not for the altered copy, whose stored `ai` is true but whose
    state is `Invalid`.

- **AC4 — Captions credit the images**
  - Given the run of AC2
  - When each attachment's caption is read
  - Then it names the file's source and licence as in
    `tests/Fixtures/README.md`; for the altered copy, that it is altered.

- **AC5 — A missing image fails the run, not the preview silently**
  *(error path)*
  - Given the blueprint with one image URL pointing at a file that does
    not exist
  - When Playground CLI runs it
  - Then the run stops with an error at that `writeFile` step and the
    Release test fails, naming the step; no preview is published with
    fewer images than the table.

## References

- WordPress: [Previews and Blueprints](https://developer.wordpress.org/plugins/wordpress-org/previews-and-blueprints/)
  (read 2026-09-28); [Blueprint steps](https://wordpress.github.io/wordpress-playground/blueprints/steps).
- Oracle: provemark/c2pa-verifier v0.2.5 (bundled), `expectedEntry()` in
  `tests/Pest.php` with the default settings; the fixtures at tag
  `v0.1.0`.
- **Measured 2026-09-28**, Playground CLI (latest, `run-blueprint`) with a
  draft of this blueprint installing 0.1.0 from the directory: PHP 8.3.33,
  WordPress 7.1.2, `openssl` and `mbstring` loaded without an extension
  bundle; directly after `wp media import` no result is stored and a
  `tracefern_check` event is scheduled; after `wp cron event run
  --due-now` the five results match the table above; the whole run took
  12.6 s. The four raw.githubusercontent.com URLs answer 200 with
  `Access-Control-Allow-Origin: *`, which a browser needs to fetch them
  from Playground; the Amazon file's SHA-256 there equals the local one.
- **Reasoned, not measured**: that the browser Playground behind the
  directory's button behaves like the CLI; how long it takes to open there
  (about 12 MB of images, the Pixel photo 5.6 MB); whether WP-Cron runs in
  the browser Playground for an image a visitor uploads themselves. All
  three are measured with "Test Preview" before the preview goes public.

## API sketch

Illustrative; the tamper step is the one from `tests/Pest.php`.

```json
{
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "landingPage": "/wp-admin/upload.php?mode=list",
  "preferredVersions": { "php": "8.3", "wp": "latest" },
  "steps": [
    { "step": "login", "username": "admin", "password": "password" },
    { "step": "installPlugin",
      "pluginData": { "resource": "wordpress.org/plugins", "slug": "tracefern-image-check-for-c2pa" },
      "options": { "activate": true } },
    { "step": "mkdir", "path": "/tmp/demo" },
    { "step": "writeFile", "path": "/tmp/demo/openai-20260826-c2pa_2x.png",
      "data": { "resource": "url", "url": "https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/openai-20260826-c2pa_2x.png" } },
    { "step": "runPHP", "code": "<?php /* altered copy, captions */" },
    { "step": "wp-cli", "command": "wp media import /tmp/demo/… --caption=…" },
    { "step": "wp-cli", "command": "wp cron event run --due-now" }
  ]
}
```

## Open questions

- If WP-Cron does not run for a visitor's own upload in the browser
  Playground, that image stays unchecked in the preview. The five demo
  images are unaffected (checked during setup). Non-blocker: found with
  "Test Preview"; whether and how to handle it is then a new decision.
- Whether to make the preview public is Maurice's, after "Test Preview".

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
