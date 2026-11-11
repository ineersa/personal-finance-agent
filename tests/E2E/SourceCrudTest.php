<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Enum\InstrumentKind;
use App\Tests\Support\FinanceNetworkBrowserTest;

final class SourceCrudTest extends FinanceNetworkBrowserTest
{
    public function testCreateEditViewAndDeleteSource(): void
    {
        $this->login();
        $suffix = bin2hex(random_bytes(6));
        $name = 'Browser account '.$suffix;
        $editedName = 'Renamed browser account '.$suffix;
        $description = 'Everyday purchases';
        $editedDescription = 'Household savings account';
        $page = $this->visit('/home/source/new');
        $page->getByLabel('Name', ['exact' => true])->fill($name);
        $page->getByLabel('Description', ['exact' => true])->fill($description);
        $page->getByLabel('Currency', ['exact' => true])->fill('CAD');
        $page->getByLabel('Instrument kind')->selectOption(InstrumentKind::CreditCard->value);
        $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
        $page = $this->visit('/home/source?query='.rawurlencode($name));
        $row = $page->locator('tbody tr')->filter(['hasText' => $name]);
        $editUrl = $row->locator('a.action-edit')->getAttribute('href');
        $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
        try {
            $page->goto($editUrl);
            self::assertSame($description, $page->getByLabel('Description', ['exact' => true])->inputValue());
            $page->getByLabel('Name', ['exact' => true])->fill($editedName);
            $page->getByLabel('Description', ['exact' => true])->fill($editedDescription);
            $page->getByLabel('Currency', ['exact' => true])->fill('CA');
            $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
            $error = $page->locator('.invalid-feedback')->filter(['hasText' => 'This value is not valid.']);
            $error->waitFor();
            self::assertTrue($error->isVisible());
            self::assertStringContainsString('is-invalid', $page->getByLabel('Currency', ['exact' => true])->getAttribute('class'));

            // Reopen to verify the rejected edit did not change the saved record.
            $page->goto($editUrl);
            self::assertSame($name, $page->getByLabel('Name', ['exact' => true])->inputValue());
            self::assertSame($description, $page->getByLabel('Description', ['exact' => true])->inputValue());
            self::assertSame('CAD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
            $page->getByLabel('Name', ['exact' => true])->fill($editedName);
            $page->getByLabel('Description', ['exact' => true])->fill($editedDescription);
            $page->getByLabel('Currency', ['exact' => true])->fill('USD');
            $page->getByLabel('Instrument kind')->selectOption(InstrumentKind::BankAccount->value);
            $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
            $page->goto($editUrl);
            self::assertSame($editedName, $page->getByLabel('Name', ['exact' => true])->inputValue());
            self::assertSame($editedDescription, $page->getByLabel('Description', ['exact' => true])->inputValue());
            self::assertSame('USD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
            self::assertSame(InstrumentKind::BankAccount->value, $page->getByLabel('Instrument kind')->inputValue());
            $page = $this->visit('/home/source?query='.rawurlencode($editedName));
            $editedRow = $page->locator('tbody tr')->filter(['hasText' => $editedName]);
            self::assertSame($editedName, trim($editedRow->locator('td[data-column="name"]')->textContent()));
            self::assertSame($editedDescription, trim($editedRow->locator('td[data-column="description"]')->textContent()));
            self::assertSame('USD', trim($editedRow->locator('td[data-column="currency"]')->textContent()));
            self::assertSame(InstrumentKind::BankAccount->name, trim($editedRow->locator('td[data-column="instrumentKind"]')->textContent()));
        } finally {
            $this->deleteRecord($detailUrl);
        }
        $page = $this->visit('/home/source?query='.rawurlencode($editedName));
        self::assertStringNotContainsString($editedName, $page->locator('tbody')->textContent());
    }
}
