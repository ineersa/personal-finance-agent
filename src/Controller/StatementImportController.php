<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Source;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Import\ImportException;
use App\Import\StatementUpload;
use App\Message\ProcessStatement;
use App\Repository\SourceRepository;
use App\Repository\StatementRepository;
use App\Repository\TransactionRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementImportController extends AbstractController
{
    #[AdminRoute('/statement-upload', name: 'statement_upload', options: ['methods' => ['GET', 'POST']])]
    public function upload(Request $request, #[CurrentUser] User $owner, SourceRepository $sources, StatementUpload $uploads, #[Autowire('%env(JEV_BASE_URL)%')] string $providerUrl): Response
    {
        $form = $this->createFormBuilder()
            ->add('source', EntityType::class, ['class' => Source::class, 'choice_label' => 'name', 'query_builder' => static fn () => $sources->queryForUser($owner), 'placeholder' => 'Select your account or card'])
            ->add('file', FileType::class, ['label' => 'CSV statement', 'constraints' => [new Assert\NotNull(), new Assert\File(maxSize: 10 * 1024 * 1024, extensions: ['csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel']])]])
            ->add('providerConsent', CheckboxType::class, ['label' => 'I agree to send statement samples and transaction details to the configured Jev provider for interpretation.', 'constraints' => [new Assert\IsTrue(message: 'Consent is required before provider processing.')]])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $source = $form->get('source')->getData();
            $file = $form->get('file')->getData();
            if (!$source instanceof Source || !$file instanceof UploadedFile) {
                throw new \LogicException('Validated upload form data is invalid.');
            }
            try {
                $statement = $uploads->upload($owner, $source, $file);

                return $this->redirectToRoute('admin_statement_processing', ['id' => $statement->getId()]);
            } catch (ImportException $error) {
                $form->addError(new FormError($error->getMessage()));
            }
        }

        $providerHost = parse_url($providerUrl, \PHP_URL_HOST);

        return $this->render('statement/upload.html.twig', ['form' => $form, 'providerHost' => \is_string($providerHost) && '' !== $providerHost ? $providerHost : 'configured provider']);
    }

    #[AdminRoute('/statement-processing/{id}', name: 'statement_processing', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function processing(int $id, Request $request, #[CurrentUser] User $owner, StatementRepository $statements, TransactionRepository $transactions, EntityManagerInterface $entityManager, MessageBusInterface $bus, \App\Import\ImportLifecycle $lifecycle): Response
    {
        $statement = $statements->findForUser($owner, $id) ?? throw $this->createNotFoundException();
        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && ProcessingStatus::Failed === $statement->processingStatus) {
            try {
                $lock = $lifecycle->acquire();
            } catch (ImportException $error) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage());
            }
            try {
                $connection = $entityManager->getConnection();
                // Retry only failed operations without persisted rows; a completed import is never duplicated.
                $claimed = $connection->executeStatement("UPDATE statement SET processing_status = 'queued', processing_error = NULL WHERE id = ? AND user_id = ? AND stored_filename = ? AND processing_status = 'failed' AND NOT EXISTS (SELECT 1 FROM financial_transaction WHERE statement_id = ?)", [$id, $owner->getId(), $statement->storedFilename, $id]);
                if (1 === $claimed) {
                    try {
                        $bus->dispatch(new ProcessStatement($id, $owner->getId() ?? throw new \LogicException('Owner missing.'), $statement->storedFilename));
                    } catch (\Throwable) {
                        $connection->executeStatement("UPDATE statement SET processing_status = 'failed', processing_error = ? WHERE id = ? AND processing_status = 'queued'", ['The processing queue is unavailable. Retry when the broker is running.', $id]);
                    }
                }

                return $this->redirectToRoute('admin_statement_processing', ['id' => $id]);
            } finally {
                $lock->release();
            }
        }
        $drafts = $transactions->queryForUser($owner)->andWhere('record.statement = :statement')->andWhere('record.isDraft = true')->setParameter('statement', $statement)->orderBy('record.id', 'ASC')->getQuery()->getResult();

        $response = $this->render('statement/processing.html.twig', ['statement' => $statement, 'drafts' => $drafts, 'retryForm' => $form]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
