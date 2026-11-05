<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @phpstan-import-type Entry from \App\Chat\ChatActivity
 * @phpstan-import-type Chart from \App\Chat\NativeAssistantOutput
 */
#[ORM\Entity]
class ChatConversation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 100)]
    public string $model;

    #[ORM\Column(length: 20)]
    public string $reasoningEffort;

    #[ORM\Column(options: ['default' => false])]
    public bool $saved = false;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $generationId = null;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $attemptId = null;

    #[ORM\Column(length: 20, enumType: ExecutionStatus::class, options: ['default' => ExecutionStatus::Completed->value])]
    public ExecutionStatus $generationStatus = ExecutionStatus::Completed;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    /**
     * Native MessageBag representation; never passed to a template.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    public array $history = [];

    /** @var list<array{question: string, reasoning: string, commentary: string, answer: string, activity?: list<Entry>, charts?: list<Chart>, execution?: string, error?: ?string}> */
    #[ORM\Column(type: 'json')]
    public array $turns = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $user,
        #[ORM\Column(length: 36, nullable: true)]
        public ?string $accountIdentity,
        string $model,
        string $reasoningEffort,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->model = $model;
        $this->reasoningEffort = $reasoningEffort;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isActive(): bool
    {
        return !$this->generationStatus->isFinished();
    }

    public function markGeneration(ExecutionStatus $status, ?string $error = null): void
    {
        $this->generationStatus = $status;
        $this->error = $error;
        if ([] !== $this->turns) {
            $index = \count($this->turns) - 1;
            $this->turns[$index]['execution'] = $status->value;
            $this->turns[$index]['error'] = $error;
        }
    }

    public function getLabel(): string
    {
        return $this->turns[0]['question'] ?? 'New chat';
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return UuidV7::fromString($this->id)->getDateTime();
    }
}
