<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Source;
use App\Entity\User;
use App\Repository\SourceRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends OwnedCrudController<Source> */
final class SourceCrudController extends OwnedCrudController
{
    public function __construct(private readonly SourceRepository $sources)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Source::class;
    }

    public function createEntity(string $entityFqcn): Source
    {
        return new Source($this->owner(), '');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield TextareaField::new('description');
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->sources->queryForUser($user);
    }
}
