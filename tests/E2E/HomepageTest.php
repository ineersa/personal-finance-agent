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
        self::assertSame('Make sense of your money.', $page->getByRole('heading', ['level' => 1])->textContent());
        $this->assertPageContains('Preview only. Chat and statement uploads are not available yet.');
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isDisabled());
        self::assertTrue($page->getByRole('textbox', ['name' => 'Your financial question'])->isDisabled());
        $page->getByRole('link', ['name' => 'Chat', 'exact' => true])->click();
        self::assertSame('Make sense of your money.', $page->getByRole('heading', ['level' => 1])->textContent());
    }

    public function testResponsiveLayout(): void
    {
        $page = $this->visit('/');

        foreach ([1440, 390] as $width) {
            $page->setViewportSize($width, 900);
            self::assertSame($width > 760 ? 'row' : 'column', $page->evaluate("() => getComputedStyle(document.querySelector('.finance-app')).flexDirection"));
            self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'));
            self::assertTrue($page->getByRole('heading', ['level' => 1])->isVisible());
        }
    }
}
