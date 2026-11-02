<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Direction;
use App\Repository\SourceRepository;
use App\Repository\TransactionRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends OwnedCrudController<Transaction> */
#[IsGranted(FinanceRecordVoter::ACCESS)]
final class TransactionCrudController extends OwnedCrudController
{
    public function __construct(private readonly TransactionRepository $transactions, private readonly SourceRepository $sources)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setFormOptions(['validation_groups' => ['Default', 'approved']]);
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action->displayIf(static fn (Transaction $transaction): bool => $transaction->isDraft))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action): Action => $action->displayIf(static fn (Transaction $transaction): bool => $transaction->isDraft))
            ->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('statement')->onlyOnDetail();
        yield DateField::new('transactionDate')->setRequired(true);
        // Never use MoneyField or NumberField: they coerce canonical decimal strings.
        yield TextField::new('amount')->setRequired(true)->setTemplatePath('admin/field/money.html.twig');
        yield TextField::new('currency')->setRequired(true);
        yield ChoiceField::new('direction')->setChoices(['Credit' => Direction::Credit, 'Debit' => Direction::Debit])->setRequired(true);
        yield TextareaField::new('description')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield ChoiceField::new('meaning')->onlyOnDetail();
        yield ChoiceField::new('recurrence')->onlyOnDetail();
        yield AssociationField::new('source')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('empty_data', static fn (FormInterface $form): never => throw new TransformationFailedException('A transaction source is required.'))
            ->setFormTypeOption('query_builder', fn (): QueryBuilder => $this->sources->queryForUser($this->owner()));
        yield AssociationField::new('category')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setRequired(false);
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        $transaction = $context->getEntity()->getInstance();
        $response = parent::delete($context);
        if ($transaction instanceof Transaction && $transaction->isDraft) {
            return $this->redirectToRoute('admin_statement_processing', ['id' => $transaction->statement->getId()]);
        }

        return $response;
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof Transaction || !$entityInstance->isDraft) {
            throw $this->createAccessDeniedException('Only unapproved drafts can be deleted.');
        }
        $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->transactions->queryForUser($user)->andWhere('record.isDraft = false');
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        $transaction = $context->getEntity()->getInstance();
        if ($transaction instanceof Transaction && $transaction->isDraft) {
            return $this->redirectToRoute('admin_statement_processing', ['id' => $transaction->statement->getId()]);
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
