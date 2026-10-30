<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\OwnedRecord;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class OwnershipListener
{
    public function __construct(private OwnerScope $scope)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if (!$entity instanceof OwnedRecord) {
                continue;
            }
            $this->assertOwner($entity);
            $metadata = $em->getClassMetadata($entity::class);
            foreach ($metadata->getAssociationNames() as $association) {
                $value = $metadata->getFieldValue($entity, $association);
                if ($value instanceof OwnedRecord) {
                    $this->assertOwner($value);
                }
            }
        }
    }

    private function assertOwner(OwnedRecord $record): void
    {
        if (null === $this->scope->getOwnerId() || $record->getOwner()->getId() !== $this->scope->getOwnerId()) {
            throw new \DomainException('Personal record access requires its trusted owner scope.');
        }
    }
}
