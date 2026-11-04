<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Direction;
use App\Finance\Entity\Transaction;
use App\Repository\SourceRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
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
    public function __construct(private readonly SourceRepository $sources)
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
        return parent::configureActions($actions)->disable(Action::NEW);
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
        yield ChoiceField::new('meaning')->hideOnIndex();
        yield ChoiceField::new('recurrence')->hideOnIndex();
        yield AssociationField::new('source')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('empty_data', static fn (FormInterface $form): never => throw new TransformationFailedException('A transaction source is required.'))
            ->setFormTypeOption('query_builder', fn (): QueryBuilder => $this->sources->query());
        yield AssociationField::new('category')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setRequired(false);
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        $transaction = $context->getEntity()->getInstance();
        $response = parent::delete($context);
        // Doctrine clears the generated ID after deletion. Preserve unsuccessful
        // native responses instead of redirecting merely because the row is a draft.
        if ($response instanceof RedirectResponse && $transaction instanceof Transaction && $transaction->isDraft && null === $transaction->getId()) {
            return $this->redirectToRoute('admin_statement_processing', ['id' => $transaction->statement->getId()]);
        }

        return $response;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)->andWhere('entity.isDraft = false');
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        $transaction = $context->getEntity()->getInstance();
        $submitButton = $context->getRequest()->request->all()['ea']['newForm']['btn'] ?? null;
        if (Action::SAVE_AND_RETURN === $submitButton && $transaction instanceof Transaction && $transaction->isDraft) {
            return $this->redirectToRoute('admin_statement_processing', ['id' => $transaction->statement->getId()]);
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
