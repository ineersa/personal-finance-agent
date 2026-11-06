<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatSearch;
use App\Chat\ChatSelection;
use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;

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
        ]);
    }

    #[Route('/home/chat/search', name: ChatSearch::ROUTE, methods: [Request::METHOD_GET])]
    public function search(Request $request, ChatConversationRepository $conversations, FormFactoryInterface $forms, #[CurrentUser] User $user): Response
    {
        $form = $forms->createNamed('', FormType::class, null, ['method' => Request::METHOD_GET, 'csrf_protection' => false, 'allow_extra_fields' => true])
            ->add(ChatSearch::QUERY_PARAMETER, SearchType::class, ['required' => false, 'constraints' => [new Length(max: ChatSearch::QUERY_LENGTH)]])
            ->handleRequest($request);
        $query = $form->get(ChatSearch::QUERY_PARAMETER)->getData() ?? '';
        $results = [];
        $error = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $results = $conversations->search($user, $query);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        } elseif ($form->isSubmitted()) {
            $error = (string) $form->getErrors(true);
        }
        $response = $this->render('chat/search_results.html.twig', ['query' => $query, 'results' => $results, 'error' => $error]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
