# SPEC-026: Other plugins can read the verdict

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

Plugins that label AI images, and WordPress's own C2PA Monitor experiment
(WordPress/ai#459), decide what to show from what a file says about
itself: whether a C2PA manifest is present, or what it claims. None of
them checks the claim (SPEC-025, Problem). This plugin does, and stores
the verdict, but only its own screens can read it: the stored entry
(`_tracefern_result`, SPEC-001) is internal, and reading it correctly
means repeating the rules of SPEC-003 (the AI label only on `Trusted` or
`Valid`), SPEC-013 (pending) and SPEC-014 (changed since its check).

Another plugin that reads the post meta directly would get those rules
wrong, and would break when the entry's shape changes. One documented,
stable way to ask "what did Tracefern conclude about this image?" lets
such a plugin show a label a site owner can rely on, without depending
on the plugin being installed.

WordPress's usual way to offer this without a hard dependency is a
filter: the caller passes a default, and gets it back unchanged when the
plugin is not active
([Plugin API: `apply_filters()`](https://developer.wordpress.org/reference/functions/apply_filters/)).

## Scope

**In scope**

- A filter, `tracefern_verdict`, called as
  `apply_filters( 'tracefern_verdict', null, $attachment_id )`. For an
  attachment it returns an array (Behavior); for anything else it returns
  the default unchanged.
- The array is built from the stored entry by the same code the Media
  Library column uses, so the two can never disagree.
- One FAQ entry in `readme.txt`, "Can other plugins use the verdict?",
  with the call and the meaning of each field.

**Out of scope** (each needs its own spec before it may be built)

- An action when a check finishes (`tracefern_checked`), for plugins that
  want to react instead of ask.
- A REST field (out of scope for this plugin, per its brief).
- Anything on the front end.
- Writing: other plugins cannot set or change a verdict.
- Any code for a specific other plugin, including the C2PA Monitor.

## Behavior

The array (all keys always present):

| key | value |
|---|---|
| `schema` | `1`; raised only when a key changes meaning or disappears |
| `status` | `checked`, `pending` ("Check pending"), `not_checked`, `changed` ("Changed since its check"), `unreadable` (stored value not a valid entry) |
| `state` | `Trusted`, `Valid`, `Invalid`, `none`, `error`; `null` unless `status` is `checked` |
| `intact` | `true` when the signature and the hash hold (`Trusted` or `Valid`) |
| `trusted` | `true` only for `Trusted` |
| `ai` | `true` exactly when the Media Library shows "AI-generated (signed)" |
| `signer` | `['issuer' => ?string, 'common_name' => ?string]` or `null` |
| `signed_at`, `checked_at` | ISO 8601 strings or `null` |
| `codes` | list of C2PA status codes (`Invalid` only), else `[]` |
| `reason` | for `error`: `interrupted`, `unreadable`, `exception`, `unsupported`; else `null` |
| `verifier`, `trust` | the verifier version and trust settings used, or `null` |

Text from the file (`signer`) is returned as stored (at most 256
characters, SPEC-015), with control and direction characters replaced by
U+FFFD as on screen, and **not HTML-escaped**: the caller escapes it for
where it puts it. The FAQ says so.

- **AC1 — A trusted AI image**
  - Given the OpenAI fixture, checked with the default settings
  - When `apply_filters('tracefern_verdict', null, $id)` is called
  - Then `status` is `checked`, `state` `Trusted`, `intact`, `trusted`
    and `ai` are `true`, `signer` and `signed_at` equal the stored entry,
    and `codes` is `[]`.

- **AC2 — Intact, signer not trusted**
  - Given `fixture-signed.jpg` (default settings: `Valid`)
  - When the filter is called
  - Then `state` is `Valid`, `intact` is `true`, `trusted` is `false`.

- **AC3 — A changed AI image gets no AI flag**
  - Given the tampered OpenAI PNG, whose stored entry has `ai` true and
    state `Invalid`
  - When the filter is called
  - Then `state` is `Invalid`, `intact`, `trusted` and `ai` are `false`,
    and `codes` equals the stored codes.

- **AC4 — Not checked, pending, no credentials**
  - Given an image uploaded before activation (no entry, no marker), one
    whose check is pending (SPEC-013), and `fixture-unsigned.jpg`
  - When the filter is called for each
  - Then `status` is `not_checked`, `pending` and `checked` (with `state`
    `none`) respectively; the first two have `state` `null` and every
    flag `false`.

- **AC5 — Changed since its check**
  - Given a checked image whose original file changed afterwards
    (SPEC-014)
  - When the filter is called
  - Then `status` is `changed`, `state` and `signer` are `null`, and
    every flag is `false`: no verdict on bytes that are no longer there.

- **AC6 — Not an attachment, or a broken entry** *(error path)*
  - Given (a) an ID that is not an attachment, a non-integer, and `0`;
    (b) an attachment whose stored value is not a SPEC-001 entry (a
    string, or an array with a wrong type); (c) an entry whose signer
    holds control and direction characters
  - When the filter is called
  - Then (a) returns the default unchanged (`null`, or whatever default
    was passed); (b) returns `status` `unreadable`, `state` `null`, every
    flag `false`, without a PHP warning; (c) returns the signer with
    those characters replaced by U+FFFD. Nothing throws.

- **AC7 — Always the same answer as the column**
  - Given every fixture of SPEC-024 and the entries of AC4–AC6
  - When the filter's `status`/`state`/`ai` are compared with what the
    Media Library column shows for the same attachment
  - Then they agree: the same state, "AI-generated (signed)" exactly when
    `ai` is `true`, "Check pending" exactly when `status` is `pending`,
    "Changed since its check" exactly when `status` is `changed`.

- **AC8 — Documented**
  - Given `readme.txt`
  - When the FAQ is read
  - Then it has "Can other plugins use the verdict?" with the
    `apply_filters( 'tracefern_verdict', null, $attachment_id )` call,
    says that `null` comes back when the plugin is not active, and that
    `signer` must be escaped by the caller; `readme.txt` stays under
    10,240 bytes.

## References

- WordPress: [`apply_filters()`](https://developer.wordpress.org/reference/functions/apply_filters/),
  [Plugin API, Filters](https://developer.wordpress.org/plugins/hooks/filters/).
- This plugin: SPEC-001 (entry), SPEC-003 (AI label), SPEC-013 (pending),
  SPEC-014 (changed), SPEC-015 (bounded text); `Display::classify()`,
  `Display::changed()`, `MediaScreens::pendingSince()`.
- Oracle: the Media Library column for the same attachment (AC7), and
  through it the verifier CLI as in SPEC-001.
- Reasoned: that a filter with a `null` default is what other plugins
  can call without a dependency; that the C2PA Monitor (not yet
  released, no hooks of its own, read 2026-09-28) could use it.

## API sketch

Illustrative only.

```php
// In the main file, with the other hooks:
add_filter('tracefern_verdict', [Verdict::class, 'filter'], 10, 2);

namespace Tracefern\ImageCheck;

final class Verdict
{
    public const int SCHEMA = 1;

    /** The default unchanged unless $attachmentId is an attachment. */
    public static function filter(mixed $default, mixed $attachmentId): mixed;

    /** @return array{schema: int, status: string, state: ?string, intact: bool, trusted: bool, ai: bool, ...} */
    public static function forAttachment(int $attachmentId): array;
}
```

A caller, with no dependency on this plugin:

```php
$verdict = apply_filters( 'tracefern_verdict', null, $attachment_id );
if ( is_array( $verdict ) && $verdict['ai'] ) {
    // Show the AI label: the claim was verified.
}
```

## Open questions

- **Release**: this is code, so it ships as a new version, proposed
  0.2.0 (a new feature), with a changelog line. Non-blocker for building.

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
