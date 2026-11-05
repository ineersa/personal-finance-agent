<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatSearch;
use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatController extends AbstractController
{
    #[AdminRoute('/chat', name: 'chat', options: ['methods' => ['GET']])]
    public function __invoke(Request $request, Connection $connection, Workspace $workspace, #[CurrentUser] User $user): Response
    {
        $id = $request->query->getString('conversation');
        $conversation = $workspace->current($user, $id);

        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $connection->status($user),
            'chat' => ChatUpdates::view($conversation),
        ]);
    }

    #[Route('/home/chat/search', name: ChatSearch::ROUTE, methods: [Request::METHOD_GET])]
    public function search(Request $request, ChatSearch $search, #[CurrentUser] User $user): Response
    {
        $query = mb_substr(trim($request->query->getString(ChatSearch::QUERY_PARAMETER)), 0, ChatSearch::QUERY_LENGTH);
        $response = $this->render('chat/search_results.html.twig', ['query' => $query, 'results' => '' === $query ? [] : $search->find($user, $query)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
