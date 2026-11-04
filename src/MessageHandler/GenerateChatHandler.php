<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Chat\AgentFactory;
use App\Chat\ChatUpdates;
use App\Chat\NativeAssistantOutput;
use App\Chat\Workspace;
use App\Entity\ChatConversation;
use App\Enum\ProcessingStatus;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
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
        if (null === $conversation || $conversation->getUser()->getId() !== $message->ownerId || $conversation->accountIdentity !== $message->accountIdentity) {
            return;
        }
        // Claim once before any provider call. Duplicate delivery does not repeat inference.
        $claimed = $this->em->getConnection()->update('chat_conversation', ['status' => ProcessingStatus::Running->value], ['id' => $message->conversationId, 'generation_id' => $message->generationId, 'status' => ProcessingStatus::Queued->value]);
        if (1 !== $claimed) {
            return;
        }
        $this->em->refresh($conversation);
        $this->em->refresh($conversation->getUser());
        try {
            if ($conversation->getUser()->getChatGptIdentity() !== $message->accountIdentity || \in_array(Workspace::ADMIN_ROLE, $conversation->getUser()->getRoles(), true)) {
                throw new \DomainException('The connected account changed.');
            }
            $output = new NativeAssistantOutput();
            $agent = $this->agents->create($conversation->getUser()->getChatGptRegistrationId() ?? throw new \DomainException('The connected account changed.'), $conversation->model, $output);
            $execution = $agent->call($this->normalizer->denormalize($conversation->history, MessageBag::class), [
                'stream' => true,
                'prompt_cache_key' => $conversation->id,
                'reasoning' => ['effort' => $conversation->reasoningEffort, 'summary' => 'auto'],
            ]);
            $this->updates->publish($conversation);
            $lastUpdate = microtime(true);
            $turnIndex = \count($conversation->turns) - 1;
            $completed = [];
            foreach ($execution as $update) {
                if (!$update instanceof Progress || Progress::STAGE_DELTA !== $update->getStage()) {
                    continue;
                }
                $delta = $update->getPayload();
                if (!$delta instanceof DeltaInterface) {
                    continue;
                }
                $output->observe($delta);
                if ($delta instanceof ThinkingDelta) {
                    $conversation->turns[$turnIndex]['reasoning'] .= $delta->getThinking();
                }
                if ($delta instanceof CommentaryDelta) {
                    $conversation->turns[$turnIndex]['commentary'] .= $delta->getCommentary();
                }
                // Complete native messages also cover terminal-only text; do not duplicate deltas.
                if ($delta instanceof MessageComplete) {
                    $text = $delta->getContent();
                    $field = Workspace::COMMENTARY_PHASE === $text->getPhase() ? 'commentary' : 'answer';
                    $completed[$field][] = $text->getText();
                    $conversation->turns[$turnIndex][$field] = implode("\n\n", $completed[$field]);
                } elseif ($delta instanceof TextDelta) {
                    $field = Workspace::COMMENTARY_PHASE === $delta->getPhase() ? 'commentary' : 'answer';
                    $conversation->turns[$turnIndex][$field] .= $delta->getText();
                }
                if (microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                    $this->updates->publish($conversation);
                    $lastUpdate = microtime(true);
                }
            }
            $conversation->history = $this->normalizer->normalize($execution->getMessages());
            $conversation->status = ProcessingStatus::Completed;
        } catch (\Throwable) {
            // Provider errors may contain input or credentials. Never log exception chains.
            $conversation->status = ProcessingStatus::Failed;
            $conversation->error = 'The response could not be completed. Check your ChatGPT connection and model access before trying again.';
        }
        $this->em->flush();
        $this->updates->publish($conversation);
    }
}
