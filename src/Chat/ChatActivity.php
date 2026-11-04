<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryComplete;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;

/** @phpstan-type Entry array{kind: string, text: string, call_id: ?string, name: ?string, arguments: ?string, result: ?string, status: ?string} */
final class ChatActivity
{
    public const string TOOL = 'tool';
    public const string REASONING = 'reasoning';
    public const string COMMENTARY = 'commentary';
    public const string PREPARING = 'preparing';
    public const string RUNNING = 'running';
    public const string COMPLETED = 'completed';
    public const string FAILED = 'failed';
    private const int DISPLAY_BYTES = 262144;

    /** @var list<Entry> */
    private array $entries = [];
    /** @var array<string, int> */
    private array $calls = [];
    private ?int $commentary = null;
    private ?int $reasoning = null;

    /** @return list<Entry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function beginRound(): void
    {
        $this->commentary = null;
        $this->reasoning = null;
    }

    public function observe(DeltaInterface $delta): void
    {
        if ($delta instanceof ToolCallStart) {
            $this->announced($delta->getId(), $delta->getName());
        } elseif ($delta instanceof ToolInputDelta) {
            $index = $this->announced($delta->getId(), $delta->getName());
            $this->entries[$index]['arguments'] = mb_strcut(($this->entries[$index]['arguments'] ?? '').$delta->getPartialJson(), 0, self::DISPLAY_BYTES, 'UTF-8');
        } elseif ($delta instanceof ThinkingDelta) {
            // The configured bridge emits these from readable reasoning-summary events only.
            $this->reasoning ??= $this->append(self::REASONING);
            $this->entries[$this->reasoning]['text'] .= $delta->getThinking();
        } elseif ($delta instanceof ThinkingComplete) {
            if ('' !== $delta->getThinking()) {
                $this->reasoning ??= $this->append(self::REASONING);
                $this->entries[$this->reasoning]['text'] = $delta->getThinking();
            }
            $this->reasoning = null;
        } elseif ($delta instanceof CommentaryDelta) {
            $this->commentary ??= $this->append(self::COMMENTARY);
            $this->entries[$this->commentary]['text'] .= $delta->getCommentary();
        } elseif ($delta instanceof CommentaryComplete) {
            if ('' !== $delta->getCommentary()) {
                $this->commentary ??= $this->append(self::COMMENTARY);
                $this->entries[$this->commentary]['text'] = $delta->getCommentary();
            }
        } elseif ($delta instanceof TextDelta && Workspace::COMMENTARY_PHASE === $delta->getPhase()) {
            $this->commentary ??= $this->append(self::COMMENTARY);
            $this->entries[$this->commentary]['text'] .= $delta->getText();
        } elseif ($delta instanceof MessageComplete) {
            $text = $delta->getContent();
            $phase = $text->getPhase() ?? (MessageItem::fromText($text)['phase'] ?? null);
            if (Workspace::COMMENTARY_PHASE === $phase) {
                $this->commentary ??= $this->append(self::COMMENTARY);
                // Completion replaces the streamed text; it must not append the same message again.
                $this->entries[$this->commentary]['text'] = $text->getText();
                $this->commentary = null;
            }
        }
    }

    public function started(ToolCall $call): void
    {
        $index = $this->announced($call->getId(), $call->getName());
        $this->entries[$index] = array_replace($this->entries[$index], [
            'call_id' => $call->getId(), 'name' => $call->getName(),
            'arguments' => $this->formatToolPayload($call->getArguments()), 'status' => self::RUNNING,
        ]);
        $this->beginRound();
    }

    public function completed(ToolResult $result): void
    {
        $index = $this->calls[$result->getToolCall()->getId()] ?? null;
        if (null === $index) {
            return;
        }
        $value = $result->getResult();
        $this->entries[$index]['result'] = $this->formatToolPayload($value);
        $this->entries[$index]['status'] = \is_array($value) && isset($value['error']) ? self::FAILED : self::COMPLETED;
    }

    public function failed(): void
    {
        foreach ($this->entries as &$entry) {
            if (\in_array($entry['status'], [self::PREPARING, self::RUNNING], true)) {
                $entry['status'] = self::FAILED;
                $entry['result'] = 'Tool execution did not complete.';
            }
        }
    }

    private function append(string $kind): int
    {
        $this->entries[] = ['kind' => $kind, 'text' => '', 'call_id' => null, 'name' => null, 'arguments' => null, 'result' => null, 'status' => null];

        return \count($this->entries) - 1;
    }

    private function announced(string $id, string $name): int
    {
        if (!isset($this->calls[$id])) {
            $index = $this->append(self::TOOL);
            $this->calls[$id] = $index;
            $this->entries[$index]['call_id'] = $id;
            $this->entries[$index]['name'] = $name;
            $this->entries[$index]['status'] = self::PREPARING;
        }

        return $this->calls[$id];
    }

    private function formatToolPayload(mixed $value): string
    {
        $json = json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

        return \strlen($json) > self::DISPLAY_BYTES ? mb_strcut($json, 0, self::DISPLAY_BYTES, 'UTF-8')."\n[Display truncated]" : $json;
    }
}
