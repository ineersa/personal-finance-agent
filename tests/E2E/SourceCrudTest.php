<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;

final class SourceCrudTest extends FinanceBrowserTest
{
    public function testSourceCrudValidationEscapingAndReload(): void
    {
        $this->login('alice');
        $page = $this->openSourceCreation();
        $page->getByLabel('Name', ['exact' => true])->fill('<script>window.financeInjected=true</script>');
        $page->getByLabel('Description', ['exact' => true])->fill('Synthetic source created through the browser');
        $this->save($page);
        $page = $this->visit('/home/source');
        self::assertStringContainsString('<script>window.financeInjected=true</script>', $page->locator('body')->textContent());
        self::assertNull($page->evaluate('() => window.financeInjected'));
        $page = $this->visit('/home/source/'.$this->aliceSource.'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill('Alice renamed account');
        $this->save($page);
        $page = $this->visit('/home/source/'.$this->aliceSource.'/edit');
        self::assertSame('Alice renamed account', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->getByLabel('Name', ['exact' => true])->fill('');
        $page->evaluate('() => document.querySelector("form[name=Source]").noValidate = true');
        $this->save($page);
        $this->assertPageContains('This value should not be blank');
    }

    public function testDatabaseRollbackLeavesNoPriorBrowserCreatedSource(): void
    {
        $this->login('alice');
        $page = $this->visit('/home/source');
        self::assertStringNotContainsString('Synthetic source created through the browser', $page->locator('body')->textContent());
        self::assertStringNotContainsString('Alice renamed account', $page->locator('body')->textContent());
    }

    public function testSourceDeletionRequiresCsrfAndCannotTargetAnotherOwner(): void
    {
        $this->login('alice');
        $page = $this->openSourceCreation();
        $page->getByLabel('Name', ['exact' => true])->fill('Disposable synthetic source');
        $this->save($page);
        $page = $this->visit('/home/source');
        $row = $page->locator('tbody tr')->filter(['hasText' => 'Disposable synthetic source']);
        $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
        self::assertNotNull($detailUrl);
        $page->goto($detailUrl);
        $this->openDeleteConfirmation($page);
        $page->evaluate('() => document.querySelector("#action-confirmation-form [name=token]").value = "forged"');
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $this->visit('/home/source');
        $this->assertPageContains('Disposable synthetic source');
        $page->goto($detailUrl);
        $page->evaluate('(path) => document.querySelector(".action-delete").setAttribute("formaction", path)', '/home/source/'.$this->bobSource.'/delete');
        $this->openDeleteConfirmation($page);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        $page->goto($detailUrl);
        $this->openDeleteConfirmation($page);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/home/source');
        self::assertStringNotContainsString('Disposable synthetic source', $page->locator('body')->textContent());
        $page = $this->visit('/home/source/'.$this->aliceSource);
        $this->openDeleteConfirmation($page);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $this->assertPageContains('This record is still referenced and was not deleted');
        $this->visit('/home/source');
        $this->assertPageContains('Alice account');
    }
}
