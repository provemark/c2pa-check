<?php

declare(strict_types=1);

// uninstall_plugin() is what WordPress runs before it deletes a plugin's
// files: it defines WP_UNINSTALL_PLUGIN and includes uninstall.php, and
// deletes nothing itself. Never `wp plugin uninstall` here: without
// --skip-delete it removes the plugin folder, which in wp-env is the
// working tree.
const UNINSTALL = "require_once ABSPATH.'wp-admin/includes/plugin.php'; uninstall_plugin('tracefern-image-check-for-c2pa/tracefern-image-check-for-c2pa.php');";

function entryCount(): int
{
    return (int) wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_tracefern_result'\");");
}

function indexCount(): int
{
    return (int) wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key IN ('_tracefern_state', '_tracefern_ai')\");");
}

/**
 * The options' rows in the database, read right after $php in the same
 * request (a later request would let the still-active plugin add its empty
 * custom option again; a deleted plugin does not).
 *
 * A missing row reads as null; get_var() would also give null for an
 * existing empty value, so get_row() is used.
 *
 * @return array<string|null>
 */
function optionRowsAfter(string $php): array
{
    $out = wpEval($php." global \$wpdb; \$rows = []; foreach (['tracefern_digicert', 'tracefern_custom_trust', 'tracefern_trust_failed', 'tracefern_index_done', 'm5_unrelated'] as \$o) { \$row = \$wpdb->get_row(\$wpdb->prepare(\"SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s\", \$o), ARRAY_A); \$rows[\$o] = \$row === null ? null : (string) \$row['option_value']; } echo json_encode(\$rows);");
    $decoded = json_decode($out, true);

    return is_array($decoded) ? array_map(fn (mixed $v): ?string => is_string($v) ? $v : null, $decoded) : [];
}

afterEach(function (): void {
    resetTrustOptions();
    wpEval("delete_option('m5_unrelated');");
});

it('AC1: removes the plugin\'s entries and options on uninstall, and nothing else', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    setOption('tracefern_digicert', false);
    setOption('tracefern_custom_trust', customSettingsJson());
    setOption('tracefern_trust_failed', true);
    setOption('m5_unrelated', 'keep me');
    setOption('tracefern_index_done', true);
    wpEval("add_post_meta($id, '_m5_unrelated', 'keep me');");
    $file = wpEval("echo get_attached_file($id);");

    expect(entryCount())->toBeGreaterThan(0);

    $rows = optionRowsAfter(UNINSTALL);

    expect(entryCount())->toBe(0)
        ->and(indexCount())->toBe(0)
        ->and($rows)->toBe([
            'tracefern_digicert' => null,
            'tracefern_custom_trust' => null,
            'tracefern_trust_failed' => null,
            'tracefern_index_done' => null,
            'm5_unrelated' => 'keep me',
        ])
        ->and(wpEval("echo get_post_type($id);"))->toBe('attachment')
        ->and(wpEval("echo file_exists('$file') ? 'yes' : 'no';"))->toBe('yes')
        ->and(wpEval("echo get_post_meta($id, '_m5_unrelated', true);"))->toBe('keep me');
})->group('SPEC-005')->group('SPEC-007');

it('AC2: keeps everything on deactivation', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    setOption('tracefern_digicert', false);
    $before = [entryCount(), visibleText(columnHtml($id))];

    wpCli(['plugin', 'deactivate', 'tracefern-image-check-for-c2pa']);
    wpCli(['plugin', 'activate', 'tracefern-image-check-for-c2pa']);

    expect([entryCount(), visibleText(columnHtml($id))])->toBe($before)
        ->and(optionRowsAfter('')['tracefern_digicert'])->toBe('');
})->group('SPEC-005');
