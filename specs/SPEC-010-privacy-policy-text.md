# SPEC-010: Suggested text for the site's privacy policy

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

A site owner must tell visitors which personal data the site processes
(GDPR). The plugin stores, per image, the signer's name and its
certificate issuer as the file states them (SPEC-001); usually an
organisation, but it can be a person's name. WordPress gathers suggested
text from plugins in its Privacy Policy Guide (Settings → Privacy), so a
site owner does not have to find out what each plugin stores.

Read in core 7.1.2 (`wp-admin/includes/plugin.php`):
`wp_add_privacy_policy_content( $plugin_name, $policy_text )` must be
called from `admin_init` and only in the admin, or it calls
`_doing_it_wrong()` and adds nothing; elements with the class
`privacy-policy-tutorial` are guidance for the site owner and are left out
when the text is copied.

Decision this spec follows (Maurice, 2026-09-27): the suggested text only;
no personal-data exporter or eraser (the data comes from the image file
itself, which keeps it; erasing the plugin's copy would add little).

## Scope

**In scope**

- `PrivacyPolicy`, registering the suggested text on `admin_init`.
- The text (English, translatable):
  - Guidance (`privacy-policy-tutorial`): "Provemark C2PA Check reads the
    Content Credentials (C2PA) of uploaded images and stores the result
    with each image. It sends nothing to anyone."
  - Suggested text: "When an image is uploaded, this site checks the
    Content Credentials in the file and stores the result with the image:
    whether the credentials verify, the name of the signer and of its
    certificate's issuer as the file states them, the signing time, and
    whether the file says it was made by generative AI. The signer's name
    can be a person's name. This information is stored in this site's
    database, shown to users who can manage media, and not sent to anyone.
    It is deleted when the image is deleted, or when the plugin is
    removed."
- A test for each claim that is not already tested elsewhere.

**Out of scope** (each needs its own spec before it may be built)

- Personal-data exporters and erasers.
- Any change to what the plugin stores.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-010')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — the text is in the Privacy Policy Guide**
  - Given the plugin active and an admin request
  - When `admin_init` runs
  - Then `WP_Privacy_Policy_Content::get_suggested_policy_text()` has an
    entry named "Provemark C2PA Check" whose text contains the guidance
    (in a `privacy-policy-tutorial` element) and the suggested text

- **AC2 — nothing outside the admin** *(error path)*
  - Given a request that is not in the admin (WP-CLI, front end)
  - When the plugin loads
  - Then `wp_add_privacy_policy_content()` is not called and no
    `_doing_it_wrong` notice appears

- **AC3 — "deleted when the image is deleted" is true**
  - Given an uploaded, checked image with an entry and an index
  - When the image is deleted (`wp_delete_attachment( $id, true )`)
  - Then no `_provemark_c2pa_result`, `_provemark_c2pa_state` or
    `_provemark_c2pa_ai` row for it remains

- **AC4 — the other claims are covered**
  - "not sent to anyone": SPEC-001's no-network test; "when the plugin is
    removed": SPEC-005 AC1; "shown to users who can manage media": the
    Media Library and attachment screens need `upload_files` (WordPress's
    own capability for them; reasoned, not re-tested here)

## References

- WordPress: [Suggesting text for the site privacy policy](https://developer.wordpress.org/plugins/privacy/suggesting-text-for-the-site-privacy-policy/),
  `wp_add_privacy_policy_content()` (read in core 7.1.2),
  `WP_Privacy_Policy_Content`, `wp_delete_attachment()`.
- Oracle: `WP_Privacy_Policy_Content::get_suggested_policy_text()` and the
  database rows as WP-CLI reads them, in the test environment.

## API sketch

```php
namespace Provemark\C2paCheck;

final class PrivacyPolicy
{
    public function register(): void;   // add_action('admin_init', ...)
    public function addText(): void;     // wp_add_privacy_policy_content(...)
    public static function text(): string;
}
```

## Open questions

None. Resolved by Maurice on 2026-09-27: the wording as drafted.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
