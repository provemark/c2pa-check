<?php

declare(strict_types=1);

it('AC8: the section\'s texts are escaped and translatable, numbers localised', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/SettingsPage.php');
    $section = (string) preg_replace('/.*(private function existingImages\(\).*?\n    }\n).*/s', '$1', $source);

    expect($section)->toContain("esc_html__('Existing images', 'tracefern-image-check-for-c2pa')")
        ->toContain("esc_html__('Check images that were never checked', 'tracefern-image-check-for-c2pa')")
        ->toContain("esc_html__('Check all images again', 'tracefern-image-check-for-c2pa')")
        ->toContain("esc_html__('Stop', 'tracefern-image-check-for-c2pa')")
        ->toContain('number_format_i18n(')
        ->and(preg_match_all('/\becho\b/', $section))->toBeGreaterThan(0)
        ->and(str_contains($section, '__(') && ! preg_match('/[^_]__\(/', str_replace(['esc_html__(', 'esc_attr__('], '', $section)))->toBeTrue();
})->group('SPEC-031');
