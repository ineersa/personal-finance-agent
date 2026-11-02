<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Source;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Direction;
use App\Enum\ProcessingStatus;
use App\Repository\SourceRepository;
use App\Repository\TransactionRepository;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class DraftReviewController extends AbstractController
{
    #[AdminRoute('/draft-review/{id}', name: 'draft_review', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function review(int $id, Request $request, #[CurrentUser] User $owner, TransactionRepository $transactions, SourceRepository $sources, EntityManagerInterface $entityManager): Response
    {
        // Includes ownership of both sources and the originating statement.
        $draft = $transactions->findForUser($owner, $id) ?? throw $this->createNotFoundException();
        if (!$draft->isDraft || ProcessingStatus::Completed !== $draft->statement->processingStatus) {
            // A repeated approval never binds stale edits or creates another row.
            return $this->redirectToRoute('admin_statement_processing', ['id' => $draft->statement->getId()]);
        }
        $form = $this->createFormBuilder($draft, ['data_class' => Transaction::class, 'validation_groups' => ['Default', 'approved']])
            ->add('transactionDate', DateType::class, ['label' => 'Transaction date', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            // Text fields preserve signs, trailing zeros and arbitrary decimal precision.
            ->add('amount', TextType::class)
            ->add('currency', TextType::class)
            ->add('direction', ChoiceType::class, ['choices' => ['Credit' => Direction::Credit, 'Debit' => Direction::Debit]])
            ->add('description', TextareaType::class, ['required' => false, 'empty_data' => ''])
            ->add('source', EntityType::class, ['class' => Source::class, 'choice_label' => 'name', 'query_builder' => static fn () => $sources->queryForUser($owner), 'empty_data' => static fn (): never => throw new TransformationFailedException('A transaction source is required.')])
            ->add('category', EntityType::class, ['class' => Category::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Uncategorized'])
            ->add('save', SubmitType::class, ['label' => 'Save draft'])
            ->add('approve', SubmitType::class, ['label' => 'Approve transaction'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $draft->source);
            $this->denyAccessUnlessGranted(FinanceRecordVoter::ACCESS, $draft->statement);
            $approve = $form->get('approve');
            if ($approve instanceof ClickableInterface && $approve->isClicked()) {
                // Promote the existing row. Doctrine flush writes corrections and approval
                // in one short transaction, retaining the original statement and row ID.
                $draft->isDraft = false;
            }
            $entityManager->flush();
            $this->addFlash('success', $draft->isDraft ? 'Draft saved. Approval is still required.' : 'Transaction approved.');

            return $draft->isDraft
                ? $this->redirectToRoute('admin_draft_review', ['id' => $id])
                : $this->redirectToRoute('admin_statement_processing', ['id' => $draft->statement->getId()]);
        }

        $response = $this->render('statement/review.html.twig', ['draft' => $draft, 'form' => $form]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
