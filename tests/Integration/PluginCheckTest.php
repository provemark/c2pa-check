<?php

declare(strict_types=1);

// Development files that never ship. Plugin Check reads the working tree,
// not the release; until M5 builds the zip, this list is the difference.
const DEV_DIRECTORIES = ['node_modules', 'tests', 'specs', 'notes', '.idea', '.github'];
const DEV_FILES = ['.wp-env.json', '.gitignore', 'AI-LOG.md', 'NOTES.md'];

/**
 * Files in the plugin root that git ignores: local files that are never
 * tracked, so never shipped.
 *
 * @return list<string>
 */
function ignoredRootFiles(): array
{
    exec('git -C '.escapeshellarg(dirname(__DIR__, 2)).' ls-files --others --ignored --exclude-standard --directory', $lines);

    return array_values(array_filter($lines, fn (string $path): bool => ! str_contains($path, '/')));
}

it('passes Plugin Check without errors or warnings', function (): void {
    $result = wpCli([
        'plugin', 'check', 'provemark-c2pa-check',
        '--exclude-directories='.implode(',', DEV_DIRECTORIES),
        '--exclude-files='.implode(',', [...DEV_FILES, ...ignoredRootFiles()]),
        '--format=csv',
        '--fields=type,code,message',
    ]);

    $findings = preg_grep('/^(ERROR|WARNING),/m', explode("\n", $result['output']));

    expect($findings)->toBe([], $result['output'])
        ->and($result['output'])->toContain('Checks complete');
});
