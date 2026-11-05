<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;

/**
 * Temporary native-message adaptation: https://github.com/ineersa/personal-finance-agent/issues/13.
 *
 * @phpstan-import-type Entry from ChatActivity
 *
 * @phpstan-type Chart array{file: ?string, error: ?string}
 */
final class NativeAssistantOutput implements OutputProcessorInterface
{
    /** @var list<Chart> */
    public array $charts = [];

    /** @var array<int, TextResult> */
    private array $messages = [];
    /** @var list<ThinkingResult> */
    private array $reasoning = [];
    private int $completedItems = 0;
    /** @var array{reasoning: string, commentary: string, answer: string} */
    private array $transcript = ['reasoning' => '', 'commentary' => '', 'answer' => ''];
    /** @var array<string, list<string>> */
    private array $completedText = [];

    public function __construct(public readonly ChatActivity $activity = new ChatActivity())
    {
    }

    /**
     * Discard interrupted streaming fragments; replay only the native durable boundary.
     *
     * @param list<Chart> $charts   already authorized private chart references survive retries
     * @param list<Entry> $activity previously projected tool display
     *
     * @return bool whether the current turn already has a persisted final answer
     */
    public function restore(MessageBag $history, array $charts, array $activity): bool
    {
        $messages = [];
        foreach ($history as $message) {
            if ($message instanceof UserMessage) {
                $messages = [];
            } else {
                $messages[] = $message;
            }
        }
        $this->charts = $charts;
        $this->activity->restore($messages, $activity);
        $last = null;
        foreach ($messages as $message) {
            $last = $message;
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->getContent() as $part) {
                if ($part instanceof Thinking) {
                    $this->transcript['reasoning'] .= $part->getContent();
                } elseif ($part instanceof Text) {
                    $phase = $part->getPhase() ?? (MessageItem::fromText($part)['phase'] ?? null);
                    $field = Workspace::COMMENTARY_PHASE === $phase ? 'commentary' : 'answer';
                    $this->completedText[$field][] = $part->getText();
                    $this->transcript[$field] = implode("\n\n", $this->completedText[$field]);
                }
            }
        }

        // A tool round ends in ToolCallMessage, not AssistantMessage. Reasoning/commentary
        // alone are not a final answer, and messages before the last user never qualify.
        if ($last instanceof AssistantMessage && !$last->hasToolCalls()) {
            foreach ($last->getContent() as $part) {
                if ($part instanceof Text && '' !== $part->getText() && Workspace::COMMENTARY_PHASE !== ($part->getPhase() ?? (MessageItem::fromText($part)['phase'] ?? null))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{reasoning: string, commentary: string, answer: string, charts: list<Chart>} */
    public function transcript(): array
    {
        return [...$this->transcript, 'charts' => $this->charts];
    }

    public function beginRound(): void
    {
        $this->activity->beginRound();
        // Provider output indices restart on each native tool-loop round.
        $this->messages = [];
        $this->reasoning = [];
        $this->completedItems = 0;
    }

    public function observe(DeltaInterface $delta): void
    {
        $this->activity->observe($delta);
        if ($delta instanceof MessageComplete) {
            $text = $delta->getContent();
            $phase = $text->getPhase() ?? (MessageItem::fromText($text)['phase'] ?? null);
            $index = $delta->getOutputIndex() ?? $this->completedItems;
            if (isset($this->messages[$index])) {
                throw new \RuntimeException('The native assistant response has ambiguous message ordering.');
            }
            $this->messages[$index] = new TextResult($text->getText(), $text->getSignature(), $phase);
            $field = Workspace::COMMENTARY_PHASE === $phase ? 'commentary' : 'answer';
            $this->completedText[$field][] = $text->getText();
            $this->transcript[$field] = implode("\n\n", $this->completedText[$field]);
            ++$this->completedItems;
        } elseif ($delta instanceof TextDelta) {
            $field = Workspace::COMMENTARY_PHASE === $delta->getPhase() ? 'commentary' : 'answer';
            $this->transcript[$field] .= $delta->getText();
        } elseif ($delta instanceof CommentaryDelta) {
            $this->transcript['commentary'] .= $delta->getCommentary();
        } elseif ($delta instanceof ThinkingDelta) {
            $this->transcript['reasoning'] .= $delta->getThinking();
        }
        if ($delta instanceof ThinkingSignature) {
            // One full native reasoning item, not one item per readable summary fragment.
            $item = json_decode($delta->getSignature(), true, flags: \JSON_THROW_ON_ERROR);
            $summary = implode("\n\n", array_column($item['summary'] ?? [], 'text'));
            $this->reasoning[] = new ThinkingResult($summary, $delta->getSignature());
            ++$this->completedItems;
        }
    }

    public function processOutput(Output $output): void
    {
        /** @var list<ResultInterface> $parts */
        $parts = [];
        $reasoning = $this->reasoning;
        $count = \count($this->messages) + \count($reasoning);
        for ($index = 0; $index < $count; ++$index) {
            $part = $this->messages[$index] ?? array_shift($reasoning);
            if (null === $part) {
                throw new \RuntimeException('The native assistant response could not be preserved.');
            }
            $parts[] = $part;
        }
        if ([] === $parts || [] !== $reasoning || \count($this->messages) !== \count(array_filter($parts, static fn (ResultInterface $part): bool => $part instanceof TextResult))) {
            throw new \RuntimeException('The native assistant response could not be preserved.');
        }
        $result = new MultiPartResult($parts);
        $result->getMetadata()->merge($output->getResult()->getMetadata());
        $output->setResult($result);
    }
}
