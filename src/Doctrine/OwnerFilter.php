<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\OwnedRecord;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class OwnerFilter extends SQLFilter
{
    /** @param ClassMetadata<object> $targetEntity */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_a($targetEntity->getName(), OwnedRecord::class, true)) {
            return '';
        }

        if (!$this->hasParameter('owner_id') || '0' === $this->getParameter('owner_id')) {
            return '1 = 0';
        }

        return $targetTableAlias.'.owner_id = '.$this->getParameter('owner_id');
    }
}
