<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Chat\Workspace;
use App\Entity\User;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChatNavigation
{
    public const int SAVED_VISIBLE = 5;

    /** @var list<array{id: string, label: string, saved: bool, active: bool}> */
    public array $temporary = [];
    /** @var list<array{id: string, label: string, saved: bool, active: bool}> */
    public array $saved = [];
    public string $selected = '';

    public function __construct(private readonly Workspace $workspace)
    {
    }

    public function mount(User $owner, string $selected = ''): void
    {
        $this->selected = $selected;
        foreach ($this->workspace->navigation($owner) as $chat) {
            if ($chat['saved']) {
                $this->saved[] = $chat;
            } else {
                $this->temporary[] = $chat;
            }
        }
    }
}
