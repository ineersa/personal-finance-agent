<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Direction;
use App\Finance\ReportingCurrencies;
use App\Repository\SourceRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends OwnedCrudController<Transaction> */
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
        return parent::configureActions($actions)->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('transactionDate')->setRequired(true);
        // Never use MoneyField or NumberField: they coerce canonical decimal strings.
        yield TextField::new('amount')->setRequired(true);
        yield ChoiceField::new('currency')->setChoices(ReportingCurrencies::choices())->setRequired(true);
        yield ChoiceField::new('direction')->setChoices(['Credit' => Direction::Credit, 'Debit' => Direction::Debit])->setRequired(true);
        yield TextareaField::new('description');
        yield AssociationField::new('source')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('query_builder', fn (): QueryBuilder => $this->sources->queryForUser($this->owner()))
            ->formatValue(static fn (mixed $value, Transaction $transaction): string => $transaction->source->name);
        yield AssociationField::new('category')->renderAsNativeWidget()->setFormTypeOption('choice_label', 'name')
            ->setRequired(false)->formatValue(static fn (mixed $value, Transaction $transaction): string => $transaction->category?->name ?? '');
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->transactions->queryForUser($user)->andWhere('record.isDraft = false');
    }
}
