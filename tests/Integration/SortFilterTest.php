<?php

declare(strict_types=1);

afterEach(fn () => resetTrustOptions());

/**
 * One attachment in each of the seven sort groups, plus a PDF without an
 * entry; keyed by group.
 *
 * @return array<string, int>
 */
function sevenGroups(): array
{
    // One request for all eight: each WP-CLI call boots WordPress again.
    $entries = [
        'trusted' => sampleEntry(['state' => 'Trusted', 'ai' => true]),
        'valid' => sampleEntry(['state' => 'Valid']),
        'invalid' => sampleEntry(['state' => 'Invalid', 'codes' => ['assertion.dataHash.mismatch'], 'ai' => true]),
        'error' => sampleEntry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => 'exception']),
        'unreadable' => 'not an entry',
        'none' => sampleEntry(['state' => 'none', 'signer' => null]),
        'unchecked' => null,
    ];
    $payload = base64_encode((string) json_encode($entries));
    $out = wpEval(<<<PHP
        \$ids = [];
        foreach (json_decode(base64_decode('$payload'), true) as \$group => \$entry) {
            \$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'entry', 'post_status' => 'inherit'], '/nonexistent.jpg');
            if (\$entry === null) {
                delete_post_meta(\$id, '_provemark_c2pa_result');
                Provemark\\C2paCheck\\Index::write(\$id, null);
            } else {
                update_post_meta(\$id, '_provemark_c2pa_result', wp_slash(\$entry));
                Provemark\\C2paCheck\\Index::write(\$id, \$entry);
            }
            \$ids[\$group] = \$id;
        }
        \$ids['pdf'] = wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'a PDF', 'post_status' => 'inherit'], '/nonexistent.pdf');
        echo json_encode(\$ids);
        PHP);
    $decoded = json_decode($out, true);
    $ids = [];
    foreach (is_array($decoded) ? $decoded : [] as $group => $id) {
        if (is_string($group) && is_int($id)) {
            $ids[$group] = $id;
        }
    }

    return $ids;
}

it('AC1: indexes every stored entry', function (): void {
    $openAi = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));
    setOption('provemark_c2pa_digicert', false);
    $amazon = importMedia(fixturePath('amazon-20240925-titan-g1.png'));
    $unsigned = importMedia(fixturePath('fixture-unsigned.jpg'));

    expect(indexOf($openAi))->toBe(['state' => 'Trusted', 'ai' => '1'])
        ->and(indexOf($amazon))->toBe(['state' => 'Invalid', 'ai' => ''])
        ->and(indexOf($unsigned))->toBe(['state' => 'none', 'ai' => '']);
})->group('SPEC-007');

it('AC2: sorts from good to nothing, and back', function (): void {
    $g = sevenGroups();
    $ids = array_values($g);
    $good = [$g['trusted'], $g['valid'], $g['invalid'], $g['error'], $g['unreadable'], $g['none']];

    $asc = listedIds($ids, ['orderby' => 'provemark_c2pa', 'order' => 'asc'])['ids'];
    $desc = listedIds($ids, ['orderby' => 'provemark_c2pa', 'order' => 'desc'])['ids'];

    // Same state and same second: the higher ID (the PDF, made last) first.
    expect(array_slice($asc, 0, 6))->toBe($good)
        ->and(array_slice($asc, 6))->toBe([$g['pdf'], $g['unchecked']])
        ->and(array_slice($desc, 2))->toBe(array_reverse($good))
        ->and(wpEval("echo json_encode(apply_filters('manage_upload_sortable_columns', []));"))->toContain('"provemark_c2pa"');
})->group('SPEC-007');

it('AC3: filters by each option', function (string $option, array $groups): void {
    $g = sevenGroups();

    expect(listedIds(array_values($g), ['provemark_c2pa' => $option])['ids'])
        ->toEqualCanonicalizing(array_map(fn (mixed $group): int => is_string($group) ? $g[$group] : 0, $groups));
})->with([
    ['trusted', ['trusted']],
    ['valid', ['valid']],
    ['invalid', ['invalid']],
    ['ai', ['trusted']],
    ['error', ['error']],
    ['none', ['none']],
    ['unchecked', ['unchecked', 'pdf']],
])->group('SPEC-007');

it('AC4: ignores anything else in the request', function (mixed $filter, mixed $order): void {
    $g = sevenGroups();
    $ids = array_values($g);
    $plain = listedIds($ids, [])['ids'];
    $result = listedIds($ids, ['provemark_c2pa' => $filter, 'orderby' => 'date', 'order' => $order]);

    expect($result['ids'])->toBe($plain)
        ->and($result['sql'])->not->toContain('OR 1=1')
        ->and($result['sql'])->not->toContain('provemark_c2pa_state');
})->with([
    'SQL' => ["' OR 1=1 --", 'desc'],
    'a state name' => ['Trusted', 'desc'],
    'an array' => [['trusted'], 'desc'],
])->group('SPEC-007');

