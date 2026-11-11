<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceNetworkBrowserTest;

final class SettingsTest extends FinanceNetworkBrowserTest
{
    public function testSaveMoneyPreferences(): void
    {
        $this->login();
        $page = $this->visit('/home/settings');
        $currency = $page->getByLabel('Reporting currency')->inputValue();
        $locale = $page->getByLabel('Money locale', ['exact' => true])->inputValue();
        try {
            $page->getByLabel('Reporting currency')->selectOption('EUR');
            $page->getByLabel('Money locale', ['exact' => true])->selectOption('de_DE');
            $page->getByRole('button', ['name' => 'Save preferences'])->click();
            $page->getByText('Preferences saved. Original amounts are unchanged.', ['exact' => true])->waitFor();
            $page = $this->visit('/home/settings');
            self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
            self::assertSame('de_DE', $page->getByLabel('Money locale', ['exact' => true])->inputValue());
            $page = $this->visit('/home/transaction');
            $row = $page->locator('tbody tr')->first();
            $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
            $editUrl = $row->locator('a.action-edit')->getAttribute('href');
            $page->goto($editUrl);
            $amount = $page->getByLabel('Amount', ['exact' => true])->inputValue();
            $transactionCurrency = $page->getByLabel('Currency', ['exact' => true])->inputValue();
            $formatted = (new \NumberFormatter('de_DE', \NumberFormatter::CURRENCY))->formatCurrency((float) $amount, $transactionCurrency);
            self::assertNotFalse($formatted);
            $page->goto($detailUrl);
            self::assertSame($formatted, trim((string) $page->getByText($formatted, ['exact' => true])->textContent()));
        } finally {
            $page = $this->visit('/home/settings');
            $page->getByLabel('Reporting currency')->selectOption($currency);
            $page->getByLabel('Money locale', ['exact' => true])->selectOption($locale);
            $page->getByRole('button', ['name' => 'Save preferences'])->click();
            $page->getByText('Preferences saved. Original amounts are unchanged.', ['exact' => true])->waitFor();
        }
    }
}
