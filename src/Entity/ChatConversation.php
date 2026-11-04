<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Native MessageBag representation; never passed to a template.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    public array $history = [];

    /** @var list<array{question: string, reasoning: string, commentary: string, answer: string, activity?: list<Entry>, charts?: list<Chart>}> */
    #[ORM\Column(type: 'json')]
    public array $turns = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $user,
        #[ORM\Column(length: 36, unique: true)]
        public readonly string $accountIdentity,
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
}
