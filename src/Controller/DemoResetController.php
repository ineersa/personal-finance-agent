<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\DemoReset;
use App\Entity\User;
use App\Import\ImportException;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\IsTrue;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class DemoResetController extends AbstractController
{
    #[AdminRoute('/settings/reset', name: 'demo_reset', options: ['methods' => ['GET', 'POST']])]
    public function __invoke(Request $request, #[CurrentUser] User $user, DemoReset $reset, Security $security): Response
    {
        if (!$reset->isAllowed($user)) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->createFormBuilder()->add('confirm', CheckboxType::class, [
            'label' => 'I understand this deletes all users, statements and transactions and restores the demo fixtures.',
            'constraints' => [new IsTrue(message: 'Confirm the database-wide reset to continue.')],
        ])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $reset->reset();
            } catch (ImportException $error) {
                $this->addFlash('danger', $error->getMessage());

                return $this->redirectToRoute('admin_demo_reset');
            } catch (\Throwable) {
                // Purge/file failures may be partial: sign out and never advertise success.
                $response = $security->logout(false) ?? new Response();
                $response->headers->remove('Location');
                $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
                $response->setContent($this->renderView('settings/reset_failed.html.twig'));

                return $response;
            }

            // The confirmation form already validated its own CSRF token.
            return $security->logout(false) ?? $this->redirectToRoute('app_login');
        }

        return $this->render('settings/reset.html.twig', ['form' => $form]);
    }
}
