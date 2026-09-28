<?php

declare(strict_types=1);

/**
 * The five images of the Live Preview (SPEC-024), by attachment title: the
 * file the oracle checks, the state it must have with the default
 * settings, whether the column shows the AI label, and what the caption
 * must name.
 *
 * @return array<string, array{file: string, state: string, label: bool, credits: list<string>}>
 */
function previewImages(): array
{
    return [
        'google-20250919-pixel10-npld-picnic-table' => ['file' => fixturePath('google-20250919-pixel10-npld-picnic-table.jpg'), 'state' => 'Trusted', 'label' => false, 'credits' => ['Wikimedia Commons', 'Bureau of Land Management', 'public domain']],
        'openai-20260826-c2pa_2x' => ['file' => fixturePath('openai-20260826-c2pa_2x.png'), 'state' => 'Trusted', 'label' => true, 'credits' => ['richardwooding/c2pa', 'MIT']],
        'amazon-20240925-titan-g1' => ['file' => fixturePath('amazon-20240925-titan-g1.png'), 'state' => 'Valid', 'label' => true, 'credits' => ['TrustNXT/c2pa-ts', 'Apache-2.0']],
        'openai-20260826-c2pa_2x-altered' => ['file' => tamperedOpenAiPng(), 'state' => 'Invalid', 'label' => false, 'credits' => ['richardwooding/c2pa', 'MIT', 'one byte']],
        'fixture-unsigned' => ['file' => fixturePath('fixture-unsigned.jpg'), 'state' => 'none', 'label' => false, 'credits' => ['provemark/c2pa-verifier', 'MIT']],
    ];
}

/**
 * Runs a blueprint in Playground CLI with the plugin from the local build
 * instead of the directory, and returns the exit code, the output and what
 * the run left in /out/results.json: per attachment title, the stored
 * result, the Media Library column, the caption and the SHA-256 of the
 * original file.
 *
 * @param  array<mixed>  $blueprint
 * @return array{exit: int, output: string, results: array<mixed>}
 */
function runPreview(array $blueprint): array
{
    $root = dirname(__DIR__, 2);
    $out = tmpDir().'/preview-'.bin2hex(random_bytes(4));
    mkdir($out);

    $steps = [];
    foreach (is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : [] as $step) {
        $steps[] = is_array($step) && ($step['step'] ?? null) === 'installPlugin'
            ? ['step' => 'activatePlugin', 'pluginPath' => 'tracefern-image-check-for-c2pa/tracefern-image-check-for-c2pa.php']
            : $step;
    }
    $steps[] = ['step' => 'runPHP', 'code' => <<<'PHP'
        <?php
        require '/wordpress/wp-load.php';
        $results = [];
        foreach (get_posts(['post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any']) as $post) {
            ob_start();
            do_action('manage_media_custom_column', 'tracefern', $post->ID);
            $results[$post->post_title] = [
                'entry' => get_post_meta($post->ID, '_tracefern_result', true),
                'column' => (string) ob_get_clean(),
                'caption' => $post->post_excerpt,
                'sha256' => hash_file('sha256', (string) wp_get_original_image_path($post->ID)),
            ];
        }
        file_put_contents('/out/results.json', json_encode($results));
        PHP];
    $blueprint['steps'] = $steps;
    file_put_contents($out.'/blueprint.json', json_encode($blueprint));

    $command = escapeshellarg($root.'/node_modules/.bin/wp-playground-cli').' run-blueprint'
        .' --blueprint='.escapeshellarg($out.'/blueprint.json')
        .' --mount='.escapeshellarg($root.'/build/tracefern-image-check-for-c2pa:/wordpress/wp-content/plugins/tracefern-image-check-for-c2pa')
        .' --mount='.escapeshellarg($out.':/out')
        .' 2>&1';
    exec($command, $lines, $exit);
    $decoded = is_file($out.'/results.json') ? json_decode((string) file_get_contents($out.'/results.json'), true) : null;

    return ['exit' => $exit, 'output' => implode("\n", $lines), 'results' => is_array($decoded) ? $decoded : []];
}

/**
 * The run of the blueprint as committed; once per test file, as it takes
 * a while.
 *
 * @return array{exit: int, output: string, results: array<mixed>}
 */
function previewRun(): array
{
    /** @var array{exit: int, output: string, results: array<mixed>}|null $run */
    static $run = null;

    return $run ??= runPreview(blueprint());
}

/**
 * One attachment's part of the run.
 *
 * @return array<mixed>
 */
function previewResult(string $title): array
{
    $result = previewRun()['results'][$title] ?? null;

    return is_array($result) ? $result : [];
}

/**
 * One text field of an attachment's part of the run, or ''.
 */
function previewText(string $title, string $field): string
{
    $text = previewResult($title)[$field] ?? null;

    return is_string($text) ? $text : '';
}

it('AC2: shows the verifier\'s verdict for every image', function (): void {
    $run = previewRun();

    expect($run['exit'])->toBe(0, $run['output'])
        ->and(array_keys($run['results']))->toEqualCanonicalizing(array_keys(previewImages()));

    foreach (previewImages() as $title => $image) {
        $result = previewResult($title);
        $entry = is_array($result['entry'] ?? null) ? $result['entry'] : [];

        expect($result['sha256'] ?? null)->toBe(hash_file('sha256', $image['file']), $title)
            ->and($entry['state'] ?? null)->toBe($image['state'], $title)
            ->and(stable($entry))->toBe(expectedEntry($image['file'], defaultSettingsFile()), $title);
    }
})->group('SPEC-024');

it('AC3: shows the AI label only where it may be shown', function (): void {
    foreach (previewImages() as $title => $image) {
        $column = visibleText(previewText($title, 'column'));

        expect($column)->not->toBe('', $title);
        $image['label']
            ? expect($column)->toContain('AI-generated (signed)')
            : expect($column)->not->toContain('AI-generated');
    }

    $altered = previewResult('openai-20260826-c2pa_2x-altered')['entry'] ?? [];
    expect(is_array($altered) ? ($altered['ai'] ?? null) : null)->toBeTrue();
})->group('SPEC-024');

it('AC4: credits each image in its caption', function (): void {
    foreach (previewImages() as $title => $image) {
        $caption = previewText($title, 'caption');

        foreach ($image['credits'] as $credit) {
            expect($caption)->toContain($credit);
        }
    }
})->group('SPEC-024');

it('AC5: stops at a missing image', function (): void {
    $blueprint = blueprint();
    $missing = 'https://raw.githubusercontent.com/provemark/tracefern-image-check/v0.1.0/tests/Fixtures/does-not-exist.png';
    $steps = is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : [];
    foreach ($steps as $i => $step) {
        $data = is_array($step) && is_array($step['data'] ?? null) ? $step['data'] : [];
        if (is_array($step) && ($step['step'] ?? null) === 'writeFile' && ($data['resource'] ?? null) === 'url') {
            $step['data'] = ['resource' => 'url', 'url' => $missing];
            $steps[$i] = $step;
            break;
        }
    }
    $blueprint['steps'] = $steps;
    $run = runPreview($blueprint);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('Error when executing the blueprint step #')
        ->toContain('Could not download "'.$missing.'"')
        ->and($run['results'])->toBe([]);
})->group('SPEC-024');
