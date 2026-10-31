<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends AbstractCrudController<Category> */
#[IsGranted('ROLE_ADMIN')]
final class CategoryCrudController extends AbstractCrudController
{
    use RestrictReferencedDeletionTrait;

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function createEntity(string $entityFqcn): Category
    {
        return new Category('');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN')->setEntityLabelInPlural('Categories')->setSearchFields(['name', 'description'])->setDefaultSort(['name' => 'ASC'])->setPaginatorPageSize(50);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield TextareaField::new('description');
        yield TextField::new('icon')->setHelp('Font Awesome classes, for example: fa fa-house.')->setTemplatePath('admin/field/category_icon.html.twig');
    }
}
