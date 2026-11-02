<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class SettingsTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testSaveMoneyPreferences(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/settings');
        self::assertSame('en_US', $page->getByLabel('Money locale', ['exact' => true])->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $page->getByLabel('Money locale', ['exact' => true])->selectOption('de_DE');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Preferences saved');
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        self::assertSame('de_DE', $page->getByLabel('Money locale', ['exact' => true])->inputValue());
        $transaction = FixtureStore::getFixtures()['demo_salary'];
        $page = $this->visit('/home/transaction/'.$transaction->getId());
        $amount = (new \NumberFormatter('de_DE', \NumberFormatter::CURRENCY))->formatCurrency((float) $transaction->amount, $transaction->currency);
        self::assertNotFalse($amount);
        self::assertSame($amount, trim((string) $page->getByText($amount, ['exact' => true])->textContent()));
    }
}
