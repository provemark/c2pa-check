# SPEC-002: Show the result in the Media Library

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

SPEC-001 stores a verdict per image, but nobody sees it. A site owner needs
to see, where they already work with media, whether an image carries
Content Credentials and what the verifier said about them, in plain words.

Everything shown that came from the file (signer, signing time, codes, a
manifest URL) is untrusted text: a manifest can hold `<script>`, quotes,
control characters or Unicode direction controls that make a name read
differently on screen. `notes/m2-where-to-show.md` measured that one
filter, `attachment_fields_to_edit`, puts a row in the Edit Media screen,
the Media Library's attachment details modal and the block editor's media
modal, and that WordPress inserts that row's `html` and `label` **without
escaping**; the list-mode column comes from `manage_media_columns` and
`manage_media_custom_column`.

The plugin shows only what SPEC-001 stored: it never turns an entry into a
verdict the verifier did not give (fail closed).

## Scope

**In scope**

- A column "Content Credentials" in the Media Library list mode.
- A row "Content Credentials" in the attachment details, through
  `attachment_fields_to_edit` (Edit Media and both modals).
- Wording per state, translatable (text domain `provemark-c2pa-check`).
- Escaping of everything, and neutralising control and direction
  characters in text from the file before escaping.
- Typed class constants in `UploadHook` (`public const string ...`), which
  PHP 8.3 allows; PhpStorm flags their absence.

**Out of scope** (each needs its own spec before it may be built)

- The AI label (SPEC-003).
- `Trusted` in practice (SPEC-004); the wording for it is defined here.
- Filtering or sorting by the column; a front-end badge; REST fields.
- Re-checking an attachment from the screen.
- Making the manifest URL a link. It is shown as text only.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-002')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

Wording (English source strings; the column shows the headline, the details
show the headline and the lines below it):

| stored | headline | details lines |
|---|---|---|
| `Trusted` | Verified: trusted signer | Signed by {common_name} ({issuer}); Signed at {signed_at}, when present |
| `Valid` | Intact: signer not trusted | the same lines |
| `Invalid` | Does not verify | Codes: {codes, comma-separated}; signer lines when present |
| `none` | No Content Credentials | with a URL: Refers to Content Credentials elsewhere (not checked): {url} |
| `error` | Could not be checked | Reason: {reason in words: interrupted → "the check did not finish", unreadable → "the file could not be read", exception → "the verifier failed"} |
| no entry | Not checked | — |
| unreadable entry | Result unreadable | — |

Every details view ends with "Checked {checked_at} with c2pa-verifier
{verifier}" when the entry has them. Every value in `{}` from the entry is
passed through `Display::text()`: C0 and C1 control characters and the
Unicode direction controls (U+200E, U+200F, U+202A–U+202E, U+2066–U+2069)
are each replaced by U+FFFD, and the result is escaped with `esc_html`.
Attribute values use `esc_attr`. Nothing from an entry is ever output
unescaped, and no URL from an entry becomes an `href`.

- **AC1 — column, one headline per state**
  - Given attachments whose stored entries are `Trusted`, `Valid`,
    `Invalid`, `none`, `error`, and one without an entry
  - When the Media Library list mode renders the column
  - Then each cell shows its headline from the table, and nothing else
    that reads as a verdict

- **AC2 — details for a signed file**
  - Given an attachment with a `Valid` entry holding a signer and a
    `checked_at` and `verifier`
  - When its attachment details are rendered (Edit Media, and the modal)
  - Then they show the headline, "Signed by {common_name} ({issuer})", and
    the "Checked … with c2pa-verifier …" line

- **AC3 — details for an invalid file**
  - Given an `Invalid` entry with codes `signingCredential.untrusted` and
    `assertion.dataHash.mismatch`
  - When its details are rendered
  - Then both codes are shown, in the stored order

- **AC4 — manifest by URL: text, never a link**
  - Given a `none` entry with a `remote_manifest_url`
  - When its details are rendered
  - Then the URL appears as escaped text after "Refers to Content
    Credentials elsewhere (not checked):", and the output contains no `<a`
    and no `href`

- **AC5 — error in words**
  - Given `error` entries with each of the three reasons
  - When their details are rendered
  - Then each shows "Could not be checked" and its reason in words

- **AC6 — hostile text is escaped** *(error path)*
  - Given a `Valid` entry whose `common_name`, `issuer` and `signed_at`, an
    `Invalid` entry whose codes, and a `none` entry whose URL each hold
    `<script>alert(1)</script>`, `"><img src=x onerror=alert(1)>` and `'`
  - When the column and the details are rendered
  - Then the output contains none of `<script`, `<img`, `onerror=` as
    markup; the text appears escaped (`&lt;script&gt;`)

- **AC7 — control and direction characters are made visible** *(error path)*
  - Given a signer name holding `\x00`, `\x1B`, `\u{85}` and `\u{202E}`
  - When the details are rendered
  - Then each of those characters is replaced by U+FFFD and none of them
    remains in the output

- **AC8 — an entry that is not a SPEC-001 entry shows no verdict** *(error path)*
  - Given stored values that are a string, an array without `state`, an
    array with state `Maybe`, and an array with `schema` 2
  - When the column and the details are rendered
  - Then each shows "Result unreadable" and none of the state headlines

- **AC9 — end to end**
  - Given `fixture-signed.jpg` uploaded with the plugin active
  - When the Media Library list mode is rendered
  - Then its cell shows "Intact: signer not trusted", and its details show
    "Signed by C2PA Signer (C2PA Test Signing Cert)"

## References

- Specification: C2PA Technical Specification 2.4, §15 (status codes, shown
  verbatim); WordPress developer reference:
  [`attachment_fields_to_edit`](https://developer.wordpress.org/reference/hooks/attachment_fields_to_edit/),
  [`manage_media_columns`](https://developer.wordpress.org/reference/hooks/manage_media_columns/),
  [`manage_media_custom_column`](https://developer.wordpress.org/reference/hooks/manage_media_custom_column/),
  [`esc_html()`](https://developer.wordpress.org/reference/functions/esc_html/);
  Unicode UAX #9 for the direction controls.
- Oracle: SPEC-001's stored entries (set directly with `update_post_meta` in
  the tests, so hostile values need no signed fixture and no key); for AC9 a
  real upload, and the verifier CLI through SPEC-001's own tests. Measured
  on WordPress 7.1.2, PHP 8.3 / 8.4 / 8.5 in wp-env.
- Reasoned: that the block editor's own inline media inserter (not
  `wp.media`) does not show compat fields; not measured, and not promised.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Provemark\C2paCheck;

// Entry in, safe HTML out; the only place wording and escaping live.
final class Display
{
    /** @param mixed $entry what get_post_meta returned */
    public static function headline(mixed $entry): string;   // escaped HTML
    public static function details(mixed $entry): string;    // escaped HTML
    public static function text(string $untrusted): string;  // controls → U+FFFD, then esc_html
}

// WordPress glue: the column and the attachment-details row.
final class MediaScreens
{
    public function register(): void;
}
```

## Open questions

- **Codes for `Valid` (non-blocker).** A `Valid` file always carries
  `signingCredential.untrusted`; SPEC-001 does not store codes for `Valid`.
  Proposal: show none; the headline says "signer not trusted".
- **Signing time format (non-blocker).** `signed_at` is the file's own
  string. Proposal: show it as is (escaped), not reformat it with
  `wp_date()`, so that the plugin never interprets untrusted input.
- **Wording (non-blocker).** The headlines above are a proposal; Maurice
  may change them before approval.

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
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
