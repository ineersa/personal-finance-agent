<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Playwright\Assertions\Expect;

final class TransactionCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testExactAmountCorrectionAndReportingPreferencePreserveOriginalMoney(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertSame('3200.00', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        foreach (['+00123456789012.12345678', '-000123456789012.123456789012345678'] as $amount) {
            $page->getByLabel('Amount', ['exact' => true])->fill($amount);
            $page->getByLabel('Description', ['exact' => true])->fill('Edited approved transaction');
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
            self::assertSame($amount, $page->getByLabel('Amount', ['exact' => true])->inputValue());
        }
        $page->getByLabel('Amount', ['exact' => true])->fill('1e20');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        Expect::locator($page->getByText('This value is not valid'))->toBeVisible();
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Reporting currency saved');
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertSame('USD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
    }

    public function testForeignSourceIsNotSelectableAndForgedChoiceCannotBeSaved(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertSame((string) $fixtures['demo_current']->getId(), $page->locator('select[name="Transaction[source]"]')->inputValue());
        self::assertStringContainsString('Demo credit card', $page->locator('select[name="Transaction[source]"]')->textContent());
        self::assertStringNotContainsString('Admin private account', $page->locator('select[name="Transaction[source]"]')->textContent());
        $page->evaluate('(id) => { const s = document.querySelector("select[name=\"Transaction[source]\"]"); s.add(new Option("Forged foreign source", id, true, true)); }', (string) $fixtures['admin_current']->getId());
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        Expect::locator($page->getByText('The selected choice is invalid'))->toBeVisible();
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertSame((string) $fixtures['demo_current']->getId(), $page->locator('select[name="Transaction[source]"]')->inputValue());
    }

    public function testAssociationNamesAreEscapedAndCategoryLinksRespectRole(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $sourceName = '<img id="source-injection" src=x onerror="window.sourceInjected=true">';
        $categoryName = '<script id="category-injection">window.categoryInjected=true</script>';
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/source/'.$fixtures['demo_current']->getId().'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($sourceName);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        // An administrator controls the shared label rendered in the demo user's finances.
        $this->loginUser($fixtures['administrator']);
        $page = $this->visit('/home/category/'.$fixtures['salary']->getId().'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill($categoryName);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $this->loginUser($fixtures['demo']);
        foreach (['/home/transaction', '/home/transaction/'.$fixtures['demo_salary']->getId()] as $path) {
            $page = $this->visit($path);
            $body = $page->locator('body')->textContent();
            self::assertStringContainsString($sourceName, $body);
            self::assertStringContainsString($categoryName, $body);
            self::assertSame(0, $page->locator('#source-injection, #category-injection')->count());
            self::assertNull($page->evaluate('() => window.sourceInjected'));
            self::assertNull($page->evaluate('() => window.categoryInjected'));
            self::assertSame(0, $page->locator('a[href*="/home/category"]')->count());
        }
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertStringContainsString($categoryName, $page->locator('select[name="Transaction[category]"]')->textContent());
        $page->getByLabel('Category', ['exact' => true])->selectOption((string) $fixtures['salary']->getId());
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/transaction/'.$fixtures['demo_salary']->getId().'/edit');
        self::assertSame((string) $fixtures['salary']->getId(), $page->getByLabel('Category', ['exact' => true])->inputValue());
        $this->visit('/home/category/'.$fixtures['salary']->getId());
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
    }

    public function testOriginalCurrenciesAndOptionalDescriptionSurviveOrdinaryEdits(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        foreach (['UAH' => 'demo_salary', 'BGN' => 'demo_rent'] as $currency => $reference) {
            $id = $fixtures[$reference]->getId();
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            $amount = $page->getByLabel('Amount', ['exact' => true])->inputValue();
            $page->getByLabel('Currency', ['exact' => true])->fill($currency);
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
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
            self::assertSame($amount, $page->getByLabel('Amount', ['exact' => true])->inputValue());
            $page->getByLabel('Currency', ['exact' => true])->fill('usd');
            $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
            Expect::locator($page->getByText('This value is not valid'))->toBeVisible();
            $page = $this->visit('/home/transaction/'.$id.'/edit');
            self::assertSame($currency, $page->getByLabel('Currency', ['exact' => true])->inputValue());
        }
    }
}
