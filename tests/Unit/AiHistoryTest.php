<?php

declare(strict_types=1);

use Tracefern\ImageCheck\Outcome;

const TRAINED = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
const COMPOSITE = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

/**
 * A report in toArray()'s shape. Each manifest is [source types of its
 * actions, ingredients]; an ingredient is [relationship, manifest label,
 * recorded failure codes, the verifier's delta failure codes]. A null
 * recorded list leaves validation_results out, a null delta list leaves
 * the delta out; the delta defaults to the recorded list.
 *
 * @param  array<string, array{list<string>, list<array<int, mixed>>}>  $manifests
 * @return array<string, mixed>
 */
function historyReport(array $manifests, string $active = 'A'): array
{
    $out = [];
    $deltas = [];
    $failures = fn (array $codes): array => array_map(fn (mixed $code): array => ['code' => $code, 'url' => '', 'explanation' => ''], $codes);
    foreach ($manifests as $label => [$types, $ingredients]) {
        $entries = [];
        foreach ($ingredients as $i => $ingredient) {
            $relationship = $ingredient[0];
            $target = $ingredient[1];
            $recorded = $ingredient[2];
            $delta = array_key_exists(3, $ingredient) ? $ingredient[3] : $recorded;
            $ingredientLabel = 'c2pa.ingredient.v3'.($i === 0 ? '' : '__'.$i);
            $entry = ['relationship' => $relationship, 'active_manifest' => $target, 'label' => $ingredientLabel];
            if (is_array($recorded)) {
                $entry['validation_results'] = ['activeManifest' => ['success' => [], 'informational' => [], 'failure' => $failures($recorded)]];
            }
            if (is_array($delta)) {
                $deltas[] = [
                    'ingredientAssertionURI' => "self#jumbf=/c2pa/{$label}/c2pa.assertions/{$ingredientLabel}",
                    'validationDeltas' => ['success' => [], 'informational' => [], 'failure' => $failures($delta)],
                ];
            }
            $entries[] = $entry;
        }
        $out[$label] = [
            'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => array_map(
                fn (string $type): array => ['action' => 'c2pa.edited', 'digitalSourceType' => $type],
                $types,
            )]]],
            'ingredients' => $entries,
        ];
    }

    return ['active_manifest' => $active, 'manifests' => $out, 'validation_results' => ['activeManifest' => [], 'ingredientDeltas' => $deltas]];
}

/**
 * AC1's chain, A → B → C, with the given failure lists on both ingredients,
 * and on the first one a verifier's delta that differs when given.
 *
 * @param  ?list<string>  $first
 * @param  ?list<string>  $second
 * @param  false|list<string>|null  $firstDelta  false: the same as $first
 * @return array<string, mixed>
 */
function parentChain(?array $first = [], ?array $second = [], false|array|null $firstDelta = false): array
{
    return historyReport([
        'A' => [[], [$firstDelta === false ? ['parentOf', 'B', $first] : ['parentOf', 'B', $first, $firstDelta]]],
        'B' => [[], [['parentOf', 'C', $second]]],
        'C' => [[TRAINED], []],
    ]);
}

it('AC1: finds AI origin down a parent chain', function (): void {
    expect(Outcome::aiHistory(parentChain()))->toBe([true, false]);
})->group('SPEC-027');

it('AC1: finds it also when the same manifest is first reached as a component', function (): void {
    $report = historyReport([
        'A' => [[], [['componentOf', 'C', []], ['parentOf', 'B', []]]],
        'B' => [[], [['parentOf', 'C', []]]],
        'C' => [[TRAINED], []],
    ]);

    expect(Outcome::aiHistory($report))->toBe([true, false]);
})->group('SPEC-027');

it('AC2: finds an AI edit in the active manifest or a parent', function (): void {
    $inActive = historyReport(['A' => [[COMPOSITE], []]]);
    $inParent = historyReport(['A' => [[], [['parentOf', 'B', []]]], 'B' => [[COMPOSITE], []]]);

    expect(Outcome::aiHistory($inActive))->toBe([false, true])
        ->and(Outcome::aiHistory($inParent))->toBe([false, true]);
})->group('SPEC-027');

