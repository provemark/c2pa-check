<?php

declare(strict_types=1);

it('activates in WordPress under its slug', function (): void {
    wpCli(['plugin', 'deactivate', 'provemark-c2pa-check']);

    $activate = wpCli(['plugin', 'activate', 'provemark-c2pa-check']);
    expect($activate['exit'])->toBe(0, $activate['output']);

    $isActive = wpCli(['plugin', 'is-active', 'provemark-c2pa-check']);
    expect($isActive['exit'])->toBe(0, $isActive['output']);
});

it('opts out of updates from anywhere, so another plugin with the same slug cannot replace it', function (): void {
    expect(wpEval("require_once ABSPATH.'wp-admin/includes/plugin.php'; echo get_plugin_data(WP_PLUGIN_DIR.'/provemark-c2pa-check/provemark-c2pa-check.php', false, false)['UpdateURI'];"))->toBe('false');
});
