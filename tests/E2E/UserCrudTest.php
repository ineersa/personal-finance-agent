<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class UserCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    private const string PASSWORD = 'Synthetic-browser-password-05';

    public function testAdministratorCreatesRenamesAndDeletesUser(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['administrator']);
        $page = $this->visit('/home/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('created@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/user');
        $this->assertPageContains('created@example.test');
        $userRow = $page->locator('tbody tr')->filter(['hasText' => 'created@example.test']);
        $userUrl = $userRow->locator('a.action-edit')->getAttribute('href');
        $userDetailUrl = $userRow->locator('a.action-detail')->getAttribute('href');
        $page->goto($userUrl);
        $page->getByLabel('Email', ['exact' => true])->fill('renamed@example.test');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->goto($userUrl);
        self::assertSame('renamed@example.test', $page->getByLabel('Email', ['exact' => true])->inputValue());
        $page->goto($userDetailUrl);
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());

        $page = $this->visit('/home/user');
        self::assertStringNotContainsString('renamed@example.test', $page->locator('body')->textContent());
    }
}
