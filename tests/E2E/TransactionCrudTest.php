<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class TransactionCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testListViewAndEditTransaction(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $transaction = $fixtures['demo_salary'];
        $this->visit('/home/transaction?query='.rawurlencode($transaction->description));
        $this->assertPageContains($transaction->description);
        $page = $this->visit('/home/transaction/'.$transaction->getId().'/edit');
        $page->getByLabel('Amount', ['exact' => true])->fill('123.45');
        $page->getByLabel('Description', ['exact' => true])->fill('Corrected salary');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/transaction/'.$transaction->getId().'/edit');
        self::assertSame('123.45', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertSame('CAD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
        $this->visit('/home/transaction/'.$transaction->getId());
        $this->assertPageContains('Corrected salary');
    }
}
