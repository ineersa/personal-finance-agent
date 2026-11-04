<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatController extends AbstractController
{
    #[AdminRoute('/chat', name: 'chat', options: ['methods' => ['GET']])]
    public function __invoke(Connection $connection, Workspace $workspace, #[CurrentUser] User $user): Response
    {
        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $connection->status($user),
            'chat' => ChatUpdates::view($workspace->current($user)),
        ]);
    }
}
