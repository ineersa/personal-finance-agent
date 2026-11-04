<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * @template TEntity of \App\Finance\Entity\FinanceRecord
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class OwnedCrudController extends AbstractCrudController
{
    use RestrictReferencedDeletionTrait;

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(FinanceRecordVoter::ACCESS)->setDefaultSort(['id' => 'DESC'])->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
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
}
