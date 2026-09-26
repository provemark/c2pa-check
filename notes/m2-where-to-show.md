# M2.0: where the result can be shown

Measured 2026-09-26 on WordPress 7.1.2, PHP 8.3.35 (wp-env), Chrome 153,
with a must-use probe plugin (below) mounted through an untracked
`.wp-env.override.json` and removed afterwards. Input for SPEC-002.

## Results (measured)

| place | mechanism | seen |
|---|---|---|
| Media Library, list mode (`upload.php?mode=list`) | filter `manage_media_columns` + action `manage_media_custom_column` | a column after `date`, one cell per row (20 rows on the page), visible by default (its Screen Options toggle is checked) |
| Edit Media (`post.php?post=<id>&action=edit`) | filter `attachment_fields_to_edit` | a row in `.compat-attachment-fields` |
| Media Library, grid mode, attachment details modal | the same filter | a row in `.compat-item` |
| Block editor (`post-new.php`), media modal (`wp.media`), library tab | the same filter | a row in `.compat-item`; the page is cross-origin isolated, the field still shows |

## How the field is rendered (read in core)

`wp-admin/includes/media.php`, `get_compat_media_markup()` (called from
`edit_form_image_editor()` for Edit Media and from
`wp_prepare_attachment_for_js()` with `in_modal` for the modal): for a
field with `'input' => 'html'`, the `html` value is concatenated into the
page **as is**, and so is `label`. Neither is escaped by WordPress; the
plugin must escape everything it puts there. `show_in_edit` and
`show_in_modal` (both default true) choose the places.

## Not measured

The Attachment details panel inside the block editor's own media inserter
(the inline "Media" tab, not `wp.media`); a multisite network.

## The probe

```php
add_filter('attachment_fields_to_edit', function (array $fields, WP_Post $post) {
    $fields['m2_probe'] = ['label' => 'M2 PROBE LABEL', 'input' => 'html', 'html' => '<span class="m2-probe">M2 PROBE '.(int) $post->ID.'</span>'];
    return $fields;
}, 10, 2);
add_filter('manage_media_columns', function (array $cols) {
    $cols['m2_probe'] = 'M2 PROBE COLUMN';
    return $cols;
});
add_action('manage_media_custom_column', function (string $col, int $id) {
    if ($col === 'm2_probe') echo '<span class="m2-probe-cell">cell '.(int) $id.'</span>';
}, 10, 2);
```
