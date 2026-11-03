<?php

declare(strict_types=1);

namespace App\Finance\Sql;

use App\Entity\User;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Unwired draft: one instance per trusted generation owner, never a shared service.
 * There is no execution deadline yet; do not expose this to model calls.
 */
#[AsTool('query_finances', 'Query approved finance_transactions. Money is decimal text: use decimal_sum(amount), grouped by currency, never native arithmetic. Direction is not income/expense; use meaning. Transfers and card payments are neither spending nor income.')]
final class OwnerFinanceQuery
{
    private readonly \SQLite3 $database;

    /** The path and persisted owner must come from trusted application code. */
    public function __construct(string $databasePath, User $owner)
    {
        $ownerId = $owner->getId();
        if (null === $ownerId || $ownerId < 1 || \in_array('ROLE_ADMIN', $owner->getRoles(), true) || !\in_array('ROLE_USER', $owner->getRoles(), true)) {
            throw new \InvalidArgumentException('A persisted ordinary finance owner is required.');
        }

        $this->database = new \SQLite3($databasePath, \SQLITE3_OPEN_READONLY);
        $this->database->enableExceptions(true);
        $this->database->busyTimeout(1000);
        // Only the integer obtained from the trusted User enters this definition.
        $this->database->exec(<<<SQL
            CREATE TEMP VIEW finance_transactions AS
            SELECT t.id, t.transaction_date AS date, t.amount, t.currency,
                   t.direction, t.meaning, t.recurrence, t.description,
                   t.category_id, t.source_id, t.statement_id
            FROM main.financial_transaction t
            JOIN main.source s ON s.id = t.source_id
            JOIN main.statement st ON st.id = t.statement_id
            JOIN main.source ss ON ss.id = st.source_id
            WHERE t.user_id = {$ownerId} AND s.user_id = {$ownerId}
              AND st.user_id = {$ownerId} AND ss.user_id = {$ownerId}
              AND t.is_draft = 0
            SQL);
        $this->database->createAggregate('decimal_sum', static function (?string $total, int $row, ?string $amount): ?string {
            if (null === $amount) {
                return $total;
            }
            if (1 !== preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/D', $amount)) {
                throw new \UnexpectedValueException('Invalid decimal amount.');
            }
            $total ??= '0';
            $scale = static function (string $value): int {
                $point = strpos($value, '.');

                return false === $point ? 0 : \strlen($value) - $point - 1;
            };

            return bcadd($total, $amount, max($scale($total), $scale($amount)));
        }, static fn (?string $total, int $rows): ?string => $total, 1);
        if (!$this->database->setAuthorizer(self::authorize(...))) {
            throw new \RuntimeException('Cannot install finance authorization policy.');
        }
    }

    public function __destruct()
    {
        $this->database->close();
    }

    /**
     * Available columns: id, date (statement date), amount (original decimal text),
     * currency, direction, meaning, recurrence, description, category_id, source_id,
     * statement_id. A source is an account/card, not a merchant. Unknown remains unknown.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function __invoke(string $sql): array
    {
        if (\strlen($sql) > 8000) {
            throw new \InvalidArgumentException('Finance query exceeds the draft input limit.');
        }
        try {
            // SQLite prepares only the first statement; no exec() of untrusted SQL.
            $statement = $this->database->prepare($sql);
            if (false === $statement) {
                throw new \RuntimeException('Cannot prepare finance query.');
            }
            try {
                $result = $statement->execute();
                if (false === $result) {
                    throw new \RuntimeException('Cannot execute finance query.');
                }
                try {
                    $rows = [];
                    $bytes = 2;
                    while (false !== ($row = $result->fetchArray(\SQLITE3_ASSOC))) {
                        $bytes += \strlen(json_encode($row, \JSON_THROW_ON_ERROR)) + 1;
                        if (\count($rows) >= 200 || $bytes > 64000) {
                            throw new \RuntimeException('Finance result exceeds the draft output limit.');
                        }
                        $rows[] = $row;
                    }

                    return $rows;
                } finally {
                    $result->finalize();
                }
            } finally {
                $statement->close();
            }
        } catch (\Exception) {
            // Do not disclose base schema, SQL or database diagnostics to a model.
            throw new \RuntimeException('Finance query failed or exceeded its result limit.');
        }
    }

    private static function authorize(int $action, ?string $first, ?string $second, ?string $database, ?string $origin): int
    {
        if (\SQLite3::SELECT === $action) {
            return \SQLite3::OK;
        }
        if (\SQLite3::FUNCTION === $action && \in_array($second, ['count', 'decimal_sum', 'substr'], true)) {
            return \SQLite3::OK;
        }
        if (\SQLite3::READ === $action) {
            if ('temp' === $database && 'finance_transactions' === $first) {
                return \SQLite3::OK;
            }
            $columns = match ($first) {
                'financial_transaction' => ['id', 'transaction_date', 'amount', 'currency', 'direction', 'meaning', 'recurrence', 'description', 'category_id', 'source_id', 'statement_id', 'user_id', 'is_draft'],
                'source' => ['id', 'user_id'],
                'statement' => ['id', 'source_id', 'user_id'],
                default => [],
            };
            if ('main' === $database && 'finance_transactions' === $origin && \in_array($second, $columns, true)) {
                return \SQLite3::OK;
            }
        }

        return \SQLite3::DENY;
    }
}
