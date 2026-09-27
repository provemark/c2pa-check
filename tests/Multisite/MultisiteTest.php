<?php

declare(strict_types=1);

beforeAll(function (): void {
    // The second site the tests use; created once, kept between runs.
    if (! str_contains(wpCli(['site', 'list', '--field=url'], 'multisite')['output'], '/site2/')) {
        wpCli(['site', 'create', '--slug=site2', '--title=Site 2'], 'multisite');
    }
});

afterEach(function (): void {
    foreach ([NETWORK_MAIN, NETWORK_SITE2] as $url) {
        networkEval("delete_option('provemark_c2pa_custom_trust'); delete_option('provemark_c2pa_digicert');", $url);
    }
});

it('AC1: checks and stores each site\'s uploads on that site', function (): void {
    $main = networkImport('fixture-signed.jpg');
    $sub = networkImport('openai-20260826-c2pa_2x.png', NETWORK_SITE2);

    expect(stable((array) networkEntry($main)))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile()))
        ->and(stable((array) networkEntry($sub, NETWORK_SITE2)))->toBe(expectedEntry(fixturePath('openai-20260826-c2pa_2x.png'), defaultSettingsFile()))
        ->and(networkCli(['post', 'meta', 'get', (string) $sub, '_provemark_c2pa_state'], NETWORK_SITE2)['output'])->toBe('Trusted');
})->group('SPEC-011');

it('AC2: keeps settings per site', function (): void {
    $payload = base64_encode(customSettingsJson());
    networkEval("update_option('provemark_c2pa_custom_trust', base64_decode('$payload'));", NETWORK_SITE2);

    $main = networkImport('fixture-signed.jpg');
    $sub = networkImport('fixture-signed.jpg', NETWORK_SITE2);

    expect(networkEntry($sub, NETWORK_SITE2)['state'] ?? null)->toBe('Trusted')
        ->and(networkEntry($sub, NETWORK_SITE2)['trust'] ?? null)->toBe('custom')
        ->and(networkEntry($main)['state'] ?? null)->toBe('Valid');
})->group('SPEC-011');

it('AC3: cleans every site on uninstall, and nothing else', function (): void {
    $main = networkImport('fixture-signed.jpg');
    $sub = networkImport('fixture-signed.jpg', NETWORK_SITE2);
    foreach ([NETWORK_MAIN, NETWORK_SITE2] as $url) {
        networkEval("update_option('provemark_c2pa_digicert', '0'); update_option('provemark_c2pa_index_done', '1');", $url);
    }
    networkEval("update_option('m11_unrelated', 'keep me'); add_post_meta($sub, '_m11_unrelated', 'keep me');", NETWORK_SITE2);

    // Uninstalled and counted in one request: a later request would let the
    // still-active plugin add its empty custom option again on the current
    // site (a deleted plugin does not).
    $counts = json_decode(networkEval(<<<'PHP'
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        uninstall_plugin('provemark-c2pa-check/provemark-c2pa-check.php');
        global $wpdb;
        $out = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site) {
            switch_to_blog($site);
            $out[$site] = [
                'meta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_provemark_c2pa_result', '_provemark_c2pa_state', '_provemark_c2pa_ai')"),
                'options' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'provemark\\_c2pa\\_%'"),
            ];
            restore_current_blog();
        }
        echo json_encode($out);
        PHP), true);

    expect(is_array($counts) ? array_values($counts) : [])->each->toBe(['meta' => 0, 'options' => 0])
        ->and(networkEval("echo get_option('m11_unrelated');", NETWORK_SITE2))->toBe('keep me')
        ->and(networkEval("echo get_post_meta($sub, '_m11_unrelated', true);", NETWORK_SITE2))->toBe('keep me')
        ->and(networkEval("echo get_post_type($main);"))->toBe('attachment');

    networkEval("delete_option('m11_unrelated');", NETWORK_SITE2);
})->group('SPEC-011');

it('AC4: works on a site created after activation', function (): void {
    $slug = 'm11-'.bin2hex(random_bytes(3));
    networkCli(['site', 'create', '--slug='.$slug, '--title=New site']);
    $url = NETWORK_MAIN.$slug.'/';
    try {
        $id = networkImport('fixture-signed.jpg', $url);

        expect(networkEntry($id, $url)['state'] ?? null)->toBe('Valid');
    } finally {
        networkCli(['site', 'delete', '--slug='.$slug, '--yes']);
    }
})->group('SPEC-011');

it('AC5: re-checks on one site with --url, and leaves the other alone', function (): void {
    $main = networkImport('fixture-signed.jpg');
    $sub = networkImport('fixture-signed.jpg', NETWORK_SITE2);
    $mainBefore = networkEntry($main);
    $payload = base64_encode(customSettingsJson());
    networkEval("update_option('provemark_c2pa_custom_trust', base64_decode('$payload'));", NETWORK_SITE2);

    $result = networkCli(['provemark-c2pa', 'check', (string) $sub], NETWORK_SITE2);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and(networkEntry($sub, NETWORK_SITE2)['state'] ?? null)->toBe('Trusted')
        ->and(networkEntry($main))->toBe($mainBefore);
})->group('SPEC-011');

it('SPEC-013 AC8: uninstall removes the pending markers and the scheduled checks, on every site', function (): void {
    foreach ([NETWORK_MAIN, NETWORK_SITE2] as $url) {
        // Imported, and its background check left scheduled.
        $id = (int) networkCli(['media', 'import', '/var/www/html/fixtures/fixture-signed.jpg', '--porcelain'], $url)['output'];
        expect($id)->toBeGreaterThan(0);
    }

    $counts = json_decode(networkEval(<<<'PHP'
        $before = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site) {
            switch_to_blog($site);
            $before[$site] = count(array_filter(_get_cron_array() ?: [], fn ($hooks) => isset($hooks['provemark_c2pa_check'])));
            restore_current_blog();
        }
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        uninstall_plugin('provemark-c2pa-check/provemark-c2pa-check.php');
        global $wpdb;
        $out = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site) {
            switch_to_blog($site);
            $out[$site] = [
                'scheduled before' => $before[$site] > 0,
                'markers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_provemark_c2pa_pending'"),
                'events' => count(array_filter(_get_cron_array() ?: [], fn ($hooks) => isset($hooks['provemark_c2pa_check']))),
            ];
            restore_current_blog();
        }
        echo json_encode($out);
        PHP), true);
    $sites = is_array($counts) ? array_values($counts) : [];

    expect(count($sites))->toBeGreaterThanOrEqual(2)
        ->and(array_slice($sites, 0, 2))->each->toBe(['scheduled before' => true, 'markers' => 0, 'events' => 0]);
})->group('SPEC-013');
