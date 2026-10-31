<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class CategoryCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testCategoryNewEditDetailAndDelete(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['administrator']);
        $page = $this->visit('/home/category/new');
        $page->getByLabel('Name', ['exact' => true])->fill('New shared catalog entry');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/category');
        $this->assertPageContains('New shared catalog entry');
        $categoryRow = $page->locator('tbody tr')->filter(['hasText' => 'New shared catalog entry']);
        $categoryUrl = $categoryRow->locator('a.action-edit')->getAttribute('href');
        $categoryDetailUrl = $categoryRow->locator('a.action-detail')->getAttribute('href');
        $page->goto($categoryUrl);
        $page->getByLabel('Name', ['exact' => true])->fill('Edited shared catalog entry');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->goto($categoryUrl);
        self::assertSame('Edited shared catalog entry', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->goto($categoryDetailUrl);
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());

        $page = $this->visit('/home/category');
        self::assertStringNotContainsString('Edited shared catalog entry', $page->locator('body')->textContent());
    }
}
