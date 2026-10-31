<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Playwright\Page\PageInterface;

final class AuthenticationTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    private const string PASSWORD = 'demo';

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        try {
            // Limiter state is outside Alice's database rollback and shares the demo identity.
            self::getContainer()->get('cache.rate_limiter')->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function testRealLoginLogoutAndProtectedPages(): void
    {
        $page = $this->visit('/home/source');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
        $this->submitLogin($page, 'demo@test.com', 'incorrect-password');
        $this->assertPageContains('Invalid credentials');
        $this->submitLogin($page, 'demo@test.com', self::PASSWORD);
        $page->locator('.finance-chat .chat-empty')->waitFor();
        $page = $this->visit('/home/source');
        $this->assertPageContains('Demo current account');
        self::assertStringNotContainsString('Admin private account', $page->locator('body')->textContent());
        $page = $this->visit('/home/settings');
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        $this->visit('/home/settings');
        $this->assertPageContains('Personal finance login');
    }

    public function testLoginAlwaysUsesRoleEntryInsteadOfSavedSettingsTarget(): void
    {
        $page = $this->visit('/home/settings');
        $this->submitLogin($page, 'demo@test.com', self::PASSWORD);
        $page->locator('.finance-chat .chat-empty')->waitFor();
        self::assertSame('/home/chat', $page->evaluate('() => location.pathname'));
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page = $this->visit('/home/settings');
        $page->evaluate('() => { const input = document.createElement("input"); input.type = "hidden"; input.name = "_target_path"; input.value = "/home/settings"; document.querySelector("form").append(input); }');
        $this->submitLogin($page, 'admin@test.com', 'admin');
        $page->waitForURL('**/home/user');
        self::assertSame(0, $page->locator('.ea-sidebar-item-link[href$="/home/settings"], .composer-preview')->count());
        foreach (['/', '/home'] as $entry) {
            $page = $this->visit($entry);
            $page->waitForURL('**/home/user');
        }
        foreach (['/admin', '/admin/source', '/admin/user', '/settings'] as $removed) {
            $this->visit($removed);
            self::assertSame(404, $this->getLastResponse()->getStatusCode());
        }
    }

    public function testLoginThrottlingCannotBeBypassedByForwardedHeaders(): void
    {
        $page = $this->visit('/login');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->submitLogin($page, 'demo@test.com', 'incorrect-password');
            $this->assertPageContains('Invalid credentials');
        }
        // Browser supplies hostile headers. No configured proxy may promote them.
        $page->setExtraHTTPHeaders(['X-Forwarded-For' => '203.0.113.55', 'Forwarded' => 'for=203.0.113.55']);
        $this->submitLogin($page, 'demo@test.com', self::PASSWORD);
        $this->assertPageContains('Too many failed login attempts');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
    }

    private function submitLogin(PageInterface $page, string $email, string $password): void
    {
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());
    }
}
