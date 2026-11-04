<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Finance\Entity\Source;
use App\Import\ImportException;
use App\Import\StatementUpload;
use App\Repository\SourceRepository;
use App\Repository\StatementRepository;
use App\Repository\TransactionRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class StatementImportController extends AbstractController
{
    #[AdminRoute('/statement-upload', name: 'statement_upload', options: ['methods' => ['GET', 'POST']])]
    public function upload(Request $request, #[CurrentUser] User $owner, SourceRepository $sources, StatementUpload $uploads, #[Autowire('%env(JEV_BASE_URL)%')] string $providerUrl): Response
    {
        $form = $this->createFormBuilder()
            ->add('source', EntityType::class, ['class' => Source::class, 'choice_label' => 'name', 'query_builder' => static fn () => $sources->query(), 'placeholder' => 'Select your account or card'])
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

        return $this->render('statement/upload.html.twig', ['form' => $form, 'providerUrl' => $providerUrl]);
    }

    #[AdminRoute('/statement-processing/{id}', name: 'statement_processing', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function processing(int $id, Request $request, #[CurrentUser] User $owner, StatementRepository $statements, TransactionRepository $transactions, StatementUpload $uploads, ValidatorInterface $validator, EntityManagerInterface $entityManager): Response
    {
        $statement = $statements->find($id) ?? throw $this->createNotFoundException();
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'statement-workflow-'.$id])
            ->add('retry', SubmitType::class)
            ->add('approve', SubmitType::class)
            ->getForm();
        $form->handleRequest($request);
        $retry = $form->get('retry');
        $approve = $form->get('approve');
        if ($form->isSubmitted() && $form->isValid() && $retry instanceof ClickableInterface && $retry->isClicked() && \in_array($statement->processingStatus, [ProcessingStatus::Failed, ProcessingStatus::Running], true)) {
            try {
                $uploads->retry($owner, $statement);
            } catch (ImportException $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToRoute('admin_statement_processing', ['id' => $id]);
        }
        $drafts = $transactions->draftsForStatement($statement);
        $approvalErrors = [];
        if ($form->isSubmitted() && $form->isValid() && $approve instanceof ClickableInterface && $approve->isClicked() && ProcessingStatus::Completed === $statement->processingStatus) {
            foreach ($drafts as $draft) {
                foreach ($validator->validate($draft, groups: ['Default', 'approved']) as $violation) {
                    $approvalErrors[] = ['id' => $draft->getId(), 'field' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
                }
            }
            if ([] === $approvalErrors) {
                // Validate every remaining row before changing any flags. Flush commits
                // the same-row promotions together in one short database transaction.
                foreach ($drafts as $draft) {
                    $draft->isDraft = false;
                }
                $entityManager->flush();
                $this->addFlash('success', [] === $drafts ? 'No drafts remain for approval.' : 'Statement draft approved.');

                return $this->redirectToRoute('admin_statement_index');
            }
            $form->addError(new FormError('Nothing was approved. Correct the draft fields listed below, then approve again.'));
        }

        if ($request->isMethod(Request::METHOD_GET) && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            $response = \in_array($statement->processingStatus, [ProcessingStatus::Completed, ProcessingStatus::Failed], true)
                ? $this->render('statement/update.stream.html.twig', ['statement' => $statement, 'drafts' => $drafts])
                : new Response(status: Response::HTTP_NO_CONTENT);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        $response = $this->render('statement/processing.html.twig', ['statement' => $statement, 'drafts' => $drafts, 'workflowForm' => $form, 'approvalErrors' => $approvalErrors]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
