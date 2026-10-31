<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractCrudController<User> */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    use RestrictReferencedDeletionTrait;

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN')->setEntityLabelInPlural('Users')->setSearchFields(['email'])->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield ChoiceField::new('roles')->setChoices(['User' => 'ROLE_USER', 'Administrator' => 'ROLE_ADMIN'])->allowMultipleChoices();
        yield TextField::new('plainPassword', 'New password')->setFormType(PasswordType::class)->onlyOnForms()
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('hash_property_path', 'password')
            ->setFormTypeOption('constraints', [
                ...(Crud::PAGE_NEW === $pageName ? [new Assert\NotBlank()] : []),
                new Assert\Length(min: 12),
                new Assert\Length(max: 4096, maxMessage: 'This value is too long. It should have {{ limit }} bytes or less.', countUnit: Assert\Length::COUNT_BYTES),
            ]);
    }
}
