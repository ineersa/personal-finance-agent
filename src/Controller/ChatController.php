<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatSearch;
use App\Chat\ChatSelection;
use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\Entity\User;
use App\Form\ChatMessageType;
use App\Repository\ChatConversationRepository;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatController extends AbstractController
{
    #[AdminRoute('/chat', name: 'chat', options: ['methods' => ['GET']])]
    public function __invoke(Connection $connection, Workspace $workspace, ChatUpdates $updates, #[CurrentUser] User $user, #[MapQueryString] ChatSelection $selection = new ChatSelection()): Response
    {
        $conversation = $workspace->current($user, $selection->conversation);

        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $connection->status($user),
            'chat' => $updates->view($conversation),
            'form' => $this->createForm(ChatMessageType::class, ['conversation' => $conversation?->id])->createView(),
        ]);
    }

    #[Route('/home/chat/search', name: ChatSearch::ROUTE, methods: [Request::METHOD_GET])]
    public function search(ChatConversationRepository $conversations, #[CurrentUser] User $user, #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ChatSearch $search = new ChatSearch()): Response
    {
        $limit = 20;
        $results = [];
        $error = null;
        try {
            $results = $conversations->search($user, $search->q, $limit);
        } catch (\DomainException $exception) {
            $error = $exception->getMessage();
        }
        $response = $this->render('chat/search_results.html.twig', ['query' => $search->q, 'results' => $results, 'error' => $error, 'limit' => $limit]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
