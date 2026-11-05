<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Chat\Workspace;
use App\Entity\User;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChatNavigation
{
    public const int VISIBLE = 5;

    /** @var list<array{id: string, label: string, favourite: bool, active: bool}> */
    public array $chats = [];
    /** @var list<array{id: string, label: string, favourite: bool, active: bool}> */
    public array $favourites = [];
    public string $selected = '';

    public function __construct(private readonly Workspace $workspace)
    {
    }

    public function mount(User $owner, string $selected = ''): void
    {
        $this->selected = $selected;
        foreach ($this->workspace->navigation($owner) as $chat) {
            if ($chat['favourite']) {
                $this->favourites[] = $chat;
            } else {
                $this->chats[] = $chat;
            }
        }
    }
}
