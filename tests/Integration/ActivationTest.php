<?php

declare(strict_types=1);

it('activates in WordPress under its slug', function (): void {
    wpCli(['plugin', 'deactivate', 'tracefern-image-check-for-c2pa']);

    $activate = wpCli(['plugin', 'activate', 'tracefern-image-check-for-c2pa']);
    expect($activate['exit'])->toBe(0, $activate['output']);

    $isActive = wpCli(['plugin', 'is-active', 'tracefern-image-check-for-c2pa']);
    expect($isActive['exit'])->toBe(0, $isActive['output']);
});
