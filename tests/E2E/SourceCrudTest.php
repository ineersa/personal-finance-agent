<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class SourceCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testCreateEditViewAndDeleteSource(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/source/new');
        $page->getByLabel('Name', ['exact' => true])->fill('Browser account');
        $page->getByLabel('Currency', ['exact' => true])->fill('CAD');
        $page->getByLabel('Instrument kind')->selectOption('credit_card');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/source');
        $row = $page->locator('tbody tr')->filter(['hasText' => 'Browser account']);
        $editUrl = $row->locator('a.action-edit')->getAttribute('href');
        $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
        $page->goto($editUrl);
        $page->getByLabel('Name', ['exact' => true])->fill('Renamed browser account');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->goto($editUrl);
        self::assertSame('Renamed browser account', $page->getByLabel('Name', ['exact' => true])->inputValue());
        self::assertSame('CAD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
        self::assertSame('credit_card', $page->getByLabel('Instrument kind')->inputValue());
        $page->goto($detailUrl);
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page = $this->visit('/home/source');
        self::assertStringNotContainsString('Renamed browser account', $page->locator('body')->textContent());
    }
}
