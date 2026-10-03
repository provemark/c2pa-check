# SPEC-033: what the dashboard summary costs on a large library

Measured 2026-10-03 in the test environment (`.wp-env.test.json`,
WordPress 7.1.2, MariaDB 12.3.3, PHP 8.3, under colima on an Apple Mac),
on commit `cc35e5e`. This answers SPEC-033's open question: "measure the
eight counts on 10,000 attachments … if one dashboard view costs more
than about 50 ms, a separate amendment proposes a short cache."

## Method

- **The library.** Image attachments without files, written straight into
  `wp_posts` and `wp_postmeta` so no plugin hook ran, each with
  `_wp_attached_file` and a realistic `_wp_attachment_metadata` (six image
  sizes, EXIF). Per 10,000 images: 6,000 `none`, 800 `Trusted` (300 of
  them AI), 800 `Valid` (200 AI), 200 `Invalid`, 100 `error`, 50 fresh
  and 50 old pending markers, 2,000 never checked; each checked one also
  with a `_tracefern_result`. Plus 500 PDFs. MIME types 3 : 1 : 1 JPEG,
  PNG, WebP. The 19 attachments left by earlier test runs were in it too.
- **The measurement.** `DashboardSummary::render()` as the administrator,
  10 times in one request, `wp_cache_flush()` before each; time with
  `hrtime()`, and each query's own time with `SAVEQUERIES`. Repeated in
  three requests at 10,000. For comparison, core's "At a Glance" and
  "Activity" widgets, rendered the same way on the same library.
- Removed afterwards (105,000 attachments and their meta; 19 left).

## Results (measured)

| Library | `wp_postmeta` rows | One dashboard view (median of 10) |
|---|---|---|
| 10,019 images, 500 PDFs | 37,027 | **51.8, 49.9 and 50.4 ms** in three requests (range 48.8–54.6) |
| 100,019 images, 5,000 PDFs | 369,127 | **1,866 ms** (range 1,765–2,009) |

At 10,000, core's "At a Glance" takes 1.1 ms and "Activity" 1.7 ms
(medians).

Nearly all of it is in the database (49.2 of 49.9 ms; 1,859 of 1,866
ms). Per count, in milliseconds:

| Count | 10,000 | 100,000 |
|---|---|---|
| total | 1.8 | 28 |
| Verified (`Trusted`) | 5.3 | 248 |
| Intact (`Valid`) | 5.4 | 262 |
| Does not verify | 5.1 | 237 |
| AI-generated | 0.7 | 73 |
| Could not be checked | 4.8 | 225 |
| No Content Credentials | 7.8 | 284 |
| Check pending | 0.3 | 27 |
| Not checked | 16.8 | 474 |

Ten times the images cost about 37 times the time.

## Why (measured, with EXPLAIN)

A state count is `INNER JOIN wp_postmeta … WHERE meta_key =
'_tracefern_state' AND meta_value = 'Trusted' GROUP BY ID ORDER BY
post_date DESC LIMIT 0, 1` with `SQL_CALC_FOUND_ROWS`. MariaDB reads all
of `wp_postmeta` (`type ALL`, 359,487 rows at 100,000) with a temporary
table and a filesort: `meta_value` has no index, and core's `meta_key`
index is not chosen when a fifth of the table has that key. Each of the
five state counts scans the whole table again.

Two changes measured at 100,000, by hand, not in the plugin:

- `'orderby' => 'none'` on all eight counts: 1,655 ms instead of 1,817
  ms, 9 % less.
- One query for every state at once (`SELECT meta_value, COUNT(*) …
  GROUP BY meta_value`, same join and post statuses): 299–354 ms for the
  five state counts together, against about 1,250 ms for the five
  separate ones. Its numbers equal the filter's (Trusted 8,003, Valid
  8,005, Invalid 2,002, error 1,001, none 60,003), plus 3 `unreadable`
  that no line shows.

## What follows (reasoned)

- At 10,000 images the summary is at the spec's threshold of about
  50 ms; at 100,000 it adds almost two seconds to every dashboard view.
  The spec's rule applies: an amendment.
- With the grouped state count the 100,000 view would be about 0.9 s
  (330 + 73 + 27 + 474 + 28 ms, added up, not measured as one view):
  better, still too slow, and it no longer counts the states through
  `MediaSort::apply()`, so "cannot drift from the filter" would rest on
  AC1's test alone.
- "Not checked" stays the most expensive count, whatever is done to the
  states.
- The site owner sees nothing of this on a small library; a large one
  (a newsroom, a photo archive) is the user this plugin hopes for.
