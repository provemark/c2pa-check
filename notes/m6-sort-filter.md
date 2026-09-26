# SPEC-007 preparation: sorting and filtering by Content Credentials

Measured 2026-09-26 on WordPress 7.1.2 in wp-env.

## Hooks (read in core)

- The Media Library list mode is `WP_Media_List_Table` on the screen with
  id `upload` (measured: `convert_to_screen('upload')->id`), so the
  sortable columns come from `manage_upload_sortable_columns`
  (`class-wp-list-table.php`, `manage_{$screen->id}_sortable_columns`).
- `WP_Media_List_Table::extra_tablenav()` fires
  `restrict_manage_posts` above the list: the place for a filter select.
- The list's query comes from `wp_edit_attachments_query_vars()`; a
  plugin changes it in `pre_get_posts` (admin, main query).
- Grid mode and the media modal fetch attachments over AJAX
  (`query-attachments`, filter `ajax_query_attachments_args`); a filter
  there also needs JavaScript in the media views. Not measured further.

## Why a second meta key

The result is one serialized array (`_provemark_c2pa_result`). MySQL cannot
sort or filter on a field inside it. A small key with only the state (and
one for the AI label) can be sorted and filtered on.

## Cost (measured)

- Writing one small meta value for each of the 1 173 attachments in the
  development environment: 266 ms, 0.23 ms each (removed afterwards; 0
  left).
- A sorted page of 20 attachments ordered by such a key: 3 ms.
- The development environment had 14 entries at the time (the uninstall
  test of SPEC-005 had removed the rest): 5 Valid, 5 none, 1 Invalid,
  3 error.

Reasoned: at 0.23 ms per image, 10 000 images take about 2.3 s to index,
100 000 about 23 s; a backfill should therefore run in batches, not in one
request.
