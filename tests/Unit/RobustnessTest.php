<?php

declare(strict_types=1);

use Provemark\C2paCheck\Outcome;

/**
 * The file's tokens without whitespace and comments, as text.
 *
 * @return list<string>
 */
function codeTokens(string $file): array
{
    return array_values(array_map(
        fn (PhpToken $token): string => $token->text,
        array_filter(PhpToken::tokenize((string) file_get_contents($file)), fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG])),
    ));
}

it('AC5: below PHP 8.3 the main file returns before loading anything bundled', function (): void {
    $tokens = codeTokens(dirname(__DIR__, 2).'/provemark-c2pa-check.php');
    $version = array_search('version_compare', $tokens, true);
    $require = array_search('require_once', $tokens, true);
    $return = is_int($version) ? array_search('return', array_slice($tokens, $version, null, true), true) : false;

    expect($version)->toBeInt()
        ->and(implode('', array_slice($tokens, (int) $version, 8)))->toBe("version_compare(PHP_VERSION,'8.3.0','<')")
        ->and($return)->toBeInt()
        ->and($require)->toBeInt()
        ->and($version < $require && $return < $require)->toBeTrue();
})->group('SPEC-015');

it('AC9: long text and many codes are capped', function (): void {
    $entry = Outcome::bounded([
        'signer' => ['issuer' => str_repeat('i', 300), 'common_name' => str_repeat('n', 1000)],
        'signed_at' => '2026-09-27T00:00:00Z',
        'remote_manifest_url' => str_repeat('u', 2000),
        'codes' => array_map(fn (int $i): string => 'code.'.$i, range(1, 120)),
    ]);
    $signer = is_array($entry['signer'] ?? null) ? $entry['signer'] : [];
    $codes = is_array($entry['codes'] ?? null) ? $entry['codes'] : [];

    expect(mb_strlen(is_string($signer['common_name'] ?? null) ? $signer['common_name'] : ''))->toBe(256)
        ->and($signer['common_name'] ?? '')->toEndWith('…')
        ->and(mb_strlen(is_string($signer['issuer'] ?? null) ? $signer['issuer'] : ''))->toBe(256)
        ->and(mb_strlen(is_string($entry['remote_manifest_url'] ?? null) ? $entry['remote_manifest_url'] : ''))->toBe(256)
        ->and($entry['signed_at'] ?? null)->toBe('2026-09-27T00:00:00Z')
        ->and(count($codes))->toBe(50)
        ->and($codes[0] ?? null)->toBe('code.1')
        ->and($entry['codes_omitted'] ?? null)->toBe(70);
})->group('SPEC-015');

it('AC11: json_encode() only with a stated reason, and no backfill left in src/', function (): void {
    $plain = [];
    $backfill = [];
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/(?<!wp_)json_encode\(/', $line) === 1 && preg_match('/phpcs:ignore \S+json_encode_json_encode -- \S/', $line) !== 1) {
                $plain[] = basename($file);
            }
        }
        if (stripos($code, 'backfill') !== false) {
            $backfill[] = basename($file);
        }
    }

    expect($plain)->toBe([])
        ->and($backfill)->toBe([]);
})->group('SPEC-015');

it('SPEC-017 AC7: calls no private core function', function (): void {
    $calls = [];
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($file)), fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            // Core's translation functions start with an underscore and are public.
            $translation = in_array($token->text, ['__', '_e', '_n', '_x', '_ex', '_nx', '_n_noop', '_nx_noop'], true);
            if ($token->is(T_STRING) && str_starts_with($token->text, '_') && ! $translation && ($tokens[$i + 1] ?? null)?->text === '('
                && ! ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]))) {
                $calls[] = basename($file).': '.$token->text;
            }
        }
    }

    expect($calls)->toBe([]);
})->group('SPEC-017');

it('SPEC-018 AC6: invalid UTF-8 is stored scrubbed', function (): void {
    $entry = Outcome::bounded(['signer' => ['issuer' => null, 'common_name' => "Bad \xC3( name"], 'signed_at' => null, 'remote_manifest_url' => null, 'codes' => ["code.\xFF"]]);
    $signer = is_array($entry['signer'] ?? null) ? $entry['signer'] : [];
    $codes = is_array($entry['codes'] ?? null) ? $entry['codes'] : [];

    expect(mb_check_encoding(is_string($signer['common_name'] ?? null) ? $signer['common_name'] : "\xFF", 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding(is_string($codes[0] ?? null) ? $codes[0] : "\xFF", 'UTF-8'))->toBeTrue();
})->group('SPEC-018');

it('SPEC-018 AC7: the main file parses before PHP 8.1, and its notices are for administrators', function (): void {
    $file = dirname(__DIR__, 2).'/provemark-c2pa-check.php';
    $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($file)), fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
    $firstClass = 0;
    foreach ($tokens as $i => $token) {
        if ($token->is(T_ELLIPSIS) && ($tokens[$i + 1] ?? null)?->text === ')' && ($tokens[$i - 1] ?? null)?->text === '(') {
            $firstClass++;
        }
    }

    expect($firstClass)->toBe(0)
        ->and(substr_count((string) file_get_contents($file), "current_user_can('activate_plugins')"))->toBe(2);
})->group('SPEC-018');

it('SPEC-018 AC8: every phpcs:ignore in src/ states its reason', function (): void {
    $bare = [];
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
            if (str_contains($line, 'phpcs:ignore') && preg_match('/phpcs:ignore \S+ -- \S/', $line) !== 1) {
                $bare[] = basename($file).':'.($n + 1);
            }
        }
    }

    expect($bare)->toBe([]);
})->group('SPEC-018');
