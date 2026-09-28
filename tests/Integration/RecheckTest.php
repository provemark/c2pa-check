<?php

declare(strict_types=1);

afterEach(fn () => resetTrustOptions());

/**
 * Runs `wp tracefern check` with $args in the test environment.
 *
 * @param  list<string>  $args
 * @return array{exit: int, output: string}
 */
function recheck(array $args): array
{
    return wpCli(['tracefern', 'check', ...$args]);
}

/**
 * The rows of `--format=json`: the one output line that is a JSON list.
 *
 * @param  list<string>  $args
 * @return list<array<mixed>>
 */
function recheckJson(array $args): array
{
    $result = recheck([...$args, '--format=json']);
    foreach (explode("\n", $result['output']) as $line) {
        if (str_starts_with($line, '[')) {
            $rows = json_decode($line, true);

            return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
        }
    }

    return [];
}

/**
 * @param  list<array<mixed>>  $rows
 * @return list<int>
 */
function rowIds(array $rows): array
{
    return array_map(fn (array $row): int => is_int($row['id'] ?? null) ? $row['id'] : 0, $rows);
}

it('AC1: checks again with the current trust settings', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    expect(storedEntry($id)['state'] ?? null)->toBe('Valid');

    setOption('tracefern_custom_trust', customSettingsJson());
    $result = recheck([(string) $id]);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and(storedEntry($id)['state'] ?? null)->toBe('Trusted')
        ->and(storedEntry($id)['trust'] ?? null)->toBe('custom')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry(fixturePath('fixture-signed.jpg'), customSettingsFile()))
        ->and(indexOf($id)['state'])->toBe('Trusted');
})->group('SPEC-008');

it('AC2: checks the original of a large image, not its -scaled copy', function (): void {
    $path = fixturePath('google-20250919-pixel10-npld-picnic-table.jpg');
    $id = importMedia($path);
    wpEval("delete_post_meta($id, '_tracefern_result');");

    recheck([(string) $id]);

    expect(wpEval("echo basename(get_attached_file($id));"))->toEndWith('-scaled.jpg')
        ->and(stable((array) storedEntry($id)))->toBe(expectedEntry($path, defaultSettingsFile()))
        ->and(storedEntry($id)['state'] ?? null)->toBe('Trusted');
})->group('SPEC-008');

it('AC3: checks exactly the chosen images', function (): void {
    $valid = importMedia(fixturePath('fixture-signed.jpg'));
    $none = importMedia(fixturePath('fixture-unsigned.jpg'));
    $invalid = attachmentWithEntry(sampleEntry(['state' => 'Invalid', 'codes' => ['assertion.dataHash.mismatch']]));
    $error = attachmentWithEntry(sampleEntry(['state' => 'error', 'signer' => null, 'format' => null, 'reason' => 'exception']));
    $unchecked = attachmentWithEntry(null);
    $pdf = (int) wpEval("echo wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'a PDF', 'post_status' => 'inherit'], '/nonexistent.pdf');");

    // --all last: it checks the never-checked image too.
    $uncheckedRows = recheckJson(['--unchecked']);
    $stateRows = recheckJson(['--state=Invalid,error']);
    $twoRows = recheckJson([(string) $valid, (string) $none]);
    $all = recheckJson(['--all']);

    $before = function (array $rows): array {
        $seen = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $seen[] = json_encode(array_key_exists('before', $r) ? $r['before'] : 'missing') ?: '';
            }
        }

        return array_values(array_unique($seen));
    };

    expect(rowIds($all))->toContain($valid, $none, $invalid, $error, $unchecked);
    expect(in_array($pdf, rowIds($all), true))->toBeFalse();
    expect($before($uncheckedRows))->toBe(['null']);
    expect(rowIds($uncheckedRows))->toContain($unchecked);
    expect(in_array($valid, rowIds($uncheckedRows), true))->toBeFalse();
    expect(rowIds($stateRows))->toContain($invalid, $error);
    expect(in_array($valid, rowIds($stateRows), true))->toBeFalse();
    expect(array_diff($before($stateRows), ['"Invalid"', '"error"']))->toBe([]);
    expect(rowIds($twoRows))->toBe([$valid, $none]);
})->group('SPEC-008');

it('AC4: refuses no selection, two selections, or an unknown state', function (string $case): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    $before = storedEntry($id);

    $result = recheck(match ($case) {
        'nothing' => [],
        'IDs and --all' => [(string) $id, '--all'],
        default => ['--state=Maybe'],
    });

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('--all')->toContain('--unchecked')->toContain('--state')
        ->and(storedEntry($id))->toBe($before);
})->with(['nothing', 'IDs and --all', 'an unknown state'])->group('SPEC-008');

it('AC5: skips IDs it cannot check, and checks the rest', function (): void {
    $image = importMedia(fixturePath('fixture-signed.jpg'));
    $pdf = (int) wpEval("echo wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'a PDF', 'post_status' => 'inherit'], '/nonexistent.pdf');");

    $result = recheck(['99999999', (string) $pdf, (string) $image]);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('Warning')->toContain('99999999')->toContain((string) $pdf)
        ->and(rowIds(recheckJson(['99999999', (string) $pdf, (string) $image])))->toBe([$image]);
})->group('SPEC-008');

it('AC6: stores error / unreadable when the original is gone, and goes on', function (): void {
    $gone = importMedia(fixturePath('fixture-signed.jpg'));
    $other = importMedia(fixturePath('fixture-signed.png'));
    wpEval("unlink(wp_get_original_image_path($gone));");

    recheck([(string) $gone, (string) $other]);

    expect(storedEntry($gone)['state'] ?? null)->toBe('error')
        ->and(storedEntry($gone)['reason'] ?? null)->toBe('unreadable')
        ->and(storedEntry($other)['state'] ?? null)->toBe('Valid');
})->group('SPEC-008');

it('AC7: changes nothing with --dry-run', function (): void {
    $id = importMedia(fixturePath('fixture-signed.jpg'));
    setOption('tracefern_custom_trust', customSettingsJson());
    $before = [storedEntry($id), indexOf($id)];

    $result = recheck([(string) $id, '--dry-run']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain((string) $id)->toContain('Valid')
        ->and([storedEntry($id), indexOf($id)])->toBe($before);
})->group('SPEC-008');

it('AC8: prints a table and a summary, or JSON equal to what it stored', function (): void {
    $ids = [importMedia(fixturePath('fixture-signed.jpg')), importMedia(fixturePath('fixture-unsigned.png')), importMedia(fixturePath('openai-20260826-c2pa_2x.png'))];
    $args = array_map('strval', $ids);

    $table = recheck($args);
    $lines = array_values(array_filter(explode("\n", $table['output'])));

    expect($table['output'])->toContain('before')->toContain('after')
        ->and(end($lines))->toContain('Checked 3:')->toContain('1 Trusted')->toContain('1 Valid')->toContain('1 none');

    $rows = recheckJson($args);
    expect(rowIds($rows))->toBe($ids);
    foreach ($rows as $row) {
        expect($row['after'] ?? null)->toBe(storedEntry(is_int($row['id'] ?? null) ? $row['id'] : 0)['state'] ?? 'missing')
            ->and(array_keys($row))->toBe(['id', 'file', 'before', 'after']);
    }
})->group('SPEC-008');
