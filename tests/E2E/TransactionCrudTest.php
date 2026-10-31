<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class TransactionCrudTest extends FinanceBrowserTest
{
    public function testApprovedEditExactMoneyCurrencySettingsAndAssociationAttack(): void
    {
        $this->login('alice');
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('+00123456789012.12345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertStringNotContainsString('Bob secret account', $page->locator('select[name="Transaction[source]"]')->textContent());
        $page->getByLabel('Amount', ['exact' => true])->fill('-000123456789012.123456789012345678');
        $page->getByLabel('Description', ['exact' => true])->fill('Edited approved transaction');
        $this->save($page);
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        $page->getByLabel('Amount', ['exact' => true])->fill('1e20');
        $this->save($page);
        $this->assertPageContains('This value is not valid');
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Reporting currency saved');
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertSame('USD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
        $page->evaluate('(id) => { const s = document.querySelector("select[name=\"Transaction[source]\"]"); s.add(new Option("Forged foreign source", id, true, true)); }', (string) $this->bobSource);
        $this->save($page);
        $page->getByText('The selected choice is invalid')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('The selected choice is invalid');
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame((string) $this->aliceSource, $page->locator('select[name="Transaction[source]"]')->inputValue());
    }

    public function testAssociationNamesAreEscapedAndCategoryLinksRespectRole(): void
    {
        $sourceName = '<img id="source-injection" src=x onerror="window.sourceInjected=true">';
        $categoryName = '<script id="category-injection">window.categoryInjected=true</script>';
        $this->login('alice');
        $page = $this->visit('/home/source/'.$this->aliceSource.'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($sourceName);
        $this->save($page);
        // An administrator controls the shared label rendered in Alice's finances.
        $this->login('admin');
        $page = $this->visit('/home/category/'.$this->category.'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($categoryName);
        $this->save($page);
        $this->login('alice');
        foreach (['/home/transaction', '/home/transaction/'.$this->aliceTransaction] as $path) {
            $page = $this->visit($path);
            $body = $page->locator('body')->textContent();
            self::assertStringContainsString($sourceName, $body);
            self::assertStringContainsString($categoryName, $body);
            self::assertSame(0, $page->locator('#source-injection, #category-injection')->count());
            self::assertNull($page->evaluate('() => window.sourceInjected'));
            self::assertNull($page->evaluate('() => window.categoryInjected'));
            self::assertSame(0, $page->locator('a[href*="/home/category"]')->count());
        }
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertStringContainsString($categoryName, $page->locator('select[name="Transaction[category]"]')->textContent());
        $page->getByLabel('Category', ['exact' => true])->selectOption((string) $this->category);
        $this->save($page);
        $page = $this->visit('/home/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame((string) $this->category, $page->getByLabel('Category', ['exact' => true])->inputValue());
        $this->visit('/home/category/'.$this->category);
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
    }

    public function testOriginalCurrenciesAndOptionalDescriptionSurviveOrdinaryEdits(): void
    {
        $this->login('alice');
        foreach ($this->originalCurrencyTransactions as $currency => $id) {
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            $page->getByLabel('Description', ['exact' => true])->fill('Unrelated correction');
            $this->save($page);
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            self::assertSame('Unrelated correction', $page->getByLabel('Description', ['exact' => true])->inputValue());
            // Leave native browser validation enabled for the optional blank field.
            $page->getByLabel('Description', ['exact' => true])->fill('');
            $this->save($page);
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame('', $page->getByLabel('Description', ['exact' => true])->inputValue());
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            self::assertSame('10.00', $page->getByLabel('Amount', ['exact' => true])->inputValue());
            $page->getByLabel('Currency', ['exact' => true])->fill('usd');
            $this->save($page);
            $this->assertPageContains('This value is not valid');
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
        }
    }
}
