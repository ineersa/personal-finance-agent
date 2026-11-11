<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceNetworkBrowserTest;

final class CategoryCrudTest extends FinanceNetworkBrowserTest
{
    public function testCategoryNewEditDetailAndDelete(): void
    {
        $this->login();
        $suffix = bin2hex(random_bytes(6));
        $name = 'Browser category '.$suffix;
        $editedName = 'Edited browser category '.$suffix;
        $description = 'Household expenses';
        $iconClasses = 'fa fa-house';
        $page = $this->visit('/home/category/new');
        $page->getByLabel('Name', ['exact' => true])->fill($name);
        $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
        $page = $this->visit('/home/category?query='.rawurlencode($name));
        $this->assertPageContains($name);
        $categoryRow = $page->locator('tbody tr')->filter(['hasText' => $name]);
        $categoryUrl = $categoryRow->locator('a.action-edit')->getAttribute('href');
        $categoryDetailUrl = $categoryRow->locator('a.action-detail')->getAttribute('href');
        try {
            $page->goto($categoryUrl);
            $page->getByLabel('Name', ['exact' => true])->fill($editedName);
            $page->getByLabel('Description', ['exact' => true])->fill($description);
            $page->getByLabel('Icon', ['exact' => true])->fill($iconClasses);
            $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
            $page->goto($categoryUrl);
            self::assertSame($editedName, $page->getByLabel('Name', ['exact' => true])->inputValue());
            self::assertSame($description, $page->getByLabel('Description', ['exact' => true])->inputValue());
            self::assertSame($iconClasses, $page->getByLabel('Icon', ['exact' => true])->inputValue());

            $page = $this->visit('/home/category?query='.rawurlencode($editedName));
            $editedRow = $page->locator('tbody tr')->filter(['hasText' => $editedName]);
            self::assertSame($editedName, trim($editedRow->locator('td[data-column="name"]')->textContent()));
            self::assertSame($description, trim($editedRow->locator('td[data-column="description"]')->textContent()));
            $iconCell = $editedRow->locator('td[data-column="icon"]');
            self::assertSame($iconClasses, trim($iconCell->textContent()));
            $icon = $iconCell->locator('i');
            self::assertTrue($icon->isVisible());
            self::assertSame($iconClasses, $icon->getAttribute('class'));
            // Font Awesome's house glyph: verify the stylesheet renders an icon, not only its class name.
            self::assertStringContainsString("\u{f015}", $icon->evaluate("element => getComputedStyle(element, '::before').content"));
        } finally {
            $this->deleteRecord($categoryDetailUrl);
        }

        $page = $this->visit('/home/category?query='.rawurlencode($editedName));
        self::assertStringNotContainsString($editedName, $page->locator('tbody')->textContent());
    }
}
