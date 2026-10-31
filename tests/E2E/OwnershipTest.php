<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class OwnershipTest extends FinanceBrowserTest
{
    public function testOwnerScopeDirectUrlsAndAdminHaveNoFinanceBypass(): void
    {
        foreach (['alice', 'admin'] as $identity) {
            $this->login($identity);
            foreach (['/admin/source/'.$this->bobSource.'/edit', '/admin/source/'.$this->bobSource, '/admin/transaction/'.$this->bobTransaction.'/edit', '/admin/statement/'.$this->bobStatement] as $path) {
                $page = $this->visit($path);
                self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
            }
            foreach (['source', 'transaction', 'statement'] as $screen) {
                $page = $this->visit('/admin/'.$screen);
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob approved transaction', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob-synthetic.csv', $page->locator('body')->textContent());
            }
        }
        $this->login('bob');
        $this->visit('/admin/source');
        $this->assertPageContains('Bob secret account');
        self::assertStringNotContainsString('Alice account', $this->getPage()->locator('body')->textContent());
        $this->visit('/admin/transaction/'.$this->bobTransaction.'/edit');
        self::assertSame('+00123456789012.12345678', $this->getPage()->getByLabel('Amount', ['exact' => true])->inputValue());
    }

    public function testUserCannotManageUsersCategoriesOrDrafts(): void
    {
        $this->login('alice');
        foreach (['/admin/user', '/admin/user/new', '/admin/user/'.$this->bobUser.'/edit', '/admin/category', '/admin/category/new', '/admin/transaction/'.$this->draftTransaction.'/edit', '/admin/transaction/new', '/admin/statement/new'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }
}
