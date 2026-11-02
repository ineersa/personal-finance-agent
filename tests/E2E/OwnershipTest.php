<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class OwnershipTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testOwnerSeesOwnRecordsButCannotReadAnotherOwnersRecords(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        foreach ([
            'source' => [$fixtures['demo_current']->name, $fixtures['admin_current']->name],
            'transaction' => [$fixtures['demo_salary']->description, $fixtures['admin_transaction']->description],
            'statement' => [$fixtures['demo_statement']->originalFilename, $fixtures['admin_statement']->originalFilename],
        ] as $screen => [$own, $foreign]) {
            $page = $this->visit('/home/'.$screen);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($foreign, $page->locator('body')->textContent());
            $page = $this->visit('/home/'.$screen.'?query='.rawurlencode($own));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($own, $page->locator('tbody')->textContent());
            self::assertStringNotContainsString($foreign, $page->locator('tbody')->textContent());
        }
        foreach (['source' => 'demo_current', 'statement' => 'demo_statement', 'transaction' => 'demo_salary'] as $screen => $reference) {
            $this->visit('/home/'.$screen.'/'.$fixtures[$reference]->getId());
            self::assertResponseIsSuccessful();
        }
        foreach (['/home/source/'.$fixtures['admin_current']->getId(), '/home/source/'.$fixtures['admin_current']->getId().'/edit', '/home/statement/'.$fixtures['admin_statement']->getId(), '/home/transaction/'.$fixtures['admin_transaction']->getId(), '/home/transaction/'.$fixtures['admin_transaction']->getId().'/edit'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }

    public function testUserCannotManageUsersCategoriesOrDrafts(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        foreach (['/home/user', '/home/user/new', '/home/user/'.$fixtures['administrator']->getId().'/edit', '/home/category', '/home/category/new', '/home?routeName=admin_user_index', '/home?routeName=admin_category_index', '/home/transaction/'.$fixtures['demo_draft']->getId().'/edit', '/home/transaction/new', '/home/statement/new'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
        foreach (['/home/user/new', '/home/category/new', '/home?routeName=admin_user_new'] as $path) {
            $page = $this->visit('/home/source/'.$fixtures['demo_current']->getId().'/edit');
            $page->locator('form[name=Source]')->evaluate('(form, path) => { form.action = path; form.noValidate = true; }', $path);
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }
}
