<?php

declare(strict_types=1);

it('activates in WordPress under its slug', function (): void {
    wpCli(['plugin', 'deactivate', 'provemark-c2pa-check']);

    $activate = wpCli(['plugin', 'activate', 'provemark-c2pa-check']);
    expect($activate['exit'])->toBe(0, $activate['output']);

    $isActive = wpCli(['plugin', 'is-active', 'provemark-c2pa-check']);
    expect($isActive['exit'])->toBe(0, $isActive['output']);
});
