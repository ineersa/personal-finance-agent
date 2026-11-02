<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class HomepageTest extends FinanceBrowserTest
{
    public function testLandingLinksToLogin(): void
    {
        $page = $this->visit('/');
        self::assertResponseIsSuccessful();
        self::assertSame('Make sense of your money.', $page->getByRole('heading', ['level' => 1])->textContent());
        $this->assertPageContains('demo@test.com');
        $page->getByRole('link', ['name' => 'Log in', 'exact' => true])->click();
        $heading = $page->getByRole('heading', ['name' => 'Personal finance login', 'exact' => true]);
        $heading->waitFor();
        self::assertTrue($heading->isVisible());
    }
}
