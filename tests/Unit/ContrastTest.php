<?php

declare(strict_types=1);

/**
 * WCAG 2 relative luminance of a #rgb or #rrggbb colour.
 */
function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $channel = function (string $pair): float {
        $c = hexdec($pair) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
}

function contrastRatio(string $a, string $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

it('keeps every badge at WCAG AA contrast (4.5:1; the badges are 12 px text)', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/admin.css');
    preg_match_all('/\.tracefern-badge(--[a-z]+)?[^{]*\{[^}]*?color:\s*(#[0-9a-f]{3,6});[^}]*?background:\s*(#[0-9a-f]{3,6});/', $css, $pairs, PREG_SET_ORDER);

    expect(count($pairs))->toBeGreaterThanOrEqual(6);
    foreach ($pairs as [$rule, $modifier, $color, $background]) {
        expect(contrastRatio($color, $background))->toBeGreaterThanOrEqual(4.5, "badge{$modifier}: {$color} on {$background}");
    }
});
