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
            foreach (['/home/source/'.$this->bobSource.'/edit', '/home/source/'.$this->bobSource, '/home/transaction/'.$this->bobTransaction.'/edit', '/home/statement/'.$this->bobStatement] as $path) {
                $page = $this->visit($path);
                self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
            }
            foreach (['source', 'transaction', 'statement'] as $screen) {
                $page = $this->visit('/home/'.$screen);
                if ('admin' === $identity) {
                    self::assertSame(403, $this->getLastResponse()->getStatusCode());
                }
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob approved transaction', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob-synthetic.csv', $page->locator('body')->textContent());
            }
        }
        $this->login('bob');
        $this->visit('/home/source');
        $this->assertPageContains('Bob secret account');
        self::assertStringNotContainsString('Alice account', $this->getPage()->locator('body')->textContent());
        $this->visit('/home/transaction/'.$this->bobTransaction.'/edit');
        self::assertSame('+00123456789012.12345678', $this->getPage()->getByLabel('Amount', ['exact' => true])->inputValue());
    }

    public function testUserCannotManageUsersCategoriesOrDrafts(): void
    {
        $this->login('alice');
        foreach (['/home/user', '/home/user/new', '/home/user/'.$this->bobUser.'/edit', '/home/category', '/home/category/new', '/home?routeName=admin_user_index', '/home?routeName=admin_category_index', '/home/transaction/'.$this->draftTransaction.'/edit', '/home/transaction/new', '/home/statement/new'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
        foreach (['/home/user/new', '/home/category/new', '/home?routeName=admin_user_new'] as $path) {
            $page = $this->visit('/home/source/'.$this->aliceSource.'/edit');
            $page->locator('form[name=Source]')->evaluate('(form, path) => { form.action = path; form.noValidate = true; }', $path);
            $this->save($page);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }
}
