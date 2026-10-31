<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

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
        $this->login('alice');
        foreach ([1280, 390] as $width) {
            $this->getPage()->setViewportSize($width, 900);
            foreach (['/', '/settings', '/home/source', '/home/source/new'] as $path) {
                $page = $this->visit($path);
                $brand = $page->locator('.app-brand:visible');
                self::assertSame(1, $brand->count(), $path);
                self::assertSame('pf', $brand->locator('.brand-mark')->textContent());
                self::assertSame('A little more clarity.', $brand->locator('.brand-caption')->textContent());
                self::assertSame('34px', $brand->locator('.brand-mark')->evaluate('(mark) => getComputedStyle(mark).width'));
                self::assertStringContainsString('Built with Symfony', $page->locator('.workspace-footer')->textContent());
                $geometry = $page->evaluate(<<<'JS'
                    () => {
                        const sidebar = document.querySelector('.app-sidebar, .ea-sidebar');
                        const header = document.querySelector('.workspace-header, .content-top');
                        const nav = document.querySelector('.app-nav, .ea-sidebar-content');
                        return {sidebarWidth: sidebar.getBoundingClientRect().width,
                            headerPadding: getComputedStyle(header).paddingLeft,
                            navColumns: getComputedStyle(nav).gridTemplateColumns.split(' ').length,
                            links: Array.from(nav.querySelectorAll('a')).map(link => new URL(link.href).pathname)};
                    }
                    JS);
                self::assertEquals($width > 991 ? 252 : $width, $geometry['sidebarWidth'], $path);
                self::assertSame($width > 991 ? '36px' : '20px', $geometry['headerPadding'], $path);
                if ($width < 992) {
                    self::assertSame(3, $geometry['navColumns'], $path);
                }
                self::assertSame(['/', '/home/source', '/home/statement', '/home/transaction', '/settings'], $geometry['links'], $path);
                self::assertTrue($page->evaluate('() => document.documentElement.scrollWidth <= window.innerWidth'), $path);
            }
        }
        $page = $this->visit('/');
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isDisabled());
        self::assertTrue($page->getByRole('textbox', ['name' => 'Your financial question'])->isDisabled());
    }
}
