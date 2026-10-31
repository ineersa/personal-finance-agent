<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class AuthenticationTest extends FinanceBrowserTest
{
    public function testRealLoginLogoutAndProtectedPages(): void
    {
        $page = $this->visit('/admin/source');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
        $this->submitLogin($page, 'alice@example.test', 'incorrect-password');
        $this->assertPageContains('Invalid credentials');
        $this->submitLogin($page, 'alice@example.test', self::PASSWORD);
        $page->waitForURL('**/admin/source');
        $this->assertPageContains('Alice account');
        self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
        $page = $this->visit('/settings');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        $this->visit('/settings');
        $this->assertPageContains('Personal finance login');
    }

    public function testLoginThrottlingCannotBeBypassedByForwardedHeaders(): void
    {
        $page = $this->visit('/login');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->submitLogin($page, 'alice@example.test', 'incorrect-password');
            $this->assertPageContains('Invalid credentials');
        }
        // Browser supplies hostile headers. No configured proxy may promote them.
        $page->setExtraHTTPHeaders(['X-Forwarded-For' => '203.0.113.55', 'Forwarded' => 'for=203.0.113.55']);
        $this->submitLogin($page, 'alice@example.test', self::PASSWORD);
        $this->assertPageContains('Too many failed login attempts');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
    }
}
