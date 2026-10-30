<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 255)]
        public string $name,
    ) {
        parent::__construct($owner);
    }
}
