<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatAction;
use App\Chat\ChatSelection;
use App\Chat\ChatUpdates;
use App\Chat\Workspace;
use App\ChatGpt\Connection;
use App\ChatGpt\ConnectionStatus;
use App\Entity\User;
use App\Security\FinanceRecordVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(FinanceRecordVoter::ACCESS)]
final class ChatSubmitController extends AbstractController
{
    #[AdminRoute('/chat/message', name: 'chat_submit', options: ['methods' => [Request::METHOD_POST]])]
    public function __invoke(Request $request, #[CurrentUser] User $user, Workspace $workspace, ChatUpdates $updates, Connection $connection, FormFactoryInterface $forms): Response
    {
        $form = $forms->createNamed('', FormType::class, null, ['csrf_token_id' => Workspace::SUBMIT_CSRF, 'allow_extra_fields' => true])
            ->add('question', TextareaType::class, ['constraints' => [new NotBlank(message: 'Write a message first.')]])
            ->add('conversation', HiddenType::class, ['required' => false, 'constraints' => [new Uuid()]])
            ->handleRequest($request);
        $question = (string) $form->get('question')->getData();
        $id = (string) $form->get('conversation')->getData();
        $conversation = '' === $id ? null : $workspace->current($user, $id);
        $status = $connection->status($user);
        $stream = '' !== $id && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $error = 'Check the message form.';
        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $formError) {
                $error = $formError->getMessage();
                break;
            }
        } elseif (ConnectionStatus::Connected !== $status) {
            $error = 'Connect ChatGPT before sending a message.';
        } else {
            try {
                $conversation = $workspace->submit($user, $question, $id);
                if ($stream) {
                    // Acknowledgement only: worker/catch-up owns all chat state.
                    return new Response(status: Response::HTTP_NO_CONTENT);
                }

                return $this->redirectToRoute(Workspace::CHAT_ROUTE, ['conversation' => $conversation->id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            } catch (TransportException) {
                $error = 'The message could not be sent to the worker. Your draft is retained below.';
            }
        }

        if ($stream) {
            $response = $this->render('chat/error.stream.html.twig', ['chat' => $updates->view($conversation), 'error' => $error], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);

            return $response;
        }
        $this->addFlash('warning', $error);

        return $this->render('home/index.html.twig', [
            'chatgpt_status' => $status,
            'chat' => $updates->view($conversation),
            'question' => $question,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/home/chat/favourite', name: Workspace::FAVOURITE_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::FAVOURITE_CSRF)]
    public function favourite(#[MapRequestPayload] ChatAction $action, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $workspace->favourite($user, $action->conversation, $action->favourite);

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, ['conversation' => $action->conversation], Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/delete', name: Workspace::DELETE_ROUTE, methods: [Request::METHOD_POST])]
    #[IsCsrfTokenValid(Workspace::DELETE_CSRF)]
    public function delete(#[MapRequestPayload] ChatAction $action, #[CurrentUser] User $user, Workspace $workspace): Response
    {
        $selected = $action->selected;
        if ('' !== $selected) {
            $workspace->current($user, $selected);
        }
        $workspace->delete($user, $action->conversation);
        if ($action->conversation === $selected) {
            $selected = '';
        }

        return $this->redirectToRoute(Workspace::CHAT_ROUTE, '' === $selected ? [] : ['conversation' => $selected], Response::HTTP_SEE_OTHER);
    }

    #[Route('/home/chat/snapshot', name: Workspace::SNAPSHOT_ROUTE, methods: [Request::METHOD_GET])]
    public function snapshot(#[CurrentUser] User $user, Workspace $workspace, ChatUpdates $updates, #[MapQueryString] ChatSelection $selection = new ChatSelection()): Response
    {
        $response = $this->render('chat/update.stream.html.twig', ['chat' => $updates->view($workspace->current($user, $selection->conversation))]);
        $response->headers->set('Content-Type', TurboBundle::STREAM_MEDIA_TYPE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
