<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceNetworkBrowserTest;

final class UserCrudTest extends FinanceNetworkBrowserTest
{
    private const string PASSWORD = 'Synthetic-browser-password-05';
    private const string CHANGED_PASSWORD = 'Changed-synthetic-password-05';

    public function testCreateEditPasswordAndDeleteUser(): void
    {
        $this->login('admin@test.com', 'admin');
        $suffix = bin2hex(random_bytes(6));
        $email = 'created-'.$suffix.'@example.test';
        $editedEmail = 'renamed-'.$suffix.'@example.test';
        $page = $this->visit('/home/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
        $page = $this->visit('/home/user?query='.rawurlencode($email));
        $this->assertPageContains($email);
        $userRow = $page->locator('tbody tr')->filter(['hasText' => $email]);
        $userUrl = $userRow->locator('a.action-edit')->getAttribute('href');
        $userDetailUrl = $userRow->locator('a.action-detail')->getAttribute('href');
        try {
            $page->goto($userUrl);
            $page->getByLabel('Email', ['exact' => true])->fill($editedEmail);
            $page->getByLabel('New password', ['exact' => true])->fill(self::CHANGED_PASSWORD);
            $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
            $page->goto($userUrl);
            self::assertSame($editedEmail, $page->getByLabel('Email', ['exact' => true])->inputValue());
            $this->logout();
            $this->login($editedEmail, self::CHANGED_PASSWORD);
            $page->locator('.finance-chat .chat-empty')->waitFor();
            self::assertTrue($page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->isVisible());
            self::assertTrue($page->getByRole('button', ['name' => 'Continue with ChatGPT', 'exact' => true])->isVisible());
            self::assertTrue($page->getByText('Connect ChatGPT to start a conversation.', ['exact' => false])->isVisible());
        } finally {
            $this->login('admin@test.com', 'admin');
            $this->deleteRecord($userDetailUrl);
        }
        $page = $this->visit('/home/user?query='.rawurlencode($editedEmail));
        self::assertStringNotContainsString($editedEmail, $page->locator('tbody')->textContent());
    }
}
