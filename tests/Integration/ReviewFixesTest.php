<?php

declare(strict_types=1);

afterEach(fn () => resetTrustOptions());

it('AC1: does not read the custom settings outside the admin', function (): void {
    $payload = base64_encode(customSettingsJson());
    wpEval("delete_option('provemark_c2pa_custom_trust'); add_option('provemark_c2pa_custom_trust', base64_decode('$payload'), '', false);");

    $out = wpEval(REGISTER_SETTINGS_ON.<<<'PHP'
        echo json_encode([
            'cached' => wp_cache_get('provemark_c2pa_custom_trust', 'options') !== false,
            'autoloaded' => array_key_exists('provemark_c2pa_custom_trust', wp_load_alloptions()),
            'on_init' => $registerSettingsOn('init') !== null,
            'on_admin_init' => $registerSettingsOn('admin_init') !== null,
        ]);
        PHP);

    expect(json_decode($out, true))->toBe(['cached' => false, 'autoloaded' => false, 'on_init' => false, 'on_admin_init' => true]);
})->group('SPEC-012');

it('AC2: still saves the settings form, and refuses what is not trust settings', function (): void {
    wpEval("delete_option('provemark_c2pa_custom_trust');");
    $valid = base64_encode(customSettingsJson());

    $out = wpEval(REGISTER_SETTINGS_ON.<<<PHP
        (\$registerSettingsOn('admin_init'))();
        update_option('provemark_c2pa_custom_trust', base64_decode('$valid'));
        \$saved = get_option('provemark_c2pa_custom_trust');
        global \$wpdb;
        \$autoload = \$wpdb->get_var(\$wpdb->prepare("SELECT autoload FROM {\$wpdb->options} WHERE option_name = %s", 'provemark_c2pa_custom_trust'));
        update_option('provemark_c2pa_custom_trust', '{not json');
        echo json_encode([
            'saved' => \$saved === base64_decode('$valid'),
            'autoload' => \$autoload,
            'kept' => get_option('provemark_c2pa_custom_trust') === base64_decode('$valid'),
            'errors' => count(get_settings_errors('provemark_c2pa_custom_trust')),
        ]);
        PHP);

    expect(json_decode($out, true))->toBe(['saved' => true, 'autoload' => 'off', 'kept' => true, 'errors' => 1]);
})->group('SPEC-012');

it('AC4: checks an upload outside the admin', function (): void {
    $entry = storedEntry(importMedia(fixturePath('fixture-signed.jpg')));

    expect($entry['state'] ?? null)->toBe('Valid')
        ->and(stable((array) $entry))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), defaultSettingsFile()));
})->group('SPEC-012');
