<?php

declare(strict_types=1);

namespace App\Finance\Sql;

use App\Entity\User;
use App\Finance\TenantManagers;

final class ReadOnlyQuery
{
    public const int MAX_SQL_BYTES = 16384;
    public const int MAX_OUTPUT_BYTES = 262144;
    public const int MAX_ROWS = 200;
    public const int MAX_COLUMNS = 50;
    private const string MAIN_DATABASE = 'main';
    private const array EXTERNAL_FUNCTIONS = ['load_extension', 'readfile', 'writefile'];

    public function __construct(private readonly TenantManagers $tenants)
    {
    }

    /** @return array{columns: list<string>, rows: list<list<int|float|string|null>>, truncated: bool} */
    public function execute(User $owner, string $sql): array
    {
        if ('' === trim($sql) || \strlen($sql) > self::MAX_SQL_BYTES) {
            throw new \RuntimeException('Provide a nonempty SQL query of at most 16384 bytes.');
        }
        $path = $this->tenants->path($owner);
        if (is_link($path) || is_link(\dirname($path)) || !is_file($path)) {
            throw new \RuntimeException('Tenant storage is unavailable.');
        }
        try {
            $database = new \SQLite3($path, \SQLITE3_OPEN_READONLY);
        } catch (\Exception) {
            // Opening storage can include the private filename; query diagnostics below must remain useful.
            throw new \RuntimeException('Tenant storage could not be opened read-only.');
        }
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
                    if ($count > self::MAX_COLUMNS) {
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
                        if (\count($rows) >= self::MAX_ROWS) {
                            $truncated = true;
                            break;
                        }
                        $bytes += \strlen(json_encode($row, \JSON_THROW_ON_ERROR));
                        if ($bytes > self::MAX_OUTPUT_BYTES) {
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
            // This fresh connection has no application callbacks or loaded extensions.
            \SQLite3::FUNCTION => !\in_array(strtolower($second ?? ''), self::EXTERNAL_FUNCTIONS, true) ? \SQLite3::OK : \SQLite3::DENY,
            default => \SQLite3::DENY,
        };
    }
}
