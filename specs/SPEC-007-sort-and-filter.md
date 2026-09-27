# SPEC-007: Sort and filter the Media Library by Content Credentials

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-26                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A site owner with many images wants to see at once which ones do not
verify, which ones are AI-generated, or which ones were never checked.
The column of SPEC-002 shows each image's verdict but cannot be sorted or
filtered: SPEC-002 left that out.

`notes/m6-sort-filter.md` measured what it takes. The verdict is stored as
one serialized array (`_provemark_c2pa_result`), which MySQL cannot sort
or filter on, so a small index key is needed. WordPress offers
`manage_upload_sortable_columns`, `restrict_manage_posts` and
`pre_get_posts` for the list mode. Writing one meta value costs 0.23 ms per
image, and a sorted page of 20 takes 3 ms.

Decisions this spec follows (Maurice, 2026-09-26): list mode only; images
checked before this spec get their index automatically, in batches on admin
requests; the sort order runs from good to nothing.

## Scope

**In scope**

- Two index keys per checked image, written with every stored entry
  (SPEC-001) and derived only from it: `_provemark_c2pa_state` (`Trusted`,
  `Valid`, `Invalid`, `error`, `none`, or `unreadable` for an entry that is
  not a SPEC-001 entry) and `_provemark_c2pa_ai` (`1` only when SPEC-003
  shows the label, else absent).
- The column sortable, in this order ascending: Verified (`Trusted`),
  Intact (`Valid`), Does not verify (`Invalid`), Could not be checked
  (`error`), Result unreadable (`unreadable`), No Content Credentials
  (`none`), Not checked (no index key); descending reverses it. Within one
  state, newest first.
- A select above the list, "All Content Credentials" plus: Verified,
  Intact, Does not verify, AI-generated (signed), Could not be checked, No
  Content Credentials, Not checked.
- A backfill: on admin requests by a user who can upload files, up to 500
  entries without an index are indexed per request, lowest ID first, until
  none are left; then an option records that it is done and it never runs
  again.
- SPEC-005 amendment 1: uninstall also removes both index keys and the
  backfill option.

**Out of scope** (each needs its own spec before it may be built)

- Grid mode and the media modals (they filter over AJAX and need
  JavaScript).
- Sorting or filtering on the signer, the codes or the trust list.
- Re-checking images.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-007')`, in `tests/Unit` when it
needs no WordPress and in `tests/Integration` (wp-env, WP-CLI) when it does.
At least one criterion MUST be an error / malformed-input path. This project
fails closed: it never shows a verdict the verifier did not give, a failure
is stored as state `error` with a reason, and the upload always proceeds.
Everything read from a file is untrusted text; a criterion that displays it
says how it is escaped.

- **AC1 — every stored entry is indexed**
  - Given uploads of the OpenAI image (default settings), the Amazon Titan
    image (DigiCert off) and `fixture-unsigned.jpg`
  - When they are checked
  - Then their `_provemark_c2pa_state` is `Trusted`, `Invalid` and `none`,
    and only the OpenAI image has `_provemark_c2pa_ai` `1` (Amazon Titan
    claims AI but does not verify)

- **AC2 — the column sorts from good to nothing**
  - Given one image in each of the seven groups
  - When the list is sorted by the column ascending, then descending
  - Then the order is Trusted, Valid, Invalid, error, unreadable, none, not
    checked, and the reverse; the sort link is on the column header

- **AC3 — the select filters**
  - Given the same images
  - When each option is chosen
  - Then only the images of that group are listed; "AI-generated (signed)"
    lists only the Trusted or Valid images with the label; "Not checked"
    lists the images without an index key, including non-images

- **AC4 — anything else in the request is ignored** *(error path)*
  - Given a filter value that is not one of the options
    (`' OR 1=1 --`, `Trusted`, an array), or an `order` that is not
    `asc`/`desc`
  - When the list is requested
  - Then no filter is applied and the query is the one WordPress would
    make without it; nothing from the request reaches SQL

