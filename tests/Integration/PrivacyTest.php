<?php

declare(strict_types=1);

it('AC1: puts the suggested text in the Privacy Policy Guide', function (): void {
    // Only the plugin's own callback on admin_init: firing all of core's in
    // WP-CLI runs callbacks that expect a real admin screen.
    $out = wpEval(<<<'PHP'
        require_once ABSPATH.'wp-admin/includes/admin.php';
        set_current_screen('options-privacy');
        remove_all_actions('admin_init');
        (new Provemark\C2paCheck\PrivacyPolicy)->register();
        do_action('admin_init');
        $entries = WP_Privacy_Policy_Content::get_suggested_policy_text();
        echo json_encode(array_values(array_filter($entries, fn ($e) => ($e['plugin_name'] ?? '') === 'Provemark C2PA Check')));
        PHP);
    $entries = json_decode($out, true);
    $policy = is_array($entries) && is_array($entries[0] ?? null) && is_string($entries[0]['policy_text'] ?? null) ? $entries[0]['policy_text'] : '';

    expect(is_array($entries) ? count($entries) : 0)->toBe(1, $out)
        ->and($policy)->toMatch('/class="privacy-policy-tutorial"[^>]*>[^<]*reads the Content Credentials/')
        ->and(visibleText($policy))->toContain('It sends nothing to anyone.')
        ->toContain('the name of the signer and of its certificate\'s issuer as the file states them')
        ->toContain('The signer\'s name can be a person\'s name.')
        ->toContain('It is deleted when the image is deleted, or when the plugin is removed.');
})->group('SPEC-010');

it('AC2: adds nothing outside the admin, and raises no notice', function (): void {
    // Only the plugin's own callback: core registers its own policy text on
    // admin_init too, which raises the notice itself outside the admin.
    $out = wpEval(<<<'PHP'
        require_once ABSPATH.'wp-admin/includes/admin.php';
        $notices = [];
        add_action('doing_it_wrong_run', function ($function) use (&$notices) { $notices[] = $function; });
        $privacy = new Provemark\C2paCheck\PrivacyPolicy;
        $privacy->register();
        $privacy->addText();
        // What this request registered (get_suggested_policy_text() also
        // lists texts of earlier requests, marked as removed).
        global $wp_privacy_policy_content;
        $added = count($wp_privacy_policy_content['Provemark C2PA Check'] ?? []);
        echo json_encode(['admin' => is_admin(), 'hooked' => has_action('admin_init', [$privacy, 'addText']) !== false, 'notices' => $notices, 'added' => $added]);
        PHP);

    expect($out)->toContain('"admin":false')
        ->toContain('"hooked":true')
        ->toContain('"notices":[]')
        ->toContain('"added":0');
})->group('SPEC-010');

it('AC3: removes its data when the image is deleted', function (): void {
    $id = importMedia(fixturePath('openai-20260826-c2pa_2x.png'));
    expect(indexOf($id)['ai'])->toBe('1');

    wpEval("wp_delete_attachment($id, true);");

    expect((int) wpEval("global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_provemark_c2pa_result', '_provemark_c2pa_state', '_provemark_c2pa_ai')\", $id));"))->toBe(0);
})->group('SPEC-010');
