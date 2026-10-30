<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SourceRepository::class)]
class Source extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 255)]
        public string $name,
        #[ORM\Column(length: 40)]
        public string $kind,
    ) {
        parent::__construct($owner);
    }
}
