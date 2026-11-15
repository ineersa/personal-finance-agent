<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChartToolset;
use App\Chat\ChatControl;
use App\Chat\ChatUpdates;
use App\Chat\InitialContext;
use App\Chat\Workspace;
use App\Finance\Sql\TenantSqlTool;
use App\Message\GenerateChat;
use App\Repository\ChatConversationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\UuidV7;

#[AsMessageHandler(fromTransport: GenerateChat::TRANSPORT)]
final readonly class GenerateChatHandler
{
    private const float UPDATE_INTERVAL = 0.05;

    public function __construct(private UserRepository $users, private EntityManagerInterface $entityManager, private ChatConversationRepository $conversations, private MessageStoreInterface $store, private InitialContext $context, private AgentFactory $agents, private ChatUpdates $updates, private ChatControl $control)
    {
    }

    public function __invoke(GenerateChat $message): void
    {
        $owner = $this->users->find($message->ownerId);
        if (null === $owner) {
            return;
        }
        // Peers may keep the identity map alive; refresh preferences without detaching their entities.
        $this->entityManager->refresh($owner);
        $conversation = $this->conversations->owned($owner, $message->conversationId);
        if (null === $conversation) {
            return;
        }
        $id = UuidV7::fromString($conversation->id);
        $input = $this->store->load($id);
        $question = $message->input->getMessages()[array_key_last($message->input->getMessages())];
        $turn = $question->getId()->toRfc4122();
        $state = $this->control->state($conversation->id);
        // An expired/superseded queued submission must not start after the UI has released it.
        if (null === $state || $state['turn'] !== $turn || ExecutionStatus::Cancelled->value === $state['status']) {
            return;
        }
        $registration = $owner->getChatGptRegistrationId();
        if (null === $registration) {
            $this->control->finish($conversation->id, $turn, ExecutionStatus::Failed);
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $input, 'status' => ExecutionStatus::Failed, 'error' => 'Connect ChatGPT before sending a message.']);

            return;
        }
        // Only the current model response is accumulated; native history is never reconstructed.
        $listener = new AssistantMessageStreamListener();
        $anchor = $question->getId()->toRfc4122();
        $lastUpdate = 0.0;
        $lastStage = null;
        $publishStage = function (string $stage) use ($conversation, $question, &$lastStage): void {
            if ($stage !== $lastStage) {
                $this->updates->publish($conversation, 'chat/status.stream.html.twig', ['user' => $question, 'stage' => $stage]);
                $lastStage = $stage;
            }
        };
        $publishContent = function (bool $force = false) use ($conversation, $question, $listener, &$anchor, &$lastUpdate): void {
            if ($force || microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                $this->updates->publish($conversation, 'chat/content.stream.html.twig', ['user' => $question, 'anchor' => $anchor, 'response' => $listener->getAssistantMessage()]);
                $lastUpdate = microtime(true);
            }
        };
        $events = new EventDispatcher();
        $events->addListener(ToolCallsExecuted::class, function (ToolCallsExecuted $event) use ($conversation, $question, $publishStage): void {
            foreach ($event->getToolResults() as $result) {
                $this->updates->publish($conversation, 'chat/tool.stream.html.twig', ['user' => $question, 'tool' => $result->getToolCall(), 'result' => $result]);
            }
            $this->updates->publish($conversation, 'chat/charts.stream.html.twig', ['user' => $question]);
            $publishStage('Continuing analysis…');
        });
        $execution = null;
        $terminal = ExecutionStatus::Failed;
        try {
            // Decide when processing, after earlier queued submissions have saved their native history.
            $request = 0 === $input->count()
                ? $input->with(Message::ofUser($this->context->forOwner($owner)))->merge($message->input)
                : $message->input;
            $execution = $this->agents->create($owner, $registration, $message->model->value, $events, $conversation, $question->getId()->toRfc4122())->call($request,
                ['stream' => true, 'prompt_cache_key' => $conversation->id, 'reasoning' => ['effort' => $message->effort->value, 'summary' => 'auto']],
            );
            $this->control->register($conversation->id, $turn, $execution);
            $started = false;
            $nextAnchor = $anchor;
            $execution->onProgress(function (Progress $update) use ($conversation, $question, $listener, $publishContent, $publishStage, &$anchor, &$nextAnchor, &$started): void {
                if (Progress::STAGE_MODEL_REQUEST === $update->getStage()) {
                    if (!$started) {
                        // The native input checkpoint now exists for acknowledgement snapshots.
                        $this->updates->publish($conversation, 'chat/start.stream.html.twig', ['user' => $question]);
                        $started = true;
                    }
                    $listener->reset();
                    $anchor = $nextAnchor;
                    $this->updates->publish($conversation, 'chat/round.stream.html.twig', ['user' => $question, 'anchor' => $anchor]);
                    $publishStage('Waiting for assistant…');
                }
                if (Progress::STAGE_DELTA === $update->getStage() && $update->getPayload() instanceof DeltaInterface) {
                    $delta = $update->getPayload();
                    $listener->accumulate($delta);
                    if ($delta instanceof ThinkingStart || $delta instanceof ThinkingDelta) {
                        $publishStage('Thinking…');
                    } elseif ($delta instanceof CommentaryDelta || ($delta instanceof TextDelta && Workspace::COMMENTARY_PHASE === $delta->getPhase())) {
                        $publishStage('Working…');
                    } elseif ($delta instanceof TextDelta) {
                        $publishStage('Writing answer…');
                    }
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
                    $publishStage(match (true) {
                        TenantSqlTool::QUERY_NAME === $tool->getName() => 'Querying financial records…',
                        ChartToolset::NAME.'_'.ChartToolset::INFO_TOOL === $tool->getName() => 'Discovering chart tools…',
                        str_starts_with($tool->getName(), ChartToolset::NAME.'_') => 'Creating chart…',
                        default => 'Running tools…',
                    });
                }
            });
            // Flush a throttled final delta before native final-history reconciliation.
            $execution->onResult(static function () use ($publishContent): void { $publishContent(true); });
            // This drives the one execution and returns its canonical native messages.
            $messages = $execution->getMessages();
            $terminal = $execution->getStatus();
        } catch (ConflictException $error) {
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $this->store->load($id), 'status' => ExecutionStatus::Failed, 'error' => 'This conversation changed during the response.']);
            throw $error;
        } catch (\Throwable) {
            if (null !== $execution && ExecutionStatus::Cancelled === $execution->getStatus()) {
                $terminal = ExecutionStatus::Cancelled;
                $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $this->store->load($id), 'status' => $terminal, 'error' => null]);

                return;
            }
            // Native Messenger retry/failure policy owns delivery; never log provider payloads.
            $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $this->store->load($id), 'status' => ExecutionStatus::Failed, 'error' => 'The response could not be completed.']);
            throw new \RuntimeException('The chat response could not be completed.');
        } finally {
            $this->control->finish($conversation->id, $turn, $terminal);
        }
        $this->updates->publish($conversation, 'chat/finish.stream.html.twig', ['user' => $question, 'messages' => $messages, 'status' => $execution->getStatus(), 'error' => null]);
    }
}
