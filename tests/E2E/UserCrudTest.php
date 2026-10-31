<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class UserCrudTest extends FinanceBrowserTest
{
    public function testUserNewEditDetailDeleteAndBlankPasswordPreservation(): void
    {
        $this->login('admin');
        $page = $this->visit('/admin/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('created@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $this->save($page);
        $page = $this->visit('/admin/user');
        $this->assertPageContains('created@example.test');
        $userRow = $page->locator('tbody tr')->filter(['hasText' => 'created@example.test']);
        $userUrl = $userRow->locator('a.action-edit')->getAttribute('href');
        $userDetailUrl = $userRow->locator('a.action-detail')->getAttribute('href');
        $page->goto($userUrl);
        $page->getByLabel('Email', ['exact' => true])->fill('renamed@example.test');
        // An empty new-password field must preserve the existing hash.
        $this->save($page);
        $this->login('renamed');
        $this->visit('/settings');
        $this->assertPageContains('Settings');
        $this->login('admin');
        $page->goto($userDetailUrl);
        $this->openDeleteConfirmation($page);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/admin/user');
        self::assertStringNotContainsString('renamed@example.test', $page->locator('body')->textContent());
    }
}
