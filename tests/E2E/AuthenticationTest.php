<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;

final class AuthenticationTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testLoginAndLogout(): void
    {
        $page = $this->visit('/login');
        $page->getByLabel('Email', ['exact' => true])->fill('demo@test.com');
        $page->getByLabel('Password', ['exact' => true])->fill('demo');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());
        $page->locator('.finance-chat .chat-empty')->waitFor();
        self::assertSame('/home/chat', $page->evaluate('() => location.pathname'));
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
    }
}
