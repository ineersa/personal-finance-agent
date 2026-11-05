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
        $id = $request->request->getString('conversation');
        $conversation = '' === $id ? null : $workspace->current($user, $id);
        $status = $connection->status($user);
        if ('' === $question) {
            $this->addFlash('warning', 'Write a message first.');
        } elseif (ConnectionStatus::Connected !== $status) {
            $this->addFlash('warning', 'Connect ChatGPT before sending a message.');
        } else {
            try {
                $conversation = $workspace->submit($user, $question, $id);
                if ('' !== $id && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $response = $this->render('chat/submitted.stream.html.twig', ['chat' => ChatUpdates::view($conversation)]);
                    $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);

                    return $response;
                }

                return $this->redirectToRoute(Workspace::CHAT_ROUTE, ['conversation' => $conversation->id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $error) {
                $this->addFlash('warning', $error->getMessage());
            } catch (TransportException) {
                $this->addFlash('warning', 'The message could not be sent to the worker. Your draft is retained below.');
            }
        }

        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $status,
            'chat' => ChatUpdates::view($conversation),
            'question' => $question,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/home/chat/new', name: Workspace::NEW_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::NEW_CSRF)]
    public function startNew(Request $request, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $id = $request->request->getString('conversation');
        if ('' !== $id) {
            $workspace->current($user, $id);
        }
        try {
            $conversation = $workspace->startNew($user);

            return $this->redirectToRoute(Workspace::CHAT_ROUTE, ['conversation' => $conversation->id], Response::HTTP_SEE_OTHER);
        } catch (\DomainException $error) {
            $this->addFlash('warning', $error->getMessage());
        }

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, '' === $id ? [] : ['conversation' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/save', name: Workspace::SAVE_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::SAVE_CSRF)]
    public function save(Request $request, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $id = $request->request->getString('conversation');
        if ('' === $id) {
            throw $this->createNotFoundException();
        }
        try {
            $workspace->save($user, $id);
        } catch (\DomainException $error) {
            $this->addFlash('warning', $error->getMessage());
        }

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, ['conversation' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/delete', name: Workspace::DELETE_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::DELETE_CSRF)]
    public function delete(Request $request, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $id = $request->request->getString('conversation');
        if ('' === $id) {
            throw $this->createNotFoundException();
        }
        $selected = $request->request->getString('selected');
        if ('' !== $selected) {
            $workspace->current($user, $selected);
        }
        try {
            $workspace->delete($user, $id);
            if ($id === $selected) {
                $selected = '';
            }
        } catch (\DomainException $error) {
            $this->addFlash('warning', $error->getMessage());
        }

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, '' === $selected ? [] : ['conversation' => $selected], Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/snapshot', name: Workspace::SNAPSHOT_ROUTE, methods: [Request::METHOD_GET])]
    public function snapshot(Request $request, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $id = $request->query->getString('conversation');
        $response = $this->render('chat/update.stream.html.twig', ['chat' => ChatUpdates::view('' === $id ? null : $workspace->current($user, $id)), 'chat_navigation' => true]);
        $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
