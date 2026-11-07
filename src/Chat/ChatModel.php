<?php

declare(strict_types=1);

namespace App\Chat;

/** ChatGPT choices curated from Hatfield's config/ai-catalog.yaml, not a provider registry. */
enum ChatModel: string
{
    case Gpt61Sol = 'gpt-6.1-sol';
    case Gpt6Astra = 'gpt-6-astra';
    case Gpt6Sol = 'gpt-6-sol';
    case Gpt6Luna = 'gpt-6-luna';
    case Gpt56Luna = 'gpt-5.6-luna';
    case Gpt56Sol = 'gpt-5.6-sol';
    case Gpt56Terra = 'gpt-5.6-terra';
    case Gpt53CodexSpark = 'gpt-5.3-codex-spark';

    public function label(): string
    {
        return match ($this) {
            self::Gpt61Sol => 'GPT-6.1 Sol',
            self::Gpt6Astra => 'GPT-6 Astra',
            self::Gpt6Sol => 'GPT-6 Sol',
            self::Gpt6Luna => 'GPT-6 Luna',
            self::Gpt56Luna => 'GPT-5.6 Luna',
            self::Gpt56Sol => 'GPT-5.6 Sol',
            self::Gpt56Terra => 'GPT-5.6 Terra',
            self::Gpt53CodexSpark => 'GPT-5.3 Codex Spark',
        };
    }

    /** @return list<ReasoningEffort> */
    public function efforts(): array
    {
        return match ($this) {
            self::Gpt6Sol, self::Gpt6Luna => ReasoningEffort::cases(),
            self::Gpt53CodexSpark => [ReasoningEffort::Low, ReasoningEffort::ExtraHigh],
            default => [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High, ReasoningEffort::ExtraHigh, ReasoningEffort::Max],
        };
    }

    public function validateEffort(ReasoningEffort $effort): void
    {
        if (!\in_array($effort, $this->efforts(), true)) {
            throw new \DomainException('Choose a supported reasoning level for this model.');
        }
    }
}
