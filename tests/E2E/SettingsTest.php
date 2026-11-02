<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class SettingsTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testSaveReportingCurrency(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/settings');
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Reporting currency saved');
        $page = $this->visit('/home/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
    }
}
