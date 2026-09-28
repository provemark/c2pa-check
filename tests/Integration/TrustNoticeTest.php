<?php

declare(strict_types=1);

afterEach(fn () => wpEval("delete_option('tracefern_trust_failed');"));

/**
 * The admin notices an administrator gets on the screen $screen.
 */
function noticesOn(string $screen): string
{
    return visibleText(wpEval(
        "update_option('tracefern_trust_failed', 1, false); \$screen = '$screen'; ".ON_SCREEN."\ndo_action('admin_notices');"
    ));
}

it('AC1: shows the trust notice on the screens it concerns', function (string $screen): void {
    expect(noticesOn($screen))->toContain('without trust settings');
})->with(['upload', 'media', 'attachment', 'settings_page_tracefern-image-check-for-c2pa'])->group('SPEC-022');

it('AC2: keeps the trust notice off other screens', function (string $screen): void {
    expect(noticesOn($screen))->not->toContain('without trust settings');
})->with(['dashboard', 'edit-post', 'plugins'])->group('SPEC-022');
