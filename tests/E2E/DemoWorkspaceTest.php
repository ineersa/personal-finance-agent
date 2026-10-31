<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Source;
use App\Entity\Transaction;
use App\Tests\Support\FinanceBrowserTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    /** @var array<string, object> */
    private array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertNotNull(self::$kernel);
        // Native append loading stays inside DAMA's existing rollback transaction.
        $this->fixtures = self::getContainer()->get('hautelook_alice.loader')->load(
            new Application(self::$kernel),
            self::getContainer()->get(EntityManagerInterface::class),
            [], 'test', true, false, true,
        );
    }

    public function testDemoLoginSettingsAndFinanceRoutesShareDarkWorkspace(): void
    {
        $page = $this->visit('/login');
        self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Email', ['exact' => true])->evaluate('(input) => getComputedStyle(input).backgroundColor'));
        self::assertSame('page', $page->getByRole('link', ['name' => 'Log in', 'exact' => true])->getAttribute('aria-current'));
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel=icon]')->getAttribute('href'));
        $this->submitLogin($page, 'demo@test.com', 'demo');
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
        self::assertSame('page', $page->getByRole('link', ['name' => 'Chat', 'exact' => true])->getAttribute('aria-current'));
        self::assertSame(0, $page->locator('a[href*="/planned/"]')->count());
        self::assertSame(2, $page->locator('.app-nav [aria-disabled=true]')->count());
        $page = $this->visit('/settings');
        self::assertSame('Settings', trim($page->locator('.workspace-header')->textContent()));
        self::assertSame('page', $page->getByRole('link', ['name' => 'Settings', 'exact' => true])->getAttribute('aria-current'));
        self::assertNull($page->getByRole('link', ['name' => 'Chat', 'exact' => true])->getAttribute('aria-current'));
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Reporting currency')->evaluate('(select) => getComputedStyle(select).backgroundColor'));
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $page = $this->visit('/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        foreach (['source', 'statement', 'transaction'] as $screen) {
            $page = $this->visit('/admin/'.$screen);
            self::assertStringEndsWith('/admin/'.$screen, $page->locator('.ea-sidebar-item-link[aria-current=page]')->getAttribute('href'));
            self::assertTrue($page->evaluate('() => document.body.classList.contains("ea-dark-scheme")'));
            self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
            self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel="shortcut icon"]')->getAttribute('href'));
            self::assertStringNotContainsString('Admin private', $page->locator('body')->textContent());
        }
        $page = $this->visit('/admin/transaction');
        $this->assertPageContains('Synthetic October salary');
        $this->assertPageContains('Synthetic grocery purchase');
        self::assertStringNotContainsString('Synthetic incomplete draft', $page->locator('body')->textContent());
        $page = $this->visit('/admin/statement');
        $this->assertPageContains('demo-october-2026.csv');
        self::assertSame(0, $page->locator('.action-new, .action-edit, .action-delete')->count());
        $foreign = $this->fixtures['admin_current'];
        self::assertInstanceOf(Source::class, $foreign);
        $this->visit('/admin/source/'.$foreign->getId());
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        $page = $this->visit('/admin/source');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        $this->visit('/settings');
        $this->assertPageContains('Personal finance login');
    }

    public function testAdminFixtureCatalogIconsAndNativeFinancePermissions(): void
    {
        $page = $this->visit('/login');
        $this->submitLogin($page, 'admin@test.com', 'admin');
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
        $page = $this->visit('/admin/category');
        $this->assertPageContains('Groceries');
        // Assert the native active route and visible label independently of its icon.
        $current = $page->locator('.ea-sidebar-item-link[aria-current=page]');
        $menu = $page->evaluate('() => Array.from(document.querySelectorAll(".ea-sidebar-item-link")).map(link => ({label: link.querySelector(".ea-sidebar-item-label")?.textContent.trim(), href: link.getAttribute("href"), current: link.getAttribute("aria-current")}))');
        self::assertSame(1, $current->count(), 'Native menu selection: '.json_encode($menu, \JSON_THROW_ON_ERROR));
        self::assertStringEndsWith('/admin/category', $current->getAttribute('href'));
        self::assertSame('Categories', trim($current->locator('.ea-sidebar-item-label')->textContent()));
        self::assertSame(1, $page->locator('.category-icon i.fa-cart-shopping')->count());
        self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
        $page = $this->visit('/admin/source');
        $this->assertPageContains('Admin private account');
        self::assertStringNotContainsString('Demo current account', $page->locator('body')->textContent());
        $demo = $this->fixtures['demo_salary'];
        self::assertInstanceOf(Transaction::class, $demo);
        foreach (['', '/edit'] as $action) {
            $page = $this->visit('/admin/transaction/'.$demo->getId().$action);
            self::assertSame(403, $this->getLastResponse()->getStatusCode());
            self::assertStringNotContainsString('Synthetic October salary', $page->locator('body')->textContent());
        }
        $page = $this->visit('/admin/user');
        $this->assertPageContains('admin@test.com');
        $this->assertPageContains('demo@test.com');
        $page = $this->visit('/admin/category/new');
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Name', ['exact' => true])->evaluate('(input) => getComputedStyle(input).backgroundColor'));
    }
}
