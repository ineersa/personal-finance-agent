<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;

/**
 * @template TEntity of \App\Entity\UserRecord
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class OwnedCrudController extends AbstractCrudController
{
    use RestrictReferencedDeletionTrait;

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(FinanceRecordVoter::ACCESS);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $owned = $this->ownedQuery($this->owner())->select('record.id');
        $query = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $query->andWhere('entity.id IN ('.$owned->getDQL().')');
        foreach ($owned->getParameters() as $parameter) {
            $query->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }

        return $query;
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        // Recheck the bound object and its associations, not just the initial URL.
        $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    abstract protected function ownedQuery(User $user): QueryBuilder;

    protected function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
