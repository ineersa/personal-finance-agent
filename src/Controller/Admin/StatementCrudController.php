<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Statement;
use App\Entity\User;
use App\Repository\StatementRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends OwnedCrudController<Statement> */
#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementCrudController extends OwnedCrudController
{
    public function __construct(private readonly StatementRepository $statements)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Statement::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $download = Action::new('download', 'Download')
            ->linkToRoute('app_statement_download', static fn (Statement $statement): array => ['id' => $statement->getId()])
            ->setHtmlAttributes(['data-turbo' => 'false']);

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $download)
            ->add(Crud::PAGE_DETAIL, $download)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('originalFilename');
        yield AssociationField::new('source');
        yield TextField::new('mediaType')->onlyOnDetail();
        yield IntegerField::new('byteSize')->onlyOnDetail();
        yield DateField::new('statementDate');
        yield DateTimeField::new('uploadedAt');
        yield DateTimeField::new('processedAt');
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->statements->queryForUser($user);
    }
}
