<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class CurrencyRateCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testCurrencyRateCrudWithDateAndCurrencyFilters(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['administrator']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $date = $today->modify('+1 day')->format('Y-m-d');
        $page = $this->visit('/home/currency-rate');
        $page->locator('.action-filters-button')->click();
        self::assertSame($today->format('Y-m-d'), $page->locator('input[name="filters[day][value]"]')->inputValue());

        $page = $this->visit('/home/currency-rate/new');
        $page->getByLabel('Date', ['exact' => true])->fill($date);
        $page->getByLabel('Currency', ['exact' => true])->selectOption('USD');
        $page->getByLabel('Units per EUR', ['exact' => true])->fill('1.12345678901234567890');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());

        $page = $this->visit('/home/currency-rate');
        $page->locator('.action-filters-button')->click();
        $page->locator('input[name="filters[day][value]"]')->fill($date);
        $page->locator('[data-filter-property="currency"] .filter-checkbox')->check();
        $page->locator('select[name="filters[currency][value]"]')->selectOption('USD');
        $page->locator('#modal-apply-button')->click();
        $row = $page->locator('tbody tr')->filter(['hasText' => '1.12345678901234567890']);
        $editUrl = $row->locator('a.action-edit')->getAttribute('href');
        $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
        self::assertStringContainsString('USD', $row->textContent());
        self::assertStringContainsString($date, $row->textContent());

        $page->goto($editUrl);
        self::assertSame('1.12345678901234567890', $page->getByLabel('Units per EUR', ['exact' => true])->inputValue());
        $page->getByLabel('Units per EUR', ['exact' => true])->fill('1.23456789012345678901');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->goto($detailUrl);
        $this->assertPageContains('1.23456789012345678901');
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        self::assertStringNotContainsString('1.23456789012345678901', $page->locator('body')->textContent());

        $page->locator('.action-filters-reset')->click();
        $page->locator('.action-filters-button')->click();
        self::assertSame($today->format('Y-m-d'), $page->locator('input[name="filters[day][value]"]')->inputValue());
    }
}
