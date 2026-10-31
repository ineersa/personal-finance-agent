<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class SettingsTest extends FinanceBrowserTest
{
    public function testSettingsRejectUnsupportedCurrencyAndInvalidCsrf(): void
    {
        $this->login('alice');
        $page = $this->visit('/home/settings');
        $page->evaluate('() => { const s = document.querySelector("select[name=\"form[reportingCurrency]\"]"); s.add(new Option("Unsupported", "XXX", true, true)); }');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('The selected choice is invalid');
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $page->evaluate('() => document.querySelector("input[name=\"form[_token]\"]").value = "invalid"');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('The CSRF token is invalid');
        $page = $this->visit('/home/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
    }
}
