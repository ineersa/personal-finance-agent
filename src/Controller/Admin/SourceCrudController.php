<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Source;
use App\Entity\User;
use App\Enum\InstrumentKind;
use App\Repository\SourceRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends OwnedCrudController<Source> */
#[IsGranted(FinanceRecordVoter::ACCESS)]
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
        yield TextField::new('currency')->setHelp('Three-letter statement currency, for example CAD or USD. Required before CSV processing.')->setRequired(false);
        yield ChoiceField::new('instrumentKind', 'Instrument kind')->setFormType(EnumType::class)->setFormTypeOption('class', InstrumentKind::class)
            ->setChoices(['Bank account' => InstrumentKind::BankAccount, 'Credit card' => InstrumentKind::CreditCard, 'Other' => InstrumentKind::Other])->renderAsNativeWidget();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInPlural('Sources')->setSearchFields(['name', 'description']);
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->sources->queryForUser($user);
    }
}
