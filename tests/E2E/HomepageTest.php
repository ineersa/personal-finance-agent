<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class HomepageTest extends FinanceBrowserTest
{
    public function testAnonymousLandingOffersDemoLoginWithoutWorkspace(): void
    {
        $page = $this->visit('/');
        self::assertResponseIsSuccessful();
        self::assertSame('Make sense of your money.', $page->getByRole('heading', ['level' => 1])->textContent());
        $this->assertPageContains('demo@test.com');
        self::assertStringContainsString('Password: demo', $page->locator('.demo-credentials')->textContent());
        self::assertStringNotContainsString('admin@test.com', $page->locator('body')->textContent());
        self::assertSame(0, $page->locator('.app-sidebar, .app-nav, .finance-app, .composer-preview, textarea, [aria-disabled=true]')->count());
        $page->getByRole('link', ['name' => 'Log in', 'exact' => true])->click();
        $this->assertPageContains('Personal finance login');
        self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
        self::assertSame('rgb(17, 17, 18)', $page->getByLabel('Email', ['exact' => true])->evaluate('(input) => getComputedStyle(input).backgroundColor'));
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel=icon]')->getAttribute('href'));
    }

    public function testLandingIsResponsive(): void
    {
        $page = $this->visit('/');
        foreach ([1440, 390] as $width) {
            $page->setViewportSize($width, 900);
            self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'));
            self::assertTrue($page->getByRole('heading', ['level' => 1])->isVisible());
            self::assertTrue($page->getByRole('link', ['name' => 'Log in', 'exact' => true])->isVisible());
        }
    }

    public function testSignedInWorkspaceAndNativeCrudShareBrandingAndGeometry(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        foreach ([1280, 390] as $width) {
            $this->getPage()->setViewportSize($width, 900);
            $referenceGeometry = null;
            foreach (['/home/chat', '/home/settings', '/home/source', '/home/source/new'] as $path) {
                $page = $this->visit($path);
                $brand = $page->locator('.app-brand:visible');
                self::assertSame(1, $brand->count(), $path);
                self::assertSame('pf', $brand->locator('.brand-mark')->textContent());
                self::assertSame('A little more clarity.', $brand->locator('.brand-caption')->textContent());
                self::assertStringContainsString('Built with Symfony', $page->locator('.workspace-footer')->textContent());
                $geometry = $page->evaluate(<<<'JS'
                    () => {
                        const sidebar = document.querySelector('.ea-sidebar');
                        const header = document.querySelector('.content-top');
                        const nav = document.querySelector('.ea-sidebar-content');
                        const destination = link => {
                            const url = new URL(link.href);
                            // Native EasyAdmin links forward named application routes through the dashboard.
                            if (url.pathname === '/home') {
                                if (url.searchParams.get('routeName') === 'admin_chat') return '/home/chat';
                                if (url.searchParams.get('routeName') === 'admin_settings') return '/home/settings';
                            }
                            return url.pathname;
                        };
                        return {sidebarWidth: sidebar.getBoundingClientRect().width,
                            availableWidth: document.body.getBoundingClientRect().width,
                            headerPadding: getComputedStyle(header).paddingLeft,
                            navColumns: getComputedStyle(nav).gridTemplateColumns.split(' ').length,
                            links: Array.from(nav.querySelectorAll('a')).map(destination),
                            current: Array.from(nav.querySelectorAll('a[aria-current=page]')).map(destination)};
                    }
                    JS);
                // Native EasyAdmin reserves a stable scrollbar gutter outside the available document width.
                $referenceGeometry ??= $geometry;
                self::assertSame($referenceGeometry['sidebarWidth'], $geometry['sidebarWidth'], $path);
                self::assertSame($referenceGeometry['headerPadding'], $geometry['headerPadding'], $path);
                if ($width > 991) {
                    self::assertGreaterThan(0, $geometry['sidebarWidth'], $path);
                    self::assertLessThan($geometry['availableWidth'], $geometry['sidebarWidth'], $path);
                } else {
                    self::assertEquals($geometry['availableWidth'], $geometry['sidebarWidth'], $path);
                }
                if ($width < 992) {
                    self::assertSame(3, $geometry['navColumns'], $path);
                }
                self::assertSame(['/home/chat', '/home/source', '/home/statement', '/home/transaction', '/home/settings'], $geometry['links'], $path);
                self::assertSame([str_starts_with($path, '/home/source') ? '/home/source' : $path], $geometry['current'], $path);
                self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'), $path);
            }
            $page->locator('.ea-sidebar-content')->getByRole('link', ['name' => 'Chat', 'exact' => true])->click();
            $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
            self::assertSame('page', $page->getByRole('link', ['name' => 'Chat', 'exact' => true])->getAttribute('aria-current'));
            $page = $this->visit('/home/source');
            $page->locator('.ea-sidebar-content')->getByRole('link', ['name' => 'Settings', 'exact' => true])->click();
            $page->getByLabel('Reporting currency')->waitFor();
            self::assertSame('page', $page->getByRole('link', ['name' => 'Settings', 'exact' => true])->getAttribute('aria-current'));
        }
        $page = $this->visit('/home/chat');
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isDisabled());
        self::assertTrue($page->getByRole('textbox', ['name' => 'Your financial question'])->isDisabled());
    }
}
