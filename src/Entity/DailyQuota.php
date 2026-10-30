<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyQuotaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyQuotaRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_quota_owner_day_resource', columns: ['owner_id', 'day', 'resource'])]
class DailyQuota extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $day,
        #[ORM\Column(length: 40)]
        public string $resource,
        #[ORM\Column]
        public int $consumed = 0,
        #[ORM\Column]
        public int $reserved = 0,
    ) {
        parent::__construct($owner);
    }
}
