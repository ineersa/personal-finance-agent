<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Chat\Workspace;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChatNavigation
{
    public const int VISIBLE = 5;
    public const string FAVOURITES_BEFORE = 'favourites_before';
    public const string CHATS_BEFORE = 'chats_before';

    /** @var list<array{id: string, title: string, rows: list<array{id: string, label: string, favourite: bool}>, parameter: string, before: string, next: ?string, next_url: ?string, latest_url: string}> */
    public array $groups = [];
    public string $selected = '';

    public function __construct(private readonly ChatConversationRepository $conversations, private readonly RequestStack $requests, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function mount(User $owner, string $selected = ''): void
    {
        $this->selected = $selected;
        $request = $this->requests->getCurrentRequest();
        \assert(null !== $request);
        $route = $request->attributes->getString('_route');
        if (Workspace::SUBMIT_ROUTE === $route) {
            $route = Workspace::CHAT_ROUTE;
        }
        $parameters = array_replace($request->attributes->get('_route_params', []), $request->query->all());
        if (Workspace::CHAT_ROUTE === $route && '' !== $selected) {
            $parameters['conversation'] = $selected;
        }
        $selection = '' === $selected ? null : $this->conversations->navigationSelection($owner, $selected);
        foreach ([true, false] as $favourite) {
            $parameter = $favourite ? self::FAVOURITES_BEFORE : self::CHATS_BEFORE;
            $before = $this->requests->getCurrentRequest()?->query->getString($parameter) ?? '';
            $rows = $this->conversations->navigation($owner, $favourite, $before, self::VISIBLE + 1);
            $next = \count($rows) > self::VISIBLE ? $rows[self::VISIBLE - 1]['id'] : null;
            $rows = \array_slice($rows, 0, self::VISIBLE);
            // A distant selected chat costs one scalar lookup, not an expanded history page.
            if (null !== $selection && $selection['favourite'] === $favourite && !\in_array($selected, array_column($rows, 'id'), true)) {
                $rows[] = $selection;
            }
            $this->groups[] = ['id' => $favourite ? 'favourites' : 'chats', 'title' => $favourite ? 'Favourites' : 'Chats', 'rows' => $rows, 'parameter' => $parameter, 'before' => $before, 'next' => $next, 'next_url' => null === $next ? null : $this->urls->generate($route, array_replace($parameters, [$parameter => $next])), 'latest_url' => $this->urls->generate($route, array_replace($parameters, [$parameter => '']))];
        }
    }
}
