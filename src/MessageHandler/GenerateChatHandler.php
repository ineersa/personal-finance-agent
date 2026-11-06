<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChatStream;
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
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
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
        $registration = $owner->getChatGptRegistrationId();
        $id = UuidV7::fromString($conversation->id);
        $input = $this->store->load($id);
        if (null === $registration) {
            $this->updates->publish($conversation, $input, ExecutionStatus::Failed, 'Connect ChatGPT before sending a message.');

            return;
        }
        $stream = new ChatStream();
        $lastUpdate = 0.0;
        $publish = function (bool $force = false) use ($conversation, &$input, $stream, &$lastUpdate): void {
            if ($force || microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                $this->updates->publish($conversation, $stream->appendTo($input), ExecutionStatus::Running);
                $lastUpdate = microtime(true);
            }
        };
        $events = new EventDispatcher();
        $events->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use ($stream, $publish): void {
            $stream->completed($event->getResult());
            $publish(true);
        });
        $question = $message->input->getMessages()[array_key_last($message->input->getMessages())];
        try {
            // Decide when processing, after earlier queued submissions have saved their native history.
            $request = 0 === $input->count()
                ? $input->with(Message::ofUser($this->context->forOwner($owner)))->merge($message->input)
                : $message->input;
            $execution = $this->agents->create($owner, $registration, $this->model, $events, $conversation, $question->getId()->toRfc4122())->call($request,
                ['stream' => true, 'prompt_cache_key' => $conversation->id, 'reasoning' => ['effort' => $this->effort, 'summary' => 'auto']],
            );
            $execution->onProgress(function (Progress $update) use ($stream, $publish, &$input, $id): void {
                if (Progress::STAGE_MODEL_REQUEST === $update->getStage()) {
                    // Agent has accepted/saved input. Read once per model round, never per delta.
                    $input = $this->store->load($id);
                    $stream->reset();
                }
                if (Progress::STAGE_DELTA === $update->getStage() && $update->getPayload() instanceof DeltaInterface) {
                    $stream->observe($update->getPayload());
                }
                if (Progress::STAGE_TOOL_CALL === $update->getStage() && $update->getPayload() instanceof ToolCall) {
                    $stream->observe(new ToolCallComplete([$update->getPayload()]));
                    $publish(true);

                    return;
                }
                $publish();
            });
            $messages = $execution->getMessages();
        } catch (ConflictException $error) {
            $this->updates->publish($conversation, $this->store->load($id), ExecutionStatus::Failed, 'This conversation changed during the response.');
            throw $error;
        } catch (\Throwable) {
            // Native Messenger retry/failure policy owns delivery; never log provider payloads.
            $this->updates->publish($conversation, $this->store->load($id), ExecutionStatus::Failed, 'The response could not be completed.');
            throw new \RuntimeException('The chat response could not be completed.');
        }
        $this->updates->publish($conversation, $messages, $execution->getStatus());
    }
}