it('AC3: counts AI as a component or an input as an edit', function (string $relationship): void {
    $report = historyReport(['A' => [[], [[$relationship, 'B', []]]], 'B' => [[TRAINED], []]]);

    expect(Outcome::aiHistory($report))->toBe([false, true]);
})->with(['componentOf', 'inputTo'])->group('SPEC-027');

it('AC4: does not follow a failing ingredient', function (string $case): void {
    $report = match ($case) {
        'hash mismatch' => parentChain(['assertion.dataHash.mismatch']),
        'untrusted and a signature mismatch' => parentChain(['signingCredential.untrusted', 'claimSignature.mismatch']),
        'no validation results' => parentChain(null, [], []),
        'recorded clean, verifier found a mismatch' => parentChain([], [], ['claimSignature.mismatch']),
        default => parentChain([], [], null),
    };

    expect(Outcome::aiHistory($report))->toBe([false, false]);
})->with([
    'hash mismatch', 'untrusted and a signature mismatch', 'no validation results',
    'recorded clean, verifier found a mismatch', 'no delta from the verifier',
])->group('SPEC-027');

it('AC5: follows an ingredient whose only failure is an unknown signer', function (): void {
    $untrusted = ['signingCredential.untrusted'];

    expect(Outcome::aiHistory(parentChain($untrusted, $untrusted)))->toBe([true, false]);
})->group('SPEC-027');

it('AC6: ends quietly on malformed and hostile histories', function (array $report): void {
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });
    try {
        expect(Outcome::aiHistory($report))->toBe([false, false]);
    } finally {
        restore_error_handler();
    }
})->with([
    'unknown label' => [historyReport(['A' => [[], [['parentOf', 'Z', []]]]])],
    'cycle' => [historyReport(['A' => [[], [['parentOf', 'B', []]]], 'B' => [[], [['parentOf', 'A', []]]]])],
    'ingredients a string' => [['active_manifest' => 'A', 'manifests' => ['A' => ['assertions' => [], 'ingredients' => 'lots']]]],
    'ingredient a number' => [['active_manifest' => 'A', 'manifests' => ['A' => ['assertions' => [], 'ingredients' => [42]]]]],
    'relationship missing' => [historyReport(['A' => [[], [[null, 'B', []]]], 'B' => [[TRAINED], []]])],
    'relationship an integer' => [historyReport(['A' => [[], [[1, 'B', []]]], 'B' => [[TRAINED], []]])],
    'failure a string' => [['active_manifest' => 'A', 'manifests' => [
        'A' => ['ingredients' => [['relationship' => 'parentOf', 'active_manifest' => 'B', 'validation_results' => ['activeManifest' => ['failure' => 'none']]]]],
        'B' => ['assertions' => [['label' => 'c2pa.actions', 'data' => ['actions' => [['digitalSourceType' => TRAINED]]]]]],
    ]]],
    'no manifests' => [['active_manifest' => 'A', 'manifests' => 'none']],
    'empty' => [[]],
])->group('SPEC-027');

it('AC6: reads each manifest at most once', function (): void {
    // A diamond repeated ten levels deep: 2^10 paths, 21 manifests.
    $manifests = [];
    for ($level = 0; $level < 10; $level++) {
        foreach (["L{$level}a", "L{$level}b"] as $label) {
            $manifests[$label] = [[], [['parentOf', 'L'.($level + 1).'a', []], ['parentOf', 'L'.($level + 1).'b', []]]];
        }
    }
    $manifests['A'] = [[], [['parentOf', 'L0a', []], ['parentOf', 'L0b', []]]];
    $manifests['L10a'] = [[], []];
    $manifests['L10b'] = [[TRAINED], []];

    $started = hrtime(true);
    expect(Outcome::aiHistory(historyReport($manifests)))->toBe([true, false])
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5);
})->group('SPEC-027');
