<?php

declare(strict_types=1);

afterEach(fn () => resetTrustOptions());

it('AC1: trusts a Pixel 10 photo with the default settings', function (): void {
    $path = fixturePath('google-20250919-pixel10-npld-picnic-table.jpg');
    $id = importMedia($path);

    expect(storedEntry($id)['state'] ?? null)->toBe('Trusted')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($path, defaultSettingsFile()))
        ->and(storedEntry($id)['trust'] ?? null)->toBe('c2pa-2026-08-14+digicert')
        ->and(visibleText(columnHtml($id)))->toBe('Verified: trusted signer');
})->group('SPEC-004');

it('AC2: trusts and labels an OpenAI image with the default settings', function (): void {
    $path = fixturePath('openai-20260826-c2pa_2x.png');
    $id = importMedia($path);

    expect(storedEntry($id)['state'] ?? null)->toBe('Trusted')
        ->and(expectedEntry($path, defaultSettingsFile())['state'])->toBe('Trusted')
        ->and(visibleText(columnHtml($id)))->toContain('AI-generated (signed)');
})->group('SPEC-004');

it('AC3: lets DigiCert decide an expired signer with a DigiCert timestamp', function (bool $digiCert, string $state, string $trust): void {
    setOption('tracefern_digicert', $digiCert);
    $path = fixturePath('amazon-20240925-titan-g1.png');
    $id = importMedia($path);

    expect(storedEntry($id)['state'] ?? null)->toBe($state)
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($path, defaultSettingsFile($digiCert)))
        ->and(storedEntry($id)['trust'] ?? null)->toBe($trust);
})->with([
    'DigiCert on' => [true, 'Valid', 'c2pa-2026-08-14+digicert'],
    'DigiCert off' => [false, 'Invalid', 'c2pa-2026-08-14'],
])->group('SPEC-004');

it('AC4: lets custom settings replace the bundled lists', function (): void {
    setOption('tracefern_custom_trust', customSettingsJson());
    $signed = importMedia(fixturePath('fixture-signed.jpg'));
    $pixel = importMedia(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'));

    expect(storedEntry($signed)['state'] ?? null)->toBe('Trusted')
        ->and(stable((array) storedEntry($signed)))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), customSettingsFile()))
        ->and(storedEntry($pixel)['state'] ?? null)->not->toBe('Trusted')
        ->and(stable((array) storedEntry($pixel)))->toBe(expectedEntry(fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'), customSettingsFile()))
        ->and(storedEntry($signed)['trust'] ?? null)->toBe('custom')
        ->and(storedEntry($pixel)['trust'] ?? null)->toBe('custom');
})->group('SPEC-004');

it('AC5: refuses custom text that is not trust settings, and keeps the old value', function (string $bad): void {
    setOption('tracefern_custom_trust', customSettingsJson());
    $payload = base64_encode($bad);
    // Registered as options.php registers it, through admin_init (SPEC-012).
    $out = wpEval(REGISTER_SETTINGS_ON.<<<PHP
        (\$registerSettingsOn('admin_init'))();
        update_option('tracefern_custom_trust', base64_decode('$payload'));
        echo json_encode(['kept' => get_option('tracefern_custom_trust'), 'errors' => get_settings_errors('tracefern_custom_trust')]);
        PHP);
    $result = json_decode($out, true);
    $kept = is_array($result) ? ($result['kept'] ?? null) : null;
    $errors = is_array($result) && is_array($result['errors'] ?? null) ? $result['errors'] : [];
    $message = is_array($errors[0] ?? null) ? ($errors[0]['message'] ?? '') : '';

    expect($kept)->toBe(customSettingsJson())
        ->and(is_string($message) && $message !== '')->toBeTrue();
})->with([
    'not JSON' => ['{not json'],
    'a loose allowed_list' => ['{"trust":{"allowed_list":"x"}}'],
])->group('SPEC-004');

it('AC6: checks without settings it cannot build, says so, and clears the notice after', function (): void {
    wpEval("remove_all_filters('sanitize_option_tracefern_custom_trust'); update_option('tracefern_custom_trust', '{not json');");
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    $notice = wpEval("do_action('admin_notices');");

    expect(storedEntry($id)['trust'] ?? null)->toBe('none')
        ->and(storedEntry($id)['state'] ?? null)->toBe(expectedEntry(fixturePath('fixture-signed.jpg'))['state'])
        ->and(visibleText($notice))->toContain('without trust settings');

    wpEval("delete_option('tracefern_custom_trust');");
    importMedia(fixturePath('fixture-signed.jpg'));

    expect(visibleText(wpEval("do_action('admin_notices');")))->not->toContain('without trust settings');
})->group('SPEC-004');

it('AC7: names the trust source in the details', function (?string $trust, string $ending): void {
    $entry = sampleEntry($trust === null ? [] : ['trust' => $trust]);

    expect(visibleText(detailsHtml(attachmentWithEntry($entry), false)))->toMatch('/'.preg_quote($ending, '/').'$/');
})->with([
    ['c2pa-2026-08-14+digicert', 'Trust list C2PA, 2026-08-14 (with DigiCert timestamps)'],
    ['c2pa-2026-08-14', 'Trust list C2PA, 2026-08-14'],
    ['custom', 'Trust list Custom trust settings'],
    ['none', 'Trust list None'],
    [null, 'Checked 2026-09-26 12:00 UTC, c2pa-verifier v0.2.3'],
])->group('SPEC-004');

it('AC8: shows the settings page to administrators only, escaped', function (): void {
    // Stored past validation (which would refuse it), to test the output.
    $hostile = base64_encode('</textarea><script>alert(1)</script>');
    wpEval("remove_all_filters('sanitize_option_tracefern_custom_trust'); update_option('tracefern_custom_trust', base64_decode('$hostile'));");
    $page = wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();");

    expect(visibleText($page))->toContain('2026-08-14')
        ->toContain('99927ca')
        ->toContain('new uploads')
        ->and($page)->toMatch('/name="tracefern_digicert"[^>]*checked/')
        ->and($page)->toContain('&lt;/textarea&gt;&lt;script&gt;')
        ->and(activeMarkup($page))->not->toContain('<script>');

    wpCli(['user', 'create', 'm4-subscriber', 'm4-subscriber@example.test', '--role=subscriber']);
    expect(trim(wpEvalAs('m4-subscriber', "require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();")))->toBe('')
        ->and(wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; do_action('admin_menu'); global \$submenu; foreach (\$submenu['options-general.php'] ?? [] as \$item) { if (\$item[2] === 'tracefern-image-check-for-c2pa') { echo \$item[1]; } }"))->toBe('manage_options');
    wpCli(['user', 'delete', 'm4-subscriber', '--yes']);
})->group('SPEC-004');

it('AC9: shows no age warning for a list from 2026-08-14 today', function (): void {
    expect(visibleText(wpEval("require_once ABSPATH.'wp-admin/includes/admin.php'; (new Tracefern\\ImageCheck\\SettingsPage)->render();")))->not->toContain('older than');
})->group('SPEC-004');
