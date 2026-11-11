<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceNetworkBrowserTest;

final class AuthenticationTest extends FinanceNetworkBrowserTest
{
    public function testLoginAndLogout(): void
    {
        $page = $this->visit('/login');
        $page->getByLabel('Email', ['exact' => true])->fill('demo@test.com');
        $page->getByLabel('Password', ['exact' => true])->fill('demo');
        $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
        $page->locator('.finance-chat')->waitFor();
        self::assertSame('/home/chat', $page->evaluate('() => location.pathname'));
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click();
        $page->waitForURL('**/login');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
    }
}
