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
        $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click();
        $active = $page->locator('.chat-navigation-row.is-active');
        $active->waitFor();
        $saved = $active->getAttribute('data-conversation-id');
        self::assertNotEmpty($saved);
        $active->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $active->getByRole('button', ['name' => 'Save chat', 'exact' => true])->click();
        $page->locator('.chat-saved-group .chat-navigation-row.is-active')->waitFor();

        $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click();
        $page->locator('.chat-navigation > section:first-of-type .chat-navigation-row.is-active')->waitFor();
        self::assertSame(2, $page->locator('.chat-navigation-row')->count());
        $savedRow = $page->locator('.chat-navigation-row[data-conversation-id="'.$saved.'"]');
        $savedRow->locator('a.ea-sidebar-item-link')->click();
        $page->locator('.chat-saved-group .chat-navigation-row.is-active')->waitFor();
        $page->reload();
        self::assertSame($saved, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        $savedRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $savedRow->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        self::assertSame(0, $savedRow->count());
        self::assertSame(1, $page->locator('.chat-navigation-row')->count());
    }
}
