<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
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
        self::assertSame(0, $page->locator('.app-sidebar, .composer-preview')->count());
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel=icon]')->getAttribute('href'));
        $this->submitLogin($page, 'demo@test.com', 'demo');
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
        self::assertSame('page', $page->getByRole('link', ['name' => 'Chat', 'exact' => true])->getAttribute('aria-current'));
        self::assertSame(0, $page->locator('a[href*="/planned/"]')->count());
        self::assertSame(0, $page->locator('.ea-sidebar [aria-disabled=true]')->count());
        $page = $this->visit('/home/settings');
        self::assertSame('Settings', trim($page->getByRole('heading', ['level' => 1])->textContent()));
        self::assertSame('page', $page->getByRole('link', ['name' => 'Settings', 'exact' => true])->getAttribute('aria-current'));
        self::assertNull($page->getByRole('link', ['name' => 'Chat', 'exact' => true])->getAttribute('aria-current'));
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Reporting currency')->evaluate('(select) => getComputedStyle(select).backgroundColor'));
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        foreach (['source', 'statement', 'transaction'] as $screen) {
            $page = $this->visit('/home/'.$screen);
            self::assertStringEndsWith('/home/'.$screen, $page->locator('.ea-sidebar-item-link[aria-current=page]')->getAttribute('href'));
            self::assertTrue($page->evaluate('() => document.body.classList.contains("ea-dark-scheme")'));
            self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
            self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel="shortcut icon"]')->getAttribute('href'));
            self::assertStringNotContainsString('Admin private', $page->locator('body')->textContent());
        }
        $page = $this->visit('/home/transaction');
        $this->assertPageContains('Synthetic October salary');
        $this->assertPageContains('Synthetic grocery purchase');
        self::assertStringNotContainsString('Synthetic incomplete draft', $page->locator('body')->textContent());
        $page = $this->visit('/home/statement');
        $this->assertPageContains('demo-october-2026.csv');
        self::assertSame(0, $page->locator('.action-new, .action-edit, .action-delete')->count());
        $foreign = $this->fixtures['admin_current'];
        self::assertInstanceOf(Source::class, $foreign);
        $this->visit('/home/source/'.$foreign->getId());
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        $page = $this->visit('/home/source');
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        $this->visit('/home/settings');
        $this->assertPageContains('Personal finance login');
    }

    public function testNativeAccountMenuLogsOutWithPostOnDesktopAndMobile(): void
    {
        foreach ([1280, 390] as $width) {
            $page = $this->visit('/login');
            $page->setViewportSize($width, 900);
            $this->submitLogin($page, 'demo@test.com', 'demo');
            $page = $this->visit('/home/source');
            $primary = $page->locator('.action-new');
            self::assertSame('rgb(237, 237, 238)', $primary->evaluate('(button) => getComputedStyle(button).backgroundColor'));
            self::assertSame('rgb(17, 17, 18)', $primary->evaluate('(button) => getComputedStyle(button).color'));
            $menu = $page->locator('.user-menu-wrapper:visible');
            self::assertSame(1, $menu->count());
            $toggle = $menu->locator('.user-details');
            self::assertSame('false', $toggle->getAttribute('aria-expanded'));
            $toggle->click();
            self::assertSame('true', $toggle->getAttribute('aria-expanded'));
            self::assertSame('demo@test.com', trim($menu->locator('.dropdown-user-details .user-name')->textContent()));
            self::assertSame(1, $menu->getByRole('link', ['name' => 'Settings', 'exact' => false])->count());
            $form = $menu->locator('form[action$="/logout"]');
            self::assertSame('post', $form->getAttribute('method'));
            self::assertNotEmpty($form->locator('input[name="_csrf_token"]')->inputValue());
            $logout = $menu->getByRole('button', ['name' => 'Log out', 'exact' => true]);
            $this->submit(function () use ($logout): void {
                $logout->click();
                $request = $this->getLastRequest();
                self::assertNotNull($request);
                self::assertSame('POST', $request->getMethod());
                self::assertSame('/logout', $request->getPathInfo());
                self::assertNotEmpty($request->request->get('_csrf_token'));
            });
            $page->waitForURL('**/login');
            $this->visit('/home/settings');
            $this->assertPageContains('Personal finance login');
        }
    }

    public function testAdminFixtureCatalogIconsAndNativeFinancePermissions(): void
    {
        $page = $this->visit('/login');
        $this->submitLogin($page, 'admin@test.com', 'admin');
        $page->waitForURL('**/home/user');
        $page = $this->visit('/home/category');
        $this->assertPageContains('Groceries');
        // Assert the native active route and visible label independently of its icon.
        $current = $page->locator('.ea-sidebar-item-link[aria-current=page]');
        $menu = $page->evaluate('() => Array.from(document.querySelectorAll(".ea-sidebar-item-link")).map(link => ({label: link.querySelector(".ea-sidebar-item-label")?.textContent.trim(), href: link.getAttribute("href"), current: link.getAttribute("aria-current")}))');
        self::assertSame(1, $current->count(), 'Native menu selection: '.json_encode($menu, \JSON_THROW_ON_ERROR));
        self::assertStringEndsWith('/home/category', $current->getAttribute('href'));
        self::assertSame('Categories', trim($current->locator('.ea-sidebar-item-label')->textContent()));
        self::assertSame(1, $page->locator('.category-icon i.fa-cart-shopping')->count());
        self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
        $page = $this->visit('/home/source');
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        self::assertStringNotContainsString('Demo current account', $page->locator('body')->textContent());
        $demo = $this->fixtures['demo_salary'];
        self::assertInstanceOf(Transaction::class, $demo);
        foreach (['', '/edit'] as $action) {
            $page = $this->visit('/home/transaction/'.$demo->getId().$action);
            self::assertSame(403, $this->getLastResponse()->getStatusCode());
            self::assertStringNotContainsString('Synthetic October salary', $page->locator('body')->textContent());
        }
        $page = $this->visit('/home/user');
        $this->assertPageContains('admin@test.com');
        $this->assertPageContains('demo@test.com');
        $page = $this->visit('/home/category/new');
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Name', ['exact' => true])->evaluate('(input) => getComputedStyle(input).backgroundColor'));
        foreach ([1280, 390] as $width) {
            $page->setViewportSize($width, 900);
            foreach (['user', 'category'] as $screen) {
                $page = $this->visit('/home/'.$screen);
                self::assertSame(['Users', 'Categories'], $page->evaluate('() => Array.from(document.querySelectorAll(".ea-sidebar-item-label")).map(label => label.textContent.trim())'));
                self::assertSame('pf', $page->locator('.app-brand:visible .brand-mark')->textContent());
                self::assertSame('A little more clarity.', $page->locator('.app-brand:visible .brand-caption')->textContent());
                $sidebarWidth = $page->locator('.ea-sidebar')->evaluate('(sidebar) => sidebar.getBoundingClientRect().width');
                // Native EasyAdmin reserves a stable scrollbar gutter outside the body's layout box.
                self::assertEquals($width > 991 ? 252 : $page->evaluate('() => document.body.getBoundingClientRect().width'), $sidebarWidth);
                self::assertStringContainsString('Built with Symfony', $page->locator('.workspace-footer')->textContent());
                self::assertSame(0, $page->locator('a[href$="/home/settings"], .composer-preview')->count());
                self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'));
            }
        }
    }

    public function testDarkOnlyManagementIgnoresSavedAndSystemLightPreferences(): void
    {
        $page = $this->visit('/login');
        $this->submitLogin($page, 'demo@test.com', 'demo');
        foreach ([1280, 390] as $width) {
            $page->setViewportSize($width, 900);
            foreach (['light', 'auto'] as $preference) {
                $page->emulateMedia(['colorScheme' => 'light']);
                $page->evaluate('(preference) => localStorage.setItem("ea/colorScheme", preference)', $preference);
                $page = $this->visit('/home/source/new');
                self::assertSame($preference, $page->evaluate('() => localStorage.getItem("ea/colorScheme")'));
                self::assertFalse($page->evaluate('() => matchMedia("(prefers-color-scheme: dark)").matches'));
                self::assertSame(0, $page->locator('.dropdown-settings, [data-ea-color-scheme], script[src*="page-color-scheme"]')->count());
                self::assertSame('dark', $page->locator('body')->getAttribute('data-bs-theme'));
                self::assertSame('dark', $page->evaluate('() => getComputedStyle(document.body).colorScheme'));
                self::assertTrue($page->evaluate('() => document.body.classList.contains("ea-dark-scheme") && !document.body.classList.contains("ea-light-scheme")'));
                self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
                self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Name', ['exact' => true])->evaluate('(input) => getComputedStyle(input).backgroundColor'));
                $page->emulateMedia(['colorScheme' => 'dark']);
                $page->emulateMedia(['colorScheme' => 'light']);
                self::assertTrue($page->evaluate('() => document.body.classList.contains("ea-dark-scheme")'));
                $menu = $page->locator('.user-menu-wrapper:visible');
                $menu->locator('.user-details')->click();
                self::assertSame(1, $menu->getByRole('link', ['name' => 'Settings', 'exact' => false])->count());
                self::assertTrue($menu->getByRole('button', ['name' => 'Log out', 'exact' => true])->isVisible());
            }
        }
    }

    public function testAdministratorCannotEnterOwnFinancesSettingsOrLegacyActions(): void
    {
        $administrator = $this->fixtures['administrator'];
        self::assertInstanceOf(User::class, $administrator);
        $administrator->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $source = $this->fixtures['admin_current'];
        $statement = $this->fixtures['admin_statement'];
        $transaction = $this->fixtures['admin_transaction'];
        self::assertInstanceOf(Source::class, $source);
        self::assertInstanceOf(Statement::class, $statement);
        self::assertInstanceOf(Transaction::class, $transaction);
        $page = $this->visit('/home/settings');
        $this->submitLogin($page, 'admin@test.com', 'admin');
        $page->waitForURL('**/home/user');
        foreach (['/home/chat', '/home/settings', '/home/source', '/home/source/new', '/home/source/'.$source->getId(), '/home/source/'.$source->getId().'/edit', '/home/statement', '/home/statement/'.$statement->getId(), '/home/transaction', '/home/transaction/'.$transaction->getId().'/edit', '/home?routeName=admin_source_index', '/home?routeName=admin_transaction_index', '/home?routeName=admin_chat', '/home?routeName=admin_settings'] as $path) {
            $page = $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
            self::assertStringNotContainsString('Admin private account', $page->locator('body')->textContent());
        }
        foreach (['/home/settings', '/home/source/new', '/home/source/'.$source->getId().'/edit', '/home/source/'.$source->getId().'/delete', '/home/transaction/'.$transaction->getId().'/edit', '/home?routeName=admin_source_new', '/home?routeName=admin_settings'] as $path) {
            $page = $this->visit('/home/category/new');
            $page->getByLabel('Name', ['exact' => true])->fill('Rejected role-boundary submission');
            $page->locator('form[name=Category]')->evaluate('(form, path) => { form.action = path; form.noValidate = true; }', $path);
            $this->save($page);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
        $page = $this->visit('/home/user/'.$administrator->getId().'/edit');
        self::assertSame(0, $page->locator('[name="User[reportingCurrency]"]')->count());
        $page = $this->visit('/home/category');
        self::assertStringNotContainsString('Rejected role-boundary submission', $page->locator('body')->textContent());
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('Admin private account', $connection->fetchOne('SELECT name FROM source WHERE id = ?', [$source->getId()]));
        self::assertSame('USD', $connection->fetchOne('SELECT reporting_currency FROM user WHERE id = ?', [$administrator->getId()]));
    }
}
