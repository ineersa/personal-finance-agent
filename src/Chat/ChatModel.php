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

    public function label(): string
    {
        return match ($this) {
            self::Gpt61Sol => 'GPT-6.1 Sol',
            self::Gpt6Astra => 'GPT-6 Astra',
            self::Gpt6Sol => 'GPT-6 Sol',
            self::Gpt6Luna => 'GPT-6 Luna',
        };
    }

    /** @return list<ReasoningEffort> */
    public function efforts(): array
    {
        return match ($this) {
            self::Gpt6Sol, self::Gpt6Luna => ReasoningEffort::cases(),
            self::Gpt61Sol, self::Gpt6Astra => [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High, ReasoningEffort::ExtraHigh, ReasoningEffort::Max],
        };
    }

    public function validateEffort(ReasoningEffort $effort): void
    {
        if (!\in_array($effort, $this->efforts(), true)) {
            throw new \DomainException('Choose a supported reasoning level for this model.');
        }
    }
}
