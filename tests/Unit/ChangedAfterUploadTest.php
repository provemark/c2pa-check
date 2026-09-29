<?php

declare(strict_types=1);

it('AC7: the new line and sentence are translatable, escaped text', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Display.php');

    expect($source)->toContain("esc_html__('Changed after upload: this is not the file that was uploaded', 'tracefern-image-check-for-c2pa')")
        ->toContain("esc_html__('An image optimizer or another plugin can change a file after it is uploaded.', 'tracefern-image-check-for-c2pa')");
})->group('SPEC-028');
