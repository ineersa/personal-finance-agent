<?php

declare(strict_types=1);

namespace App\Finance\Sql;

final class ReadOnlyQuery
{
    private const string MAIN_DATABASE = 'main';

    // No extension loading, file I/O or application/PHP callbacks are available to SQL.
    private const array FUNCTIONS = [
        'abs', 'avg', 'char', 'coalesce', 'concat', 'concat_ws', 'count', 'date', 'datetime',
        'dense_rank', 'first_value', 'format', 'glob', 'group_concat', 'hex', 'if', 'ifnull',
        'iif', 'instr', 'json', 'json_array', 'json_array_length', 'json_extract', 'json_group_array',
        'json_group_object', 'json_object', 'json_quote', 'json_type', 'json_valid', 'julianday',
        'lag', 'last_value', 'lead', 'length', 'like', 'likelihood', 'likely', 'lower', 'ltrim',
        'max', 'min', 'nth_value', 'ntile', 'nullif', 'octet_length', 'percent_rank', 'printf',
        'quote', 'random', 'randomblob', 'rank', 'replace', 'round', 'row_number', 'rtrim',
        'sign', 'sqlite_version', 'strftime', 'string_agg', 'substr', 'substring', 'sum', 'time',
        'timediff', 'total', 'trim', 'typeof', 'unicode', 'unixepoch', 'unlikely', 'upper', 'zeroblob',
    ];

    /** @return array{columns: list<string>, rows: list<list<int|float|string|null>>, truncated: bool} */
    public function execute(string $path, string $sql): array
    {
        if (is_link($path) || is_link(\dirname($path)) || !is_file($path)) {
            throw new \RuntimeException('Tenant storage is unavailable.');
        }
        $database = new \SQLite3($path, \SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true);
        try {
            $database->busyTimeout(100);
            $database->exec('PRAGMA query_only = ON');
            $database->exec('PRAGMA temp_store = MEMORY');
            if (!$database->setAuthorizer(self::authorize(...))) {
                throw new \RuntimeException('SQL authorization is unavailable.');
            }
            // PHP's SQLite3 prepare executes the first statement only. Never execute a tail.
            $statement = $database->prepare($sql);
            if (false === $statement || !$statement->readOnly()) {
                throw new \RuntimeException('A read-only query is required.');
            }
            try {
                $result = $statement->execute();
                if (false === $result) {
                    throw new \RuntimeException('SQL execution failed.');
                }
                try {
                    $columns = [];
                    $count = $result->numColumns();
                    if ($count > SqlExecutor::MAX_COLUMNS) {
                        throw new \RuntimeException('SQL column limit reached.');
                    }
                    for ($column = 0; $column < $count; ++$column) {
                        $name = $result->columnName($column);
                        if (false === $name) {
                            throw new \RuntimeException('SQL column name is unavailable.');
                        }
                        $columns[] = $name;
                    }
                    $rows = [];
                    $bytes = \strlen(json_encode($columns, \JSON_THROW_ON_ERROR));
                    $truncated = false;
                    while (false !== $row = $result->fetchArray(\SQLITE3_NUM)) {
                        if (\count($rows) >= SqlExecutor::MAX_ROWS) {
                            $truncated = true;
                            break;
                        }
                        $bytes += \strlen(json_encode($row, \JSON_THROW_ON_ERROR));
                        if ($bytes > SqlExecutor::MAX_OUTPUT_BYTES) {
                            throw new \RuntimeException('SQL output limit reached.');
                        }
                        $values = [];
                        foreach ($row as $value) {
                            if (null !== $value && !\is_int($value) && !\is_float($value) && !\is_string($value)) {
                                throw new \RuntimeException('Unexpected SQL value.');
                            }
                            $values[] = $value;
                        }
                        $rows[] = $values;
                    }

                    return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
                } finally {
                    $result->finalize();
                }
            } finally {
                $statement->close();
            }
        } finally {
            $database->close();
        }
    }

    private static function authorize(int $action, ?string $first, ?string $second, ?string $database, ?string $origin): int
    {
        return match ($action) {
            \SQLite3::SELECT, \SQLite3::RECURSIVE => \SQLite3::OK,
            \SQLite3::READ => self::MAIN_DATABASE === $database ? \SQLite3::OK : \SQLite3::DENY,
            \SQLite3::FUNCTION => \in_array(strtolower($second ?? ''), self::FUNCTIONS, true) ? \SQLite3::OK : \SQLite3::DENY,
            default => \SQLite3::DENY,
        };
    }
}
