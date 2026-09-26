<?php

declare(strict_types=1);

// uninstall_plugin() is what WordPress runs before it deletes a plugin's
// files: it defines WP_UNINSTALL_PLUGIN and includes uninstall.php, and
// deletes nothing itself. Never `wp plugin uninstall` here: without
// --skip-delete it removes the plugin folder, which in wp-env is the
// working tree.
const UNINSTALL = "require_once ABSPATH.'wp-admin/includes/plugin.php'; uninstall_plugin('provemark-c2pa-check/provemark-c2pa-check.php');";

function entryCount(): int
{
    return (int) wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE meta_key = '_provemark_c2pa_result'\");");
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
    $out = wpEval($php." global \$wpdb; \$rows = []; foreach (['provemark_c2pa_digicert', 'provemark_c2pa_custom_trust', 'provemark_c2pa_trust_failed', 'm5_unrelated'] as \$o) { \$row = \$wpdb->get_row(\$wpdb->prepare(\"SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s\", \$o), ARRAY_A); \$rows[\$o] = \$row === null ? null : (string) \$row['option_value']; } echo json_encode(\$rows);");
    $decoded = json_decode($out, true);

    return is_array($decoded) ? array_map(fn (mixed $v): ?string => is_string($v) ? $v : null, $decoded) : [];
}

afterEach(function (): void {
    resetTrustOptions();
    wpEval("delete_option('m5_unrelated');");
});

it('AC1: removes the plugin\'s entries and options on uninstall, and nothing else', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    setOption('provemark_c2pa_digicert', false);
    setOption('provemark_c2pa_custom_trust', customSettingsJson());
    setOption('provemark_c2pa_trust_failed', true);
    setOption('m5_unrelated', 'keep me');
    wpEval("add_post_meta($id, '_m5_unrelated', 'keep me');");
    $file = wpEval("echo get_attached_file($id);");

    expect(entryCount())->toBeGreaterThan(0);

    $rows = optionRowsAfter(UNINSTALL);

    expect(entryCount())->toBe(0)
        ->and($rows)->toBe([
            'provemark_c2pa_digicert' => null,
            'provemark_c2pa_custom_trust' => null,
            'provemark_c2pa_trust_failed' => null,
            'm5_unrelated' => 'keep me',
        ])
        ->and(wpEval("echo get_post_type($id);"))->toBe('attachment')
        ->and(wpEval("echo file_exists('$file') ? 'yes' : 'no';"))->toBe('yes')
        ->and(wpEval("echo get_post_meta($id, '_m5_unrelated', true);"))->toBe('keep me');
})->group('SPEC-005');

it('AC2: keeps everything on deactivation', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    setOption('provemark_c2pa_digicert', false);
    $before = [entryCount(), visibleText(columnHtml($id))];

    wpCli(['plugin', 'deactivate', 'provemark-c2pa-check']);
    wpCli(['plugin', 'activate', 'provemark-c2pa-check']);

    expect([entryCount(), visibleText(columnHtml($id))])->toBe($before)
        ->and(optionRowsAfter('')['provemark_c2pa_digicert'])->toBe('');
})->group('SPEC-005');
