<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;

/** Temporary native-message adaptation: https://github.com/ineersa/personal-finance-agent/issues/13 */
final class NativeAssistantOutput implements OutputProcessorInterface
{
    /** @var array<int, TextResult> */
    private array $messages = [];
    /** @var list<ThinkingResult> */
    private array $reasoning = [];
    private int $completedItems = 0;

    public function observe(DeltaInterface $delta): void
    {
        if ($delta instanceof MessageComplete) {
            $text = $delta->getContent();
            $index = $delta->getOutputIndex() ?? $this->completedItems;
            if (isset($this->messages[$index])) {
                throw new \RuntimeException('The native assistant response has ambiguous message ordering.');
            }
            $this->messages[$index] = new TextResult($text->getText(), $text->getSignature(), $text->getPhase());
            ++$this->completedItems;
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
