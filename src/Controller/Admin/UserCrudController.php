<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Finance\ReportingCurrencies;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractCrudController<User> */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    use RestrictReferencedDeletionTrait;

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield ChoiceField::new('roles')->setChoices(['User' => 'ROLE_USER', 'Administrator' => 'ROLE_ADMIN'])->allowMultipleChoices();
        yield ChoiceField::new('reportingCurrency')->setChoices(ReportingCurrencies::choices());
        yield TextField::new('plainPassword', 'New password')->setFormType(PasswordType::class)->onlyOnForms()
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setFormTypeOption('constraints', Crud::PAGE_NEW === $pageName ? [new Assert\NotBlank(), new Assert\Length(min: 12, max: 4096)] : [new Assert\Length(min: 12, max: 4096)]);
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        if (null !== $user->plainPassword && '' !== $user->plainPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $user->plainPassword));
        }
        $user->plainPassword = null;
    }
}
