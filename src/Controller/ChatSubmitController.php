<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\ChatGpt\ConnectionStatus;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatSubmitController extends AbstractController
{
    #[AdminRoute('/chat/message', name: 'chat_submit', options: ['methods' => [Request::METHOD_POST]])]
    #[IsCsrfTokenValid(Workspace::SUBMIT_CSRF)]
    public function __invoke(Request $request, #[CurrentUser] User $user, Workspace $workspace, Connection $connection): Response
    {
        $question = trim($request->request->getString('question'));
        $status = $connection->status($user);
        if ('' === $question) {
            $this->addFlash('warning', 'Write a message first.');
        } elseif (ConnectionStatus::Connected !== $status) {
            $this->addFlash('warning', 'Connect ChatGPT before sending a message.');
        } else {
            try {
                $workspace->submit($user, $question);

                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $response = $this->render('chat/submitted.stream.html.twig', ['chat' => ChatUpdates::view($workspace->current($user))]);
                    $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);

                    return $response;
                }

                return $this->redirectToRoute(Workspace::CHAT_ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (\DomainException $error) {
                $this->addFlash('warning', $error->getMessage());
            } catch (TransportException) {
                $this->addFlash('warning', 'The message could not be sent to the worker. Your draft is retained below.');
            }
        }

        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $status,
            'chat' => ChatUpdates::view($workspace->current($user)),
            'question' => $question,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/home/chat/new', name: Workspace::NEW_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::NEW_CSRF)]
    public function startNew(#[CurrentUser] User $user, Workspace $workspace): Response
    {
        $workspace->startNew($user);

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/snapshot', name: Workspace::SNAPSHOT_ROUTE, methods: [Request::METHOD_GET])]
    public function snapshot(#[CurrentUser] User $user, Workspace $workspace): Response
    {
        $response = $this->render('chat/update.stream.html.twig', ['chat' => ChatUpdates::view($workspace->current($user))]);
        $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
