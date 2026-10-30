<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSettingsRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_settings_owner', columns: ['owner_id'])]
class UserSettings extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 3)]
        public string $reportingCurrency = 'USD',
    ) {
        parent::__construct($owner);
    }
}
