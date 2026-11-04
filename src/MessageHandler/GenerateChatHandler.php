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
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
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
        if (null === $conversation) {
            return;
        }
        $owner = $conversation->getUser();
        if ($owner->getChatGptIdentity() !== $conversation->accountIdentity || null === $registration = $owner->getChatGptRegistrationId()) {
            return;
        }
        $execution = null;
        try {
            $output = new NativeAssistantOutput();
            $execution = $this->agents->create($registration, $conversation->model, $output)->call(
                $this->normalizer->denormalize($conversation->history, MessageBag::class),
                ['stream' => true, 'prompt_cache_key' => $conversation->id, 'reasoning' => ['effort' => $conversation->reasoningEffort, 'summary' => 'auto']],
            );
            $lastUpdate = 0.0;
            $turnIndex = \count($conversation->turns) - 1;
            $execution->onProgress(function (Progress $update) use ($execution, $conversation, $output, $turnIndex, &$lastUpdate): void {
                if (Progress::STAGE_DELTA === $update->getStage() && $update->getPayload() instanceof DeltaInterface) {
                    $output->observe($update->getPayload());
                    $conversation->turns[$turnIndex] = ['question' => $conversation->turns[$turnIndex]['question'], ...$output->transcript()];
                }
                if (microtime(true) - $lastUpdate >= self::UPDATE_INTERVAL) {
                    $this->updates->publish($conversation, $execution);
                    $lastUpdate = microtime(true);
                }
            });
            // Agent drives its own model/tool loop; callbacks only project its progress.
            $conversation->history = $this->normalizer->normalize($execution->getMessages());
            $this->em->flush();
            $this->updates->publish($conversation, $execution);
        } catch (\Throwable) {
            // Provider exceptions can contain private input or credentials.
            $this->updates->publish($conversation, $execution, 'The response could not be completed. Check your ChatGPT connection and model access before trying again.');
        }
    }
}
