<?php

declare(strict_types=1);

it('AC1: registers both settings with a named sanitize callback', function (): void {
    $out = wpEval(REGISTER_SETTINGS_ON.<<<'PHP'
        ($registerSettingsOn('admin_init'))();
        $settings = get_registered_settings();
        $named = static fn (mixed $cb): bool => is_string($cb) || (is_array($cb) && is_string($cb[0] ?? null) && is_string($cb[1] ?? null));
        echo json_encode([
            'digicert' => $named($settings['tracefern_digicert']['sanitize_callback'] ?? null),
            'custom' => $named($settings['tracefern_custom_trust']['sanitize_callback'] ?? null),
        ]);
        PHP);

    expect(json_decode($out, true))->toBe(['digicert' => true, 'custom' => true]);
})->group('SPEC-021');

it('AC2: reads the DigiCert checkbox as WordPress reads a boolean', function (): void {
    $out = wpEval(REGISTER_SETTINGS_ON.<<<'PHP'
        ($registerSettingsOn('admin_init'))();
        $cb = get_registered_settings()['tracefern_digicert']['sanitize_callback'];
        echo json_encode(array_map(static fn (mixed $v): bool => call_user_func($cb, $v), ['1', true, '', '0', 'false', null]));
        PHP);

    expect(json_decode($out, true))->toBe([true, true, false, false, false, false]);
})->group('SPEC-021');
