<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Playwright\Assertions\Expect;

final class SettingsTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testSettingsRejectInvalidCurrencyChoicesAndInvalidCsrf(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/settings');
        foreach (['XXX', ''] as $currency) {
            $page->evaluate('(value) => { const s = document.querySelector("select[name=\"form[reportingCurrency]\"]"); s.closest("form").noValidate = true; s.add(new Option("Invalid currency", value, true, true)); }', $currency);
            $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
            self::assertLessThan(500, $this->getLastResponse()->getStatusCode());
            Expect::locator($page->getByText('The selected choice is invalid'))->toBeVisible();
            $page = $this->visit('/home/settings');
            self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        }
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $page->evaluate('() => document.querySelector("input[name=\"form[_token]\"]").value = "invalid"');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        Expect::locator($page->getByText('The CSRF token is invalid'))->toBeVisible();
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
    }
}
