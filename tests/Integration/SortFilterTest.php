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
            // Made here, not uploaded: no pending check (SPEC-013).
            delete_post_meta(\$id, '_tracefern_pending');
            if (\$entry === null) {
                delete_post_meta(\$id, '_tracefern_result');
                Tracefern\\ImageCheck\\Index::write(\$id, null);
            } else {
                update_post_meta(\$id, '_tracefern_result', wp_slash(\$entry));
                Tracefern\\ImageCheck\\Index::write(\$id, \$entry);
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
    setOption('tracefern_digicert', false);
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

    $asc = listedIds($ids, ['orderby' => 'tracefern', 'order' => 'asc'])['ids'];
    $desc = listedIds($ids, ['orderby' => 'tracefern', 'order' => 'desc'])['ids'];

    // Same state and same second: the higher ID (the PDF, made last) first.
    expect(array_slice($asc, 0, 6))->toBe($good)
        ->and(array_slice($asc, 6))->toBe([$g['pdf'], $g['unchecked']])
        ->and(array_slice($desc, 2))->toBe(array_reverse($good))
        ->and(wpEval("echo json_encode(apply_filters('manage_upload_sortable_columns', []));"))->toContain('"tracefern"');
})->group('SPEC-007');

it('AC3: filters by each option', function (string $option, array $groups): void {
    $g = sevenGroups();

    expect(listedIds(array_values($g), ['tracefern' => $option])['ids'])
        ->toEqualCanonicalizing(array_map(fn (mixed $group): int => is_string($group) ? $g[$group] : 0, $groups));
})->with([
    ['trusted', ['trusted']],
    ['valid', ['valid']],
    ['invalid', ['invalid']],
    ['ai', ['trusted']],
    ['error', ['error']],
    ['none', ['none']],
    // SPEC-015: files the plugin never checks are not "Not checked".
    ['unchecked', ['unchecked']],
])->group('SPEC-007');

it('AC4: ignores anything else in the request', function (mixed $filter, mixed $order): void {
    $g = sevenGroups();
    $ids = array_values($g);
    $plain = listedIds($ids, [])['ids'];
    $result = listedIds($ids, ['tracefern' => $filter, 'orderby' => 'date', 'order' => $order]);

    expect($result['ids'])->toBe($plain)
        ->and($result['sql'])->not->toContain('OR 1=1')
        ->and($result['sql'])->not->toContain('tracefern_state');
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
        $_GET = ['tracefern' => 'Trusted'];
        $q = new WP_Query();
        $GLOBALS['wp_the_query'] = $q;
        (new Tracefern\ImageCheck\MediaSort)->onPreGetPosts($q);
        echo json_encode($q->get('meta_query'));
        PHP);

    expect($out)->not->toContain('_tracefern_state');
})->group('SPEC-007');

it('AC4: orders ascending when the order is not asc or desc', function (): void {
    $g = sevenGroups();
    $ids = array_values($g);

    expect(listedIds($ids, ['orderby' => 'tracefern', 'order' => 'desc; DROP TABLE x']))
        ->toBe(listedIds($ids, ['orderby' => 'tracefern', 'order' => 'asc']));
})->group('SPEC-007');

it('AC5: renders an escaped select that remembers the choice', function (string $chosen, ?string $selected): void {
    $payload = base64_encode($chosen);
    $html = wpEval("\$_GET['tracefern'] = base64_decode('$payload'); do_action('restrict_manage_posts', 'attachment', 'bar');");

    expect($html)->toContain('name="tracefern"')
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
    expect(wpEval("do_action('restrict_manage_posts', 'post', 'top');"))->not->toContain('tracefern');
})->group('SPEC-007');

it('SPEC-013 AC7: filters and sorts the images whose check is pending', function (): void {
    $pending = attachmentWithEntry(null);
    $unchecked = attachmentWithEntry(null);
    $valid = attachmentWithEntry(sampleEntry(['state' => 'Valid']));
    wpEval("update_post_meta($pending, '_tracefern_pending', time());");
    $ids = [$pending, $unchecked, $valid];

    $sorted = listedIds($ids, ['orderby' => 'tracefern', 'order' => 'asc'])['ids'];

    expect(listedIds($ids, ['tracefern' => 'pending'])['ids'])->toBe([$pending])
        ->and(listedIds($ids, ['tracefern' => 'unchecked'])['ids'])->toBe([$unchecked])
        ->and($sorted[0] ?? null)->toBe($valid)
        ->and(wpEval("do_action('restrict_manage_posts', 'attachment', 'top');"))->toContain('Check pending');
})->group('SPEC-013');