it('AC4: ignores a state name in the real request, not only in apply()', function (): void {
    $out = wpEval(<<<'PHP'
        global $pagenow;
        $pagenow = 'upload.php';
        set_current_screen('upload');
        $_GET = ['provemark_c2pa' => 'Trusted'];
        $q = new WP_Query();
        $GLOBALS['wp_the_query'] = $q;
        (new Provemark\C2paCheck\MediaSort)->onPreGetPosts($q);
        echo json_encode($q->get('meta_query'));
        PHP);

    expect($out)->not->toContain('_provemark_c2pa_state');
})->group('SPEC-007');

it('AC4: orders ascending when the order is not asc or desc', function (): void {
    $g = sevenGroups();
    $ids = array_values($g);

    expect(listedIds($ids, ['orderby' => 'provemark_c2pa', 'order' => 'desc; DROP TABLE x']))
        ->toBe(listedIds($ids, ['orderby' => 'provemark_c2pa', 'order' => 'asc']));
})->group('SPEC-007');

it('AC5: renders an escaped select that remembers the choice', function (string $chosen, ?string $selected): void {
    $payload = base64_encode($chosen);
    $html = wpEval("\$_GET['provemark_c2pa'] = base64_decode('$payload'); do_action('restrict_manage_posts', 'attachment', 'bar');");

    expect($html)->toContain('name="provemark_c2pa"')
        ->and(visibleText($html))->toContain('All Content Credentials')
        ->toContain('Verified: trusted signer')
        ->toContain('AI-generated (signed)')
        ->toContain('Not checked')
        ->and(activeMarkup($html))->toBe([]);
    if ($selected === null) {
        expect($html)->not->toContain('selected');
    } else {
        expect($html)->toMatch('/value="'.$selected.'"[^>]*selected/');
    }
})->with([
    ['invalid', 'invalid'],
    ['ai', 'ai'],
    ['"><script>alert(1)</script>', null],
])->group('SPEC-007');

it('AC5: renders no select on other post types', function (): void {
    expect(wpEval("do_action('restrict_manage_posts', 'post', 'top');"))->not->toContain('provemark_c2pa');
})->group('SPEC-007');

it('AC6: backfills unindexed entries in batches of 500', function (): void {
    // Index whatever the environment already holds, then start clean.
    wpEval('while (Provemark\\C2paCheck\\Index::backfill(500) === 500) {} delete_option("provemark_c2pa_index_done");');

    $made = wpEval(<<<'PHP'
        // Test data only: without the upload hook, which would check each one.
        remove_all_actions('add_attachment');
        $ids = [];
        for ($i = 0; $i < 1200; $i++) {
            // A unique post_name each: the same title would make WordPress
            // search ever longer for a free slug.
            $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'backfill', 'post_name' => 'm7-backfill-'.$i.'-'.wp_generate_password(6, false), 'post_status' => 'inherit'], '/nonexistent.jpg');
            update_post_meta($id, '_provemark_c2pa_result', $i === 0 ? 'not an entry' : ['schema' => 1, 'state' => 'none', 'format' => 'jpeg', 'signer' => null, 'signed_at' => null, 'codes' => [], 'ai' => false, 'remote_manifest_url' => null, 'reason' => null, 'verifier' => 'v0.2.3', 'checked_at' => '2026-09-26T12:00:00Z']);
            delete_post_meta($id, '_provemark_c2pa_state');
            delete_post_meta($id, '_provemark_c2pa_ai');
            $ids[] = $id;
        }
        update_post_meta($ids[1199], '_provemark_c2pa_state', 'Valid'); // indexed already: must stay as it is
        echo json_encode([$ids[0], $ids[1199]]);
        PHP);
    $cleanup = 'global $wpdb; $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = \'attachment\' AND post_title = \'backfill\'"); if ($ids) { $in = implode(",", array_map("intval", $ids)); $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)"); $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($in)"); }';
    $made = json_decode($made, true);
    $first = is_array($made) && is_int($made[0] ?? null) ? $made[0] : 0;
    $last = is_array($made) && is_int($made[1] ?? null) ? $made[1] : 0;
    $unindexed = "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} r LEFT JOIN {\$wpdb->postmeta} s ON s.post_id = r.post_id AND s.meta_key = '_provemark_c2pa_state' WHERE r.meta_key = '_provemark_c2pa_result' AND s.meta_id IS NULL\");";
    $request = '(new Provemark\\C2paCheck\\MediaSort)->backfill(); echo get_option("provemark_c2pa_index_done") ? "done" : "busy";';

    try {
        expect((int) wpEval($unindexed))->toBe(1199)
            ->and(wpEval($request))->toBe('busy')
            ->and((int) wpEval($unindexed))->toBe(699)
            ->and(indexOf($first)['state'])->toBe('unreadable')
            ->and(wpEval($request))->toBe('busy')
            ->and((int) wpEval($unindexed))->toBe(199)
            ->and(wpEval($request))->toBe('done')
            ->and((int) wpEval($unindexed))->toBe(0)
            ->and(indexOf($last)['state'])->toBe('Valid');

        // Done means done: a new unindexed entry is left alone.
        wpEval("\$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'backfill', 'post_status' => 'inherit'], '/nonexistent.jpg'); delete_post_meta(\$id, '_provemark_c2pa_state');");
        expect(wpEval($request))->toBe('done')
            ->and((int) wpEval($unindexed))->toBe(1);

    } finally {
        wpEval($cleanup.' delete_option("provemark_c2pa_index_done");');
    }
})->group('SPEC-007');
