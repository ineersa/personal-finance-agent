<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\Length;

#[Exclude]
final readonly class ChatSearch
{
    public const string MODAL_ID = 'chat-search-modal';
    public const string FRAME_ID = 'chat-search-results';
    public const string ROUTE = 'app_chat_search';

    public function __construct(#[Length(max: 200)] public string $q = '')
    {
    }
}