- **AC5 — the select is escaped and remembers the choice**
  - Given the list with a filter chosen
  - When the select renders
  - Then that option is `selected`, and every value and label is escaped

- **AC6 — the backfill indexes what was checked before, in batches**
  - Given 1 200 entries without index keys (one of them not a SPEC-001
    entry) and some with them
  - When admin requests run
  - Then each request indexes at most 500, lowest ID first, the malformed
    entry gets `unreadable`, indexed ones are not rewritten, and after the
    third request an option says it is done and no further request touches
    anything

- **AC7 — uninstall removes the index** *(SPEC-005 amendment 1)*
  - Given indexed images and the backfill option
  - When the plugin is uninstalled
  - Then neither index key nor the option remains

## References

- WordPress: `WP_List_Table` (`manage_{$screen->id}_sortable_columns`),
  `WP_Media_List_Table::extra_tablenav()` (`restrict_manage_posts`),
  [`pre_get_posts`](https://developer.wordpress.org/reference/hooks/pre_get_posts/),
  `WP_Meta_Query` (named clauses, `NOT EXISTS`); read in core 7.1.2.
- Oracle: the entries of SPEC-001/SPEC-003/SPEC-004 as WP-CLI reads them;
  the attachment IDs a query returns; measured in
  `notes/m6-sort-filter.md`.
- Reasoned: that ordering by a named `EXISTS OR NOT EXISTS` meta clause
  keeps images without the key in the list; to be measured by AC2.

## API sketch

Illustrative only — not binding implementation.

```php
namespace Provemark\C2paCheck;

final class Index
{
    /** The index keys for a stored entry (what Display reads it as). */
    public static function write(int $attachmentId, mixed $entry): void;

    /** Up to $limit unindexed entries, lowest ID first; false when none are left. */
    public static function backfill(int $limit = 500): bool;
}

final class MediaSort
{
    public function register(): void;               // sortable column, select, pre_get_posts, backfill
    public static function apply(WP_Query $query, array $request): void; // the query change, testable
}
```

## Open questions

None. Resolved by Maurice on 2026-09-26, as proposed in the draft: the
select's options use SPEC-002's headlines, after "All Content Credentials".

## Amendments

1. **2026-09-26, a fix within the approved behaviour ("newest first"),
   reported to Maurice.** CI run 36232610171 failed AC4 on PHP 8.3 and 8.5:
   two images of the same state uploaded in the same second tie on
   `post_date`, and MySQL may return them in either order. The ORDER BY
   now ends with `ID DESC`; AC2 checks that order for the tie.

2. **2026-09-27, approved by Maurice van Loon** with SPEC-013. A filter
   option "Check pending" (no entry, a pending marker under an hour old);
   "Not checked" leaves those out. Sorting unchanged: pending images sort
   with the unchecked.

3. **2026-09-27, approved by Maurice van Loon** with SPEC-015. AC6 (the
   backfill of the index) is withdrawn with its code: only development
   installs ever had entries without an index. "Not checked" and "Check
   pending" list JPEG, PNG and WebP only; files the plugin never checks
   have no state.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/SortFilterTest.php` :: AC1 | `src/Index.php` `Index::write`; `src/Display.php` `Display::classify`; `src/UploadHook.php` |
| AC2 | `tests/Integration/SortFilterTest.php` :: AC2 | `src/MediaSort.php` `MediaSort::sortable`, `apply`, `orderClauses` |
| AC3 | `tests/Integration/SortFilterTest.php` :: AC3 | `MediaSort::apply` (meta clauses) |
| AC4 | `tests/Integration/SortFilterTest.php` :: AC4 (both) | `MediaSort::chosen`, `apply` (order `ASC` unless `desc`) |
| AC5 | `tests/Integration/SortFilterTest.php` :: AC5 (both) | `MediaSort::renderSelect`, `options` |
| AC6 | `tests/Integration/SortFilterTest.php` :: AC6 | `Index::backfill`, `MediaSort::backfill` (`Index::DONE_OPTION`) |
| AC7 | `tests/Integration/UninstallTest.php` :: AC1 | `uninstall.php` |
