<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChatUpdates;
use App\Chat\NativeAssistantOutput;
use App\Entity\ChatConversation;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: GenerateChat::TRANSPORT)]
final readonly class GenerateChatHandler
{
    private const float UPDATE_INTERVAL = 0.1;

    public function __construct(private EntityManagerInterface $em, private AgentFactory $agents, private MessageBagNormalizer $normalizer, private ChatUpdates $updates)
    {
    }

    public function __invoke(GenerateChat $message): void
    {
        $conversation = $this->em->find(ChatConversation::class, $message->conversationId);
        if (null === $conversation) {
            return;
        }
        $owner = $conversation->getUser();
        if ($owner->getChatGptIdentity() !== $conversation->accountIdentity || null === $registration = $owner->getChatGptRegistrationId()) {
            return;
        }
        $execution = null;
        $output = new NativeAssistantOutput();
        $turnIndex = \count($conversation->turns) - 1;
        $lastUpdate = 0.0;
        $project = function (bool $force = false) use (&$execution, $conversation, $output, $turnIndex, &$lastUpdate): void {
            $conversation->turns[$turnIndex] = ['question' => $conversation->turns[$turnIndex]['question'], ...$output->transcript(), 'activity' => $output->activity->entries()];
            if ($force || microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                $this->updates->publish($conversation, $execution);
                $lastUpdate = microtime(true);
            }
        };
        $error = null;
        try {
            // Scoped native tool events project results privately; no global listener or payload logging.
            $events = new EventDispatcher();
            $events->addListener(ToolCallSucceeded::class, function (ToolCallSucceeded $event) use ($output, $project, $conversation, $turnIndex): void {
                $output->activity->completed($event->getResult());
                // Authorize a completed local image before Mercure tells the browser to load it.
                $conversation->turns[$turnIndex] = ['question' => $conversation->turns[$turnIndex]['question'], ...$output->transcript(), 'activity' => $output->activity->entries()];
                $this->em->flush();
                $project(true);
            });
            $execution = $this->agents->create($owner, $registration, $conversation->model, $output, $events, $conversation->id)->call(
                // Refresh application instructions, not the user's history or initial data context.
                $this->normalizer->denormalize($conversation->history, MessageBag::class)->withoutSystemMessage(),
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
            // Agent drives its own model/tool loop; callbacks only project its progress.
            $conversation->history = $this->normalizer->normalize($execution->getMessages());
            $project();
        } catch (\Throwable) {
            // Provider exceptions can contain private input or credentials.
            $output->activity->failed();
            $conversation->turns[$turnIndex] = ['question' => $conversation->turns[$turnIndex]['question'], ...$output->transcript(), 'activity' => $output->activity->entries()];
            $error = 'The response could not be completed. Check your ChatGPT connection and model access before trying again.';
        }
        $this->em->flush();
        $this->updates->publish($conversation, $execution, $error);
    }
}
