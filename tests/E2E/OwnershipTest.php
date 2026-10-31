<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class OwnershipTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testOwnerScopeDirectUrlsAndAdminHaveNoFinanceBypass(): void
    {
        $fixtures = FixtureStore::getFixtures();
        foreach (['alice', 'admin'] as $identity) {
            $this->loginUser($fixtures[$identity]);
            foreach (['/home/source/'.$fixtures['bob_source']->getId().'/edit', '/home/source/'.$fixtures['bob_source']->getId(), '/home/transaction/'.$fixtures['bob_transaction']->getId().'/edit', '/home/statement/'.$fixtures['bob_statement']->getId()] as $path) {
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
        $this->loginUser($fixtures['bob']);
        $this->visit('/home/source');
        $this->assertPageContains('Bob secret account');
        self::assertStringNotContainsString('Alice account', $this->getPage()->locator('body')->textContent());
        $this->visit('/home/transaction/'.$fixtures['bob_transaction']->getId().'/edit');
        self::assertSame('+00123456789012.12345678', $this->getPage()->getByLabel('Amount', ['exact' => true])->inputValue());
    }

    public function testUserCannotManageUsersCategoriesOrDrafts(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['alice']);
        foreach (['/home/user', '/home/user/new', '/home/user/'.$fixtures['bob']->getId().'/edit', '/home/category', '/home/category/new', '/home?routeName=admin_user_index', '/home?routeName=admin_category_index', '/home/transaction/'.$fixtures['alice_draft']->getId().'/edit', '/home/transaction/new', '/home/statement/new'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
        foreach (['/home/user/new', '/home/category/new', '/home?routeName=admin_user_new'] as $path) {
            $page = $this->visit('/home/source/'.$fixtures['alice_source']->getId().'/edit');
            $page->locator('form[name=Source]')->evaluate('(form, path) => { form.action = path; form.noValidate = true; }', $path);
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }
}
