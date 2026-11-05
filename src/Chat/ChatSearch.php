<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final readonly class ChatSearch
{
    public const string QUERY_PARAMETER = 'q';
    public const int RESULT_LIMIT = 20;
    public const int QUERY_LENGTH = 200;
    private const int SNIPPET_LENGTH = 240;

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, label: string, snippet: string}> */
    public function find(User $owner, string $query): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}_]+/u', mb_substr($query, 0, self::QUERY_LENGTH), $matches);
        if ([] === $matches[0]) {
            return [];
        }
        // Only literal Unicode words, never user-controlled FTS operators or column selectors.
        $literal = implode(' AND ', array_map(static fn (string $word): string => '"'.$word.'"', $matches[0]));
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id, chat_search.title AS label, snippet(chat_search, 2, '', '', ' … ', 36) AS snippet
            FROM chat_search JOIN chat_conversation c ON c.id = chat_search.conversation_id
            WHERE chat_search MATCH ? AND c.user_id = ?
            ORDER BY rank, c.id DESC LIMIT ?
            SQL, [$literal, $owner->getId(), self::RESULT_LIMIT], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'label' => mb_substr((string) $row['label'], 0, self::SNIPPET_LENGTH), 'snippet' => mb_substr((string) $row['snippet'], 0, self::SNIPPET_LENGTH)], $rows);
    }
}
