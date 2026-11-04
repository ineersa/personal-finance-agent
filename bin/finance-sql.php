<?php

declare(strict_types=1);

use App\Finance\Sql\ReadOnlyQuery;
use App\Finance\Sql\SqlExecutor;

require \dirname(__DIR__).'/vendor/autoload.php';

// Internal one-shot executor, not a console endpoint or an OS security sandbox.
try {
    if (!\function_exists('posix_setrlimit') || !\defined('POSIX_RLIMIT_AS') || !\defined('POSIX_RLIMIT_CPU')
        || !posix_setrlimit(\POSIX_RLIMIT_AS, SqlExecutor::ADDRESS_SPACE_BYTES, SqlExecutor::ADDRESS_SPACE_BYTES)
        || !posix_setrlimit(\POSIX_RLIMIT_CPU, SqlExecutor::CPU_SECONDS, SqlExecutor::CPU_SECONDS)) {
        throw new \RuntimeException('SQL resource limits are unavailable.');
    }
    $input = stream_get_contents(\STDIN, SqlExecutor::MAX_INPUT_BYTES + 1);
    if (false === $input || \strlen($input) > SqlExecutor::MAX_INPUT_BYTES) {
        throw new \RuntimeException('SQL input limit reached.');
    }
    $request = json_decode($input, true, flags: \JSON_THROW_ON_ERROR);
    if (!\is_array($request) || !\is_string($request['path'] ?? null) || !\is_string($request['sql'] ?? null)
        || \strlen($request['sql']) > SqlExecutor::MAX_SQL_BYTES) {
        throw new \RuntimeException('Invalid SQL request.');
    }
    $output = json_encode((new ReadOnlyQuery())->execute($request['path'], $request['sql']), \JSON_THROW_ON_ERROR);
    if (\strlen($output) > SqlExecutor::MAX_OUTPUT_BYTES) {
        throw new \RuntimeException('SQL output limit reached.');
    }
    fwrite(\STDOUT, $output);
} catch (\Throwable) {
    // Parent returns a fixed error; exception chains and raw SQL/data never enter logs.
    exit(1);
}
