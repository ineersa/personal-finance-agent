<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class CategoryCrudTest extends FinanceBrowserTest
{
    public function testCategoryNewEditDetailAndDelete(): void
    {
        $this->login('admin');
        $page = $this->visit('/admin/category/new');
        $page->getByLabel('Name', ['exact' => true])->fill('New shared catalog entry');
        $this->save($page);
        $page = $this->visit('/admin/category');
        $this->assertPageContains('New shared catalog entry');
        $categoryRow = $page->locator('tbody tr')->filter(['hasText' => 'New shared catalog entry']);
        $categoryUrl = $categoryRow->locator('a.action-edit')->getAttribute('href');
        $categoryDetailUrl = $categoryRow->locator('a.action-detail')->getAttribute('href');
        $page->goto($categoryUrl);
        $page->getByLabel('Name', ['exact' => true])->fill('Edited shared catalog entry');
        $this->save($page);
        $page->goto($categoryUrl);
        self::assertSame('Edited shared catalog entry', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->goto($categoryDetailUrl);
        $this->openDeleteConfirmation($page);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/admin/category');
        self::assertStringNotContainsString('Edited shared catalog entry', $page->locator('body')->textContent());
    }
}
