# Accessibility of the column, the details and the filter

Measured 2026-09-27 in Chrome on the development environment (WordPress
7.1.2), with the colours from `assets/admin.css`.

## Contrast (WCAG 2, AA needs 4.5:1; the badges are 12 px bold, not "large")

| element | colours | ratio |
|---|---|---|
| default badge (none, not checked) | `#50575e` on `#f6f7f7` | 6.83:1 |
| Verified | `#007017` on `#edfaef` | 5.86:1 |
| Intact | `#135e96` on `#f0f6fc` | 6.29:1 |
| Does not verify | `#b32d2e` on `#fcf0f1` | 5.67:1 |
| Could not be checked / unreadable | `#8a4b00` on `#fcf9e8` | 6.43:1 |
| AI-generated | `#3c434a` on `#fff` | 10.03:1 |
| detail terms | `#50575e` on `#f0f0f1` (screen) / `#fff` (modal) | 6.43:1 / 7.33:1 |
| detail values | `#1d2327` on `#f0f0f1` | 13.95:1 |

`tests/Unit/ContrastTest.php` recomputes every badge pair from the CSS;
a lighter blue for Intact (`#72aee6`) makes it fail.

## Screen readers (read in the page)

- The column header is a `th scope="col"` "Content Credentials" with
  WordPress's sort link ("Sort ascending."); each cell carries
  `data-colname="Content Credentials"`.
- The verdict is text ("Intact: signer not trusted"); the Dashicon is
  `aria-hidden="true"`, so colour and icon are never the only carriers.
- The filter select has a label, "Filter by Content Credentials", as
  `screen-reader-text` (hidden visually, read aloud), and 8 options.
- The details are a `<dl>`: each value is announced with its term.

## Reflow (320 CSS px, WCAG's measure for 400 % zoom)

Measured in a 320, 360 and 600 px wide iframe of the same page (the
window itself was not resized): below 782 px WordPress hides all but the
first column until a row is expanded; expanded, no badge overflows its
cell (widest 183 px in a 296 px cell at 320 px) and the page has no
horizontal scroll. The Edit Media details at 320 px: nothing overflows the
197 px box; long values wrap (`overflow-wrap: anywhere`).

## Not measured

A real screen reader (VoiceOver, NVDA) reading the page; Windows high
contrast mode.
