<?php

declare(strict_types=1);

namespace App\Chat;

final class ChatSearch
{
    public const string QUERY_PARAMETER = 'q';
    public const string MODAL_ID = 'chat-search-modal';
    public const string FRAME_ID = 'chat-search-results';
    public const string ROUTE = 'app_chat_search';
    public const int RESULT_LIMIT = 20;
    public const int QUERY_LENGTH = 200;
}
