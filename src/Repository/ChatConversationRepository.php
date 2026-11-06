<?php

declare(strict_types=1);

namespace App\Repository;

use App\Chat\ChatSearch;
use App\Entity\ChatConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ChatConversation> */
final class ChatConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatConversation::class);
    }

    public function owned(User $owner, string $id): ?ChatConversation
    {
        return $this->findOneBy(['user' => $owner, 'id' => $id]);
    }

    /** @return array{id: string, label: string, favourite: bool}|null */
    public function navigationSelection(User $owner, string $id): ?array
    {
        return $this->createQueryBuilder('c')->select('c.id, c.label, c.favourite')
            ->where('c.user = :owner AND c.id = :id')->setParameter('owner', $owner)->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);
    }

    public function save(ChatConversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
        $this->getEntityManager()->flush();
    }

    public function remove(ChatConversation $conversation): void
    {
        $this->getEntityManager()->remove($conversation);
        $this->getEntityManager()->flush();
    }

    public function attachChart(ChatConversation $conversation, string $message, string $file): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($conversation, $message, $file): void {
            $this->getEntityManager()->refresh($conversation);
            $conversation->charts[] = ['message' => $message, 'file' => $file];
        });
    }

    /** @return list<array{id: string, label: string, favourite: bool}> */
    public function navigation(User $owner, bool $favourite, string $before, int $limit): array
    {
        $query = $this->createQueryBuilder('c')
            ->select('c.id, c.label, c.favourite')
            ->where('c.user = :owner AND c.favourite = :favourite')
            ->setParameter('owner', $owner)->setParameter('favourite', $favourite)
            ->orderBy('c.id', 'DESC')->setMaxResults($limit);
        if ('' !== $before) {
            $query->andWhere('c.id < :before')->setParameter('before', $before);
        }

        return $query->getQuery()->getArrayResult();
    }

    /** @return list<array{id: string, label: string, snippet: string}> */
    public function search(User $owner, string $query): array
    {
        if ('' === trim($query)) {
            return [];
        }
        try {
            // SQLite owns the FTS grammar. Binding protects SQL structure, not FTS syntax validity.
            $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
                SELECT c.id, c.label, snippet(chat_search, 2, '', '', ' … ', 36) AS snippet
                FROM chat_search JOIN chat_metadata c ON c.id = chat_search.conversation_id
                WHERE chat_search MATCH ? AND c.user_id = ?
                ORDER BY rank, c.id DESC LIMIT ?
                SQL, [$query, $owner->getId(), ChatSearch::RESULT_LIMIT], [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]);
        } catch (DriverException $exception) {
            // Native FTS query errors only; missing tables/storage failures remain real application failures.
            $message = $exception->getPrevious()?->getMessage() ?? '';
            if (str_contains($message, 'fts5:') || str_contains($message, 'unterminated string') || str_contains($message, 'no such column:') || str_contains($message, 'malformed MATCH')) {
                throw new \DomainException('Check the search expression. Use valid SQLite full-text search syntax.', previous: $exception);
            }
            throw $exception;
        }

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'label' => (string) $row['label'], 'snippet' => (string) $row['snippet']], $rows);
    }
}
