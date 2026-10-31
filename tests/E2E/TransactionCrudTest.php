<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class TransactionCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testApprovedEditExactMoneyCurrencySettingsAndAssociationAttack(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['alice']);
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertSame('+00123456789012.12345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertStringNotContainsString('Bob secret account', $page->locator('select[name="Transaction[source]"]')->textContent());
        $page->getByLabel('Amount', ['exact' => true])->fill('-000123456789012.123456789012345678');
        $page->getByLabel('Description', ['exact' => true])->fill('Edited approved transaction');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        $page->getByLabel('Amount', ['exact' => true])->fill('1e20');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $this->assertPageContains('This value is not valid');
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Reporting currency saved');
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertSame('USD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
        $page->evaluate('(id) => { const s = document.querySelector("select[name=\"Transaction[source]\"]"); s.add(new Option("Forged foreign source", id, true, true)); }', (string) $fixtures['bob_source']->getId());
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->getByText('The selected choice is invalid')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('The selected choice is invalid');
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertSame((string) $fixtures['alice_source']->getId(), $page->locator('select[name="Transaction[source]"]')->inputValue());
    }

    public function testAssociationNamesAreEscapedAndCategoryLinksRespectRole(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $sourceName = '<img id="source-injection" src=x onerror="window.sourceInjected=true">';
        $categoryName = '<script id="category-injection">window.categoryInjected=true</script>';
        $this->loginUser($fixtures['alice']);
        $page = $this->visit('/home/source/'.$fixtures['alice_source']->getId().'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($sourceName);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        // An administrator controls the shared label rendered in Alice's finances.
        $this->loginUser($fixtures['admin']);
        $page = $this->visit('/home/category/'.$fixtures['regression_category']->getId().'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($categoryName);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $this->loginUser($fixtures['alice']);
        foreach (['/home/transaction', '/home/transaction/'.$fixtures['alice_transaction']->getId()] as $path) {
            $page = $this->visit($path);
            $body = $page->locator('body')->textContent();
            self::assertStringContainsString($sourceName, $body);
            self::assertStringContainsString($categoryName, $body);
            self::assertSame(0, $page->locator('#source-injection, #category-injection')->count());
            self::assertNull($page->evaluate('() => window.sourceInjected'));
            self::assertNull($page->evaluate('() => window.categoryInjected'));
            self::assertSame(0, $page->locator('a[href*="/home/category"]')->count());
        }
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertStringContainsString($categoryName, $page->locator('select[name="Transaction[category]"]')->textContent());
        $page->getByLabel('Category', ['exact' => true])->selectOption((string) $fixtures['regression_category']->getId());
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/transaction/'.$fixtures['alice_transaction']->getId().'/edit');
        self::assertSame((string) $fixtures['regression_category']->getId(), $page->getByLabel('Category', ['exact' => true])->inputValue());
        $this->visit('/home/category/'.$fixtures['regression_category']->getId());
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
    }

    public function testOriginalCurrenciesAndOptionalDescriptionSurviveOrdinaryEdits(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['alice']);
        foreach (['UAH' => 'original_uah', 'BGN' => 'original_bgn'] as $currency => $reference) {
            $id = $fixtures[$reference]->getId();
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            $page->getByLabel('Description', ['exact' => true])->fill('Unrelated correction');
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            self::assertSame('Unrelated correction', $page->getByLabel('Description', ['exact' => true])->inputValue());
            // Leave native browser validation enabled for the optional blank field.
            $page->getByLabel('Description', ['exact' => true])->fill('');
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame('', $page->getByLabel('Description', ['exact' => true])->inputValue());
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
            self::assertSame('10.00', $page->getByLabel('Amount', ['exact' => true])->inputValue());
            $page->getByLabel('Currency', ['exact' => true])->fill('usd');
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            $this->assertPageContains('This value is not valid');
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
        }
    }
}
