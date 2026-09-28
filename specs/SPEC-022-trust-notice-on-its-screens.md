# SPEC-022: The trust notice only on the screens it concerns

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-28                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

wordpress.org's first review (2026-09-28) asked to check guideline 11:
"Plugins should not hijack the admin dashboard. Upgrade prompts, notices,
alerts, and the like must be limited in scope and used with moderation."

The plugin has three `admin_notices`. Two appear only when the plugin
cannot run at all (PHP older than 8.3, bundled libraries missing), for
users who can activate plugins; they stay. The third, "the last image was
checked without trust settings" (SPEC-004 AC6), appears on every admin
screen, the dashboard included, as long as the flag is set.

## Scope

**In scope**

- The trust notice only on: the Media Library (`upload`), Add New Media
  File (`media`), an attachment's edit screen (`attachment`) and the
  plugin's settings page (`settings_page_tracefern-image-check-for-c2pa`).
- SPEC-004 AC6's test shows the notice on the Media Library screen.

**Out of scope**

- The two "cannot run" notices.
- Dismissing: the notice goes away by itself once a check succeeds; a
  dismissed notice could hide a real fault.
- The wording.

## Acceptance criteria

- **AC1** With the flag set, an administrator sees the notice on each of
  the four screens above.
- **AC2** With the flag set, the notice is not on the dashboard, the posts
  list or the plugins screen.

## Traceability

- AC1, AC2: `tests/Integration/TrustNoticeTest.php`
