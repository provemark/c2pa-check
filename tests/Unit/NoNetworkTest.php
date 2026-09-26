<?php

declare(strict_types=1);

// AC10 says nothing is fetched. The verifier never fetches (its SPEC-013);
// this keeps the plugin's own code from doing it either. Pest's arch
// toUse() did not see a namespaced call to a global function (measured), so
// this reads the tokens of every source file instead.
const NETWORK_FUNCTIONS = [
    'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request',
    'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request',
    'download_url', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client',
];

/**
 * Global functions called in a PHP file: a name followed by "(", not a
 * method, static call, declaration or `new`.
 *
 * @return list<string>
 */
function calledFunctions(string $file): array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($file)),
        fn (array|string $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $calls = [];
    foreach ($tokens as $i => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        $before = $tokens[$i - 1] ?? null;
        if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }
        $calls[] = strtolower(ltrim($token[1], '\\'));
    }

    return $calls;
}

it('AC10: makes no network calls in the plugin\'s own code', function (): void {
    $files = [...(glob(dirname(__DIR__, 2).'/src/*.php') ?: []), dirname(__DIR__, 2).'/provemark-c2pa-check.php'];
    $found = [];
    foreach ($files as $file) {
        foreach (array_intersect(calledFunctions($file), NETWORK_FUNCTIONS) as $call) {
            $found[] = basename($file).': '.$call;
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($found)->toBe([]);
})->group('SPEC-001');
