<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChatUpdates;
use App\Chat\InitialContext;
use App\Message\GenerateChat;
use App\Repository\ChatConversationRepository;
use App\Repository\UserRepository;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\UuidV7;

#[AsMessageHandler(fromTransport: GenerateChat::TRANSPORT)]
final readonly class GenerateChatHandler
{
    private const float UPDATE_INTERVAL = 0.05;

    public function __construct(private UserRepository $users, private ChatConversationRepository $conversations, private MessageStoreInterface $store, private InitialContext $context, private AgentFactory $agents, private ChatUpdates $updates, #[Autowire('%env(CHAT_MODEL)%')] private string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private string $effort)
    {
    }

    public function __invoke(GenerateChat $message): void
    {
        $owner = $this->users->find($message->ownerId);
        $conversation = null === $owner ? null : $this->conversations->owned($owner, $message->conversationId);
        if (null === $conversation || null === $owner) {
            return;
        }
        $id = UuidV7::fromString($conversation->id);
        $input = $this->store->load($id);
        $question = $message->input->getMessages()[array_key_last($message->input->getMessages())];
        $registration = $owner->getChatGptRegistrationId();
        if (null === $registration) {
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $input, 'status' => ExecutionStatus::Failed, 'error' => 'Connect ChatGPT before sending a message.']);

            return;
        }
        // Only the current model response is accumulated; native history is never reconstructed.
        $listener = new AssistantMessageStreamListener();
        $anchor = $question->getId()->toRfc4122();
        $lastUpdate = 0.0;
        $publishContent = function (bool $force = false) use ($conversation, $question, $listener, &$anchor, &$lastUpdate): void {
            if ($force || microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                $this->updates->publish($conversation, 'chat/content.stream.html.twig', ['user' => $question, 'anchor' => $anchor, 'response' => $listener->getAssistantMessage()]);
                $lastUpdate = microtime(true);
            }
        };
        $events = new EventDispatcher();
        $events->addListener(ToolCallSucceeded::class, function (ToolCallSucceeded $event) use ($conversation, $question): void {
            $result = $event->getResult();
            $this->updates->publish($conversation, 'chat/tool.stream.html.twig', ['user' => $question, 'tool' => $result->getToolCall(), 'result' => $result]);
        });
        try {
            // Decide when processing, after earlier queued submissions have saved their native history.
            $request = 0 === $input->count()
                ? $input->with(Message::ofUser($this->context->forOwner($owner)))->merge($message->input)
                : $message->input;
            $execution = $this->agents->create($owner, $registration, $this->model, $events, $conversation, $question->getId()->toRfc4122())->call($request,
                ['stream' => true, 'prompt_cache_key' => $conversation->id, 'reasoning' => ['effort' => $this->effort, 'summary' => 'auto']],
            );
            $this->updates->publish($conversation, 'chat/start.stream.html.twig', ['user' => $question]);
            $nextAnchor = $anchor;
            $execution->onProgress(function (Progress $update) use ($conversation, $question, $listener, $publishContent, &$anchor, &$nextAnchor): void {
                if (Progress::STAGE_MODEL_REQUEST === $update->getStage()) {
                    $listener->reset();
                    $anchor = $nextAnchor;
                    $this->updates->publish($conversation, 'chat/round.stream.html.twig', ['user' => $question, 'anchor' => $anchor]);
                }
                if (Progress::STAGE_DELTA === $update->getStage() && $update->getPayload() instanceof DeltaInterface) {
                    $listener->accumulate($update->getPayload());
                    $publishContent();
                }
                if (Progress::STAGE_TOOL_CALL === $update->getStage() && $update->getPayload() instanceof ToolCall) {
                    // Flush this response before its first tool card; later cards append without erasing earlier ones.
                    if ($nextAnchor === $anchor) {
                        $publishContent(true);
                    }
                    $tool = $update->getPayload();
                    $nextAnchor = $tool->getId();
                    $this->updates->publish($conversation, 'chat/tool.stream.html.twig', ['user' => $question, 'anchor' => $anchor, 'tool' => $tool, 'result' => null]);
                }
            });
            // This drives the one execution and returns its canonical native messages.
            $messages = $execution->getMessages();
        } catch (ConflictException $error) {
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $this->store->load($id), 'status' => ExecutionStatus::Failed, 'error' => 'This conversation changed during the response.']);
            throw $error;
        } catch (\Throwable) {
            // Native Messenger retry/failure policy owns delivery; never log provider payloads.
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $this->store->load($id), 'status' => ExecutionStatus::Failed, 'error' => 'The response could not be completed.']);
            throw new \RuntimeException('The chat response could not be completed.');
        }
        $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $messages, 'status' => $execution->getStatus(), 'error' => null]);
    }
}
