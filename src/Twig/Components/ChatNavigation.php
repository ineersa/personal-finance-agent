<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Chat\ChatSearch;
use App\Chat\Workspace;
use App\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChatNavigation
{
    public const int SAVED_VISIBLE = 5;

    /** @var list<array{id: string, label: string, saved: bool, active: bool, has_turn: bool}> */
    public array $temporary = [];
    /** @var list<array{id: string, label: string, saved: bool, active: bool, has_turn: bool}> */
    public array $saved = [];
    public string $selected = '';
    public string $query = '';
    /** @var list<array{id: string, label: string, snippet: string}> */
    public array $results = [];

    public function __construct(private readonly Workspace $workspace, private readonly ChatSearch $search, private readonly RequestStack $requests)
    {
    }

    public function mount(User $owner, string $selected = ''): void
    {
        $this->selected = $selected;
        $this->query = mb_substr(trim($this->requests->getCurrentRequest()?->query->getString(ChatSearch::QUERY_PARAMETER) ?? ''), 0, ChatSearch::QUERY_LENGTH);
        if ('' !== $this->query) {
            $this->results = $this->search->find($owner, $this->query);
        }
        foreach ($this->workspace->navigation($owner) as $chat) {
            if ($chat['saved']) {
                $this->saved[] = $chat;
            } else {
                $this->temporary[] = $chat;
            }
        }
    }
}
