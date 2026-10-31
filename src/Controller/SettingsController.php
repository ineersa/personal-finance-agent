<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\ReportingCurrency;
use App\Security\FinanceRecordVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createFormBuilder($user)
            ->add('reportingCurrency', EnumType::class, ['class' => ReportingCurrency::class, 'choice_label' => static fn (ReportingCurrency $currency): string => $currency->value])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Reporting currency saved. Original amounts are unchanged.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/index.html.twig', ['form' => $form]);
    }
}
