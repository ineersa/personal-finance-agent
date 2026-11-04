<?php

declare(strict_types=1);

namespace App\Controller;

use App\ChatGpt\Connection;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatGptController extends AbstractController
{
    #[Route('/home/chatgpt/connect', name: Connection::CONNECT_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Connection::CONNECT_CSRF)]
    public function connect(#[CurrentUser] User $user, Connection $connection): Response
    {
        try {
            $response = $this->redirect($connection->begin($user));
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } catch (AuthException) {
            $this->addFlash('danger', 'ChatGPT sign-in could not be started. Please try again.');
        }

        return $this->redirectToRoute(Connection::SETTINGS_ROUTE, ['_fragment' => Connection::SETTINGS_FRAGMENT]);
    }

    #[Route('/home/chatgpt/disconnect', name: Connection::DISCONNECT_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Connection::DISCONNECT_CSRF)]
    public function disconnect(#[CurrentUser] User $user, Connection $connection): Response
    {
        try {
            $connection->disconnect($user);
            $this->addFlash('success', 'ChatGPT disconnected. Reconnect to use the same account registration.');
        } catch (AuthException) {
            $this->addFlash('danger', 'ChatGPT could not be disconnected. Your connection is unchanged. Please try again.');
        }

        return $this->redirectToRoute(Connection::SETTINGS_ROUTE, ['_fragment' => Connection::SETTINGS_FRAGMENT]);
    }
}
