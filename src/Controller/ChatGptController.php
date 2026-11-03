<?php

declare(strict_types=1);

namespace App\Controller;

use App\ChatGpt\Connection;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatGptController extends AbstractController
{
    #[Route('/home/chatgpt/{action}', name: 'app_chatgpt_connection', requirements: ['action' => 'connect|disconnect'], methods: ['POST'])]
    public function manage(string $action, Request $request, #[CurrentUser] User $user, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('chatgpt-'.$action, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            if ('connect' === $action) {
                $response = $this->redirect($connection->begin($user));
                $response->headers->set('Cache-Control', 'no-store');
                $response->headers->set('Referrer-Policy', 'no-referrer');

                return $response;
            }
            $connection->disconnect($user);
            $this->addFlash('success', 'ChatGPT disconnected. You can now connect a different account.');
        } catch (\Throwable) {
            $this->addFlash('danger', 'ChatGPT connection could not be updated. Please try again.');
        }

        return $this->redirect('/home/settings#chatgpt-connection');
    }
}
