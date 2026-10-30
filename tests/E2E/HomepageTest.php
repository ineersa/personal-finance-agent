<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\HeadedBrowserTestCase;
use Playwright\Symfony\Client\BrowserRegistry;

final class HomepageTest extends HeadedBrowserTestCase
{
    public function testHomepage(): void
    {
        self::assertFalse(BrowserRegistry::fromEnvironment()->isHeadless());
        $page = $this->visit('/');
        self::assertResponseIsSuccessful();
        self::assertSame('Hello, world!', $page->getByRole('heading', ['level' => 1])->textContent());
        $this->assertPageContains('Symfony is running in Docker.');
    }
}
