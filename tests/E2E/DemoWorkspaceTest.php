<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Playwright\Page\PageInterface;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testFinanceNavigationShowsApprovedRecordsAndReadOnlyStatements(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        self::assertTrue($page->locator('.ea-sidebar')->getByText('Finance management', ['exact' => true])->isVisible());
        self::assertSame(0, $page->locator('a[href*="/planned/"], .ea-sidebar [aria-disabled=true], .sidebar-note, .ea-sidebar-footer')->count());
        self::assertStringNotContainsString('Work in progress', $page->locator('.ea-sidebar')->textContent());
        self::assertStringNotContainsString('Manage sources and approved transactions.', $page->locator('.ea-sidebar')->textContent());
        $page = $this->visit('/home/transaction');
        $this->assertPageContains('Synthetic October salary');
        $this->assertPageContains('Synthetic grocery purchase');
        self::assertStringNotContainsString('Synthetic incomplete draft', $page->locator('body')->textContent());
        $page = $this->visit('/home/statement');
        self::assertResponseIsSuccessful();
        $this->assertPageContains('demo-october-2026.csv');
        self::assertSame(0, $page->locator('.action-new, .action-edit, .action-delete')->count());
        $page = $this->visit('/home/statement/'.FixtureStore::getFixtures()['demo_statement']->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $page->locator('.action-new, .action-edit, .action-delete')->count());
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

    public function testAdministratorNavigationContainsOnlyUserAndCategoryManagement(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['administrator']);
        foreach ([1280, 390] as $width) {
            $this->getPage()->setViewportSize($width, 900);
            foreach (['user', 'category'] as $screen) {
                $page = $this->visit('/home/'.$screen);
                self::assertResponseIsSuccessful();
                self::assertSame(['Users', 'Categories'], $page->evaluate('() => Array.from(document.querySelectorAll(".ea-sidebar-item-label")).map(label => label.textContent.trim())'));
                self::assertSame(0, $page->locator('.sidebar-note, .ea-sidebar-footer, a[href$="/home/settings"], .composer-preview')->count());
                $current = $page->locator('.ea-sidebar-item-link[aria-current=page]');
                self::assertSame(1, $current->count());
                self::assertStringEndsWith('/home/'.$screen, $current->getAttribute('href'));
                self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'));
            }
        }
        $this->assertPageContains('Groceries');
        self::assertSame(1, $page->locator('.category-icon i.fa-cart-shopping')->count());
    }

    public function testDarkOnlyManagementIgnoresSavedAndSystemLightPreferences(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
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
                $page = $this->visit('/home/settings');
                self::assertSame('dark', $page->locator('body')->getAttribute('data-bs-theme'));
                self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Reporting currency')->evaluate('(select) => getComputedStyle(select).backgroundColor'));
                $menu = $page->locator('.user-menu-wrapper:visible');
                $menu->locator('.user-details')->click();
                self::assertSame(1, $menu->getByRole('link', ['name' => 'Settings', 'exact' => false])->count());
                self::assertTrue($menu->getByRole('button', ['name' => 'Log out', 'exact' => true])->isVisible());
            }
        }
    }

    public function testAdministratorWithBothRolesCannotEnterOwnFinancesSettingsOrLegacyActions(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $administrator = $fixtures['administrator'];
        self::assertInstanceOf(User::class, $administrator);
        $source = $fixtures['admin_current'];
        $statement = $fixtures['admin_statement'];
        $transaction = $fixtures['admin_transaction'];
        self::assertInstanceOf(Source::class, $source);
        self::assertInstanceOf(Statement::class, $statement);
        self::assertInstanceOf(Transaction::class, $transaction);
        $this->loginUser($administrator);
        $page = $this->visit('/home/user/'.$administrator->getId().'/edit');
        $page->locator('select[name="User[roles][]"]')->selectOption(['ROLE_ADMIN', 'ROLE_USER'], ['force' => true]);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"]')->first()->click());
        // Reauthenticate after changing roles so the token uses both persisted roles.
        $page = $this->visit('/login');
        $this->submitLogin($page, 'admin@test.com', 'admin');
        $page = $this->visit('/home/user');
        $page->waitForURL('**/home/user');
        $page = $this->visit('/home/user/'.$administrator->getId().'/edit');
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $page->locator('select[name="User[roles][]"]')->evaluate('(select) => Array.from(select.selectedOptions).map(option => option.value).sort()'));
        foreach (['/home/chat', '/home/settings', '/home/source', '/home/source/new', '/home/source/'.$source->getId(), '/home/source/'.$source->getId().'/edit', '/home/statement', '/home/statement/'.$statement->getId(), '/home/transaction', '/home/transaction/'.$transaction->getId().'/edit', '/home?routeName=admin_source_index', '/home?routeName=admin_transaction_index', '/home?routeName=admin_chat', '/home?routeName=admin_settings'] as $path) {
            $page = $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
            self::assertStringNotContainsString('Admin private account', $page->locator('body')->textContent());
        }
        foreach (['/home/settings', '/home/source/new', '/home/source/'.$source->getId().'/edit', '/home/source/'.$source->getId().'/delete', '/home/transaction/'.$transaction->getId().'/edit', '/home?routeName=admin_source_new', '/home?routeName=admin_settings'] as $path) {
            $page = $this->visit('/home/category/new');
            $page->getByLabel('Name', ['exact' => true])->fill('Rejected role-boundary submission');
            $page->locator('form[name=Category]')->evaluate('(form, path) => { form.action = path; form.noValidate = true; }', $path);
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
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

    private function submitLogin(PageInterface $page, string $email, string $password): void
    {
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());
    }
}
