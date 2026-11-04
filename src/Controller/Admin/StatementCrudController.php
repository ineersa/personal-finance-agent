<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Finance\Entity\Statement;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Import\ImportException;
use App\Repository\StatementRepository;
use App\Security\FinanceRecordVoter;
use App\Storage\StatementStorage;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends OwnedCrudController<Statement> */
#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementCrudController extends OwnedCrudController
{
    public function __construct(private readonly StatementRepository $statements, private readonly StatementStorage $storage)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Statement::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $upload = Action::new('uploadCsv', 'Upload CSV')->linkToRoute('admin_statement_upload')->createAsGlobalAction();
        $processing = Action::new('processing', 'Processing and drafts')->linkToRoute('admin_statement_processing', static fn (Statement $statement): array => ['id' => $statement->getId()]);
        $download = Action::new('download', 'Download')
            ->linkToRoute('app_statement_download', static fn (Statement $statement): array => ['id' => $statement->getId()])
            ->setHtmlAttributes(['data-turbo' => 'false']);

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $upload)
            ->add(Crud::PAGE_INDEX, $processing)
            ->add(Crud::PAGE_DETAIL, $processing)
            ->add(Crud::PAGE_INDEX, $download)
            ->add(Crud::PAGE_DETAIL, $download)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action->askConfirmation('Permanently delete this statement, its original CSV file and all linked transactions, including approved transactions and unapproved drafts?'))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action): Action => $action->askConfirmation('Permanently delete this statement, its original CSV file and all linked transactions, including approved transactions and unapproved drafts?'))
            ->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('originalFilename');
        yield ChoiceField::new('processingStatus');
        yield AssociationField::new('source');
        yield TextField::new('mediaType')->onlyOnDetail();
        yield IntegerField::new('byteSize')->onlyOnDetail();
        yield DateField::new('statementDate');
        yield DateTimeField::new('uploadedAt');
        yield DateTimeField::new('processedAt');
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::delete($context);
        } catch (ImportException $error) {
            $this->addFlash('danger', $error->getMessage());

            return $this->redirectToRoute('admin_statement_detail', ['entityId' => $context->getEntity()->getPrimaryKeyValue()]);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof Statement) {
            throw new \LogicException('Expected a statement.');
        }
        // EasyAdmin checks the configured entity permission before calling this hook.
        if (\in_array($entityInstance->processingStatus, [ProcessingStatus::Queued, ProcessingStatus::Running], true)) {
            throw new ImportException('Deletion refused: wait for this import to finish.');
        }
        $filename = $entityInstance->storedFilename;
        try {
            parent::deleteEntity($entityManager, $entityInstance);
        } catch (\Throwable) {
            throw new ImportException('Statement deletion could not be committed. The original file was retained. Check database availability before retrying.');
        }
        try {
            $this->storage->delete($filename);
        } catch (\Throwable) {
            $this->addFlash('warning', 'Statement records were deleted, but its original file could not be removed. Orphaned bytes may remain in private storage.');
        }
    }

    protected function ownedQuery(): QueryBuilder
    {
        return $this->statements->query();
    }
}
