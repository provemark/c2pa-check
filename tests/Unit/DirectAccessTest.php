<?php

declare(strict_types=1);

it('AC3: every file in src/ refuses direct access', function (string $file): void {
    $tokens = array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents($file)),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $texts = array_map(fn (PhpToken $token): string => $token->text, $tokens);
    $namespace = array_search('namespace', $texts, true);
    $end = is_int($namespace) ? array_search(';', array_slice($texts, $namespace, null, true), true) : false;

    expect($end)->toBeInt()
        ->and(implode('', array_slice($texts, (int) $end + 1, 12)))->toBe("if(!defined('ABSPATH')){exit;}");
})->with(fn (): array => glob(dirname(__DIR__, 2).'/src/*.php') ?: [])->group('SPEC-012');
