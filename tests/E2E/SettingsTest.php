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

    public function testSettingsRejectUnsupportedCurrencyAndInvalidCsrf(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/settings');
        $page->evaluate('() => { const s = document.querySelector("select[name=\"form[reportingCurrency]\"]"); s.add(new Option("Unsupported", "XXX", true, true)); }');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        Expect::locator($page->getByText('The selected choice is invalid'))->toBeVisible();
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $page->evaluate('() => document.querySelector("input[name=\"form[_token]\"]").value = "invalid"');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        Expect::locator($page->getByText('The CSRF token is invalid'))->toBeVisible();
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
    }
}
