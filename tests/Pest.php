<?php

declare(strict_types=1);

/**
 * Runs a WP-CLI command in the wp-env "cli" container.
 *
 * @param  list<string>  $args  WP-CLI arguments, each passed as one shell argument
 * @return array{exit: int, output: string}
 */
function wpCli(array $args): array
{
    $command = escapeshellarg(dirname(__DIR__).'/node_modules/.bin/wp-env')
        .' run cli wp '
        .implode(' ', array_map(escapeshellarg(...), $args))
        .' 2>&1';

    exec($command, $lines, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $lines)];
}
