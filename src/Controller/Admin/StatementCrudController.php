<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Import\ImportException;
use App\Import\ImportLifecycle;
use App\Repository\StatementRepository;
use App\Security\FinanceRecordVoter;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends OwnedCrudController<Statement> */
#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementCrudController extends OwnedCrudController
{
    public function __construct(private readonly StatementRepository $statements, private readonly ImportLifecycle $lifecycle, private readonly StatementStorage $storage)
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

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $entityInstance);
        if (!$entityInstance instanceof Statement) {
            throw new \LogicException('Expected a statement.');
        }
        try {
            $lock = $this->lifecycle->acquire(true);
        } catch (ImportException $error) {
            throw new ConflictHttpException($error->getMessage());
        }
        try {
            $entityManager->refresh($entityInstance);
            $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $entityInstance);
            if (\in_array($entityInstance->processingStatus, [ProcessingStatus::Queued, ProcessingStatus::Running], true)) {
                throw new ConflictHttpException('Deletion refused: wait for this import to finish.');
            }
            $this->storage->delete($entityInstance->storedFilename);
            $entityManager->wrapInTransaction(static function () use ($entityManager, $entityInstance): void {
                $entityManager->createQuery('DELETE FROM '.Transaction::class.' record WHERE record.statement = :statement')->setParameter('statement', $entityInstance)->execute();
                $entityManager->remove($entityInstance);
            });
        } catch (ConflictHttpException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ConflictHttpException('Deletion incomplete. The original file may already be removed. Check storage and database availability before retrying.');
        } finally {
            $lock->release();
        }
    }

    protected function ownedQuery(User $user): QueryBuilder
    {
        return $this->statements->queryForUser($user);
    }
}
