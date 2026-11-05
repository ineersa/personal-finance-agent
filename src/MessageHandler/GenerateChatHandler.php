<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChatUpdates;
use App\Chat\ConversationMessageStore;
use App\Chat\NativeAssistantOutput;
use App\Chat\Workspace;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\UuidV7;

#[AsMessageHandler(fromTransport: GenerateChat::TRANSPORT)]
final readonly class GenerateChatHandler
{
    private const float UPDATE_INTERVAL = 0.05;

    public function __construct(private EntityManagerInterface $em, private AgentFactory $agents, private MessageBagNormalizer $normalizer, private ChatUpdates $updates, private Workspace $workspace)
    {
    }

    public function __invoke(GenerateChat $message): void
    {
        $conversation = $this->workspace->claim($message);
        if (null === $conversation) {
            return;
        }
        if (ExecutionStatus::Running !== $conversation->generationStatus) {
            $this->updates->publish($conversation);

            return;
        }
        $owner = $conversation->getUser();
        $registration = $owner->getChatGptRegistrationId();
        $turnId = $conversation->generationId;
        $attemptId = $conversation->attemptId;
        $ownerId = $owner->getId();
        \assert(null !== $registration && null !== $turnId && null !== $attemptId && null !== $ownerId);
        $execution = null;
        $output = new NativeAssistantOutput();
        $lastUpdate = 0.0;
        $project = function (bool $force = false) use (&$execution, $conversation, $turnId, $attemptId, $output, &$lastUpdate): void {
            if ($force || microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                // Persist chart authorization and the readable projection before browser delivery.
                $this->workspace->project($conversation, $turnId, $attemptId, $output);
                $this->updates->publish($conversation, $execution);
                $lastUpdate = microtime(true);
            }
        };
        $error = null;
        try {
            $store = new ConversationMessageStore($this->em->getConnection(), $this->normalizer, $ownerId, $conversation->id, $turnId, $attemptId);
            $history = $store->load(UuidV7::fromString($conversation->id));
            $turn = $conversation->turns[\count($conversation->turns) - 1];
            if ($output->restore($history, $turn['charts'] ?? [], $turn['activity'] ?? [])) {
                // Native final output committed before the prior worker died; no second inference.
                $this->workspace->project($conversation, $turnId, $attemptId, $output, ExecutionStatus::Completed);
                $this->updates->publish($conversation);

                return;
            }
            $project(true);
            $events = new EventDispatcher();
            $events->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use ($output, $project): void {
                $error = $event->getException();
                $detail = $error instanceof ToolExecutionExceptionInterface ? $error->getToolCallResult() : 'Tool execution failed.';
                $output->activity->toolFailed($event->getDefinition()->getName(), $detail);
                $project(true);
            });
            $events->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use ($output, $project): void {
                $output->activity->completed($event->getResult());
                $project(true);
            });
            $execution = $this->agents->create($owner, $registration, $conversation->model, $output, $events, $conversation->id, $store)->call(
                // Agent loads canonical history and saves input, complete tool rounds and final native output.
                MessageBag::forId(UuidV7::fromString($conversation->id)),
                ['stream' => true, 'prompt_cache_key' => $conversation->id, 'reasoning' => ['effort' => $conversation->reasoningEffort, 'summary' => 'auto']],
            );
            $execution->onProgress(static function (Progress $update) use ($output, $project): void {
                if (Progress::STAGE_MODEL_REQUEST === $update->getStage()) {
                    $output->beginRound();
                }
                if (Progress::STAGE_DELTA === $update->getStage() && $update->getPayload() instanceof DeltaInterface) {
                    $output->observe($update->getPayload());
                }
                $toolStarted = Progress::STAGE_TOOL_CALL === $update->getStage() && $update->getPayload() instanceof ToolCall;
                if ($toolStarted && $update->getPayload() instanceof ToolCall) {
                    $output->activity->started($update->getPayload());
                }
                $project($toolStarted);
            });
            $execution->getMessages();
        } catch (ConflictException) {
            // A redelivery owns this generation now. Never mark its work failed or overwrite it.
            return;
        } catch (\Throwable) {
            // Provider exceptions can contain private input or credentials.
            $output->activity->failed();
            $error = 'The response could not be completed. Please try again.';
        }
        try {
            $this->workspace->project($conversation, $turnId, $attemptId, $output, null === $error ? ExecutionStatus::Completed : ExecutionStatus::Failed, $error);
        } catch (ConflictException) {
            return;
        }
        $this->updates->publish($conversation);
    }
}
