<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testChatConnectionGate(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        self::assertResponseIsSuccessful();
        self::assertTrue($page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->isVisible());
        self::assertTrue($page->getByRole('button', ['name' => 'Continue with ChatGPT', 'exact' => true])->isVisible());
        self::assertTrue($page->getByText('Connect ChatGPT to start a conversation.', ['exact' => false])->isVisible());
    }

    public function testConversationHistoryControls(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        $this->visit($page->getByRole('link', ['name' => 'New chat', 'exact' => true])->getAttribute('href'));
        self::assertSame(0, $page->locator('.chat-navigation-row.is-active')->count());

        $favourite = FixtureStore::getFixtures()['demo_chat_5']->id;
        $favouriteRow = $page->locator('.chat-navigation-row[data-conversation-id="'.$favourite.'"]');
        $this->visit($favouriteRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $favouriteRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $this->submit(static fn () => $favouriteRow->getByRole('button', ['name' => 'Favourite', 'exact' => true])->click());
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();
        $favouriteRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $this->submit(static fn () => $favouriteRow->getByRole('button', ['name' => 'Remove favourite', 'exact' => true])->click());
        $page->locator('.chat-chats-group .chat-navigation-row.is-active')->waitFor();
        $favouriteRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $this->submit(static fn () => $favouriteRow->getByRole('button', ['name' => 'Favourite', 'exact' => true])->click());
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();

        // Exercise a bookmarked older-page cursor using the same five canonical chats.
        $before = FixtureStore::getFixtures()['demo_chat_4']->id;
        $this->visit('/home/chat?conversation='.$favourite.'&'.\App\Twig\Components\ChatNavigation::FAVOURITES_BEFORE.'='.$before);
        self::assertSame(4, $page->locator('.chat-favourites-group .chat-navigation-row')->count());
        self::assertSame($favourite, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        $this->visit($page->locator('.chat-favourites-group')->getByRole('link', ['name' => 'Show latest', 'exact' => true])->getAttribute('href'));
        $other = FixtureStore::getFixtures()['demo_chat_1']->id;
        $this->visit($page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"] a.ea-sidebar-item-link')->getAttribute('href'));
        $this->visit($favouriteRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();
        $page->reload();
        self::assertSame($favourite, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        self::assertTrue($page->locator('.chat-answer p')->filter(['hasText' => 'provisional'])->isVisible());

        $this->visit($page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"] a.ea-sidebar-item-link')->getAttribute('href'));
        $page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"].is-active')->waitFor();
        $page->getByRole('button', ['name' => 'Search chats', 'exact' => true])->click();
        $page->getByRole('searchbox', ['name' => 'Search chats', 'exact' => true])->fill('provisional');
        $page->getByRole('searchbox', ['name' => 'Search chats', 'exact' => true])->press('Enter');
        $result = $page->locator('.chat-search-results li a[href*="conversation='.$favourite.'"]');
        $result->waitFor();
        self::assertTrue($result->locator('span')->filter(['hasText' => 'provisional'])->isVisible());
        $this->visit($result->getAttribute('href'));
        $page->locator('.chat-navigation-row[data-conversation-id="'.$favourite.'"].is-active')->waitFor();
        self::assertSame($favourite, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        $favouriteRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $favouriteRow->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        self::assertSame(0, $favouriteRow->count());
        self::assertSame(4, $page->locator('.chat-navigation-row')->count());
    }
}
