<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Playwright\Symfony\Test\PlaywrightTestCase;

final class HomepageTest extends PlaywrightTestCase
{
    public function testHomepage(): void
    {
        $page = $this->visit('/');
        self::assertResponseIsSuccessful();
        self::assertSame('Hello, world!', $page->getByRole('heading', ['level' => 1])->textContent());
        $this->assertPageContains('Symfony is running in Docker.');
    }
}
