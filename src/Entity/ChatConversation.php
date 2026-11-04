<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProcessingStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class ChatConversation extends UserRecord
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $generationId = null;

    #[ORM\Column(length: 100)]
    public string $model;

    #[ORM\Column(length: 20)]
    public string $reasoningEffort;

    /**
     * Native MessageBag representation; never passed to a template.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    public array $history = [];

    /** @var list<array{question: string, reasoning: string, commentary: string, answer: string}> */
    #[ORM\Column(type: 'json')]
    public array $turns = [];

    #[ORM\Column(enumType: ProcessingStatus::class)]
    public ProcessingStatus $status = ProcessingStatus::Completed;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    public function __construct(
        User $user,
        #[ORM\Column(length: 36, unique: true)]
        public readonly string $accountIdentity,
        string $model,
        string $reasoningEffort,
    ) {
        parent::__construct($user);
        $this->id = Uuid::v7()->toRfc4122();
        $this->model = $model;
        $this->reasoningEffort = $reasoningEffort;
    }

    public function busy(): bool
    {
        return \in_array($this->status, [ProcessingStatus::Queued, ProcessingStatus::Running], true);
    }
}
