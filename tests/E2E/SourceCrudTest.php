<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Playwright\Assertions\Expect;

final class SourceCrudTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    public function testSourceCrudValidationEscapingAndReload(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/source');
        $document = $page->goto($this->getBaseUrl().'/home/source/new');
        self::assertNotNull($document);
        self::assertSame(200, $document->status());
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel="shortcut icon"]')->getAttribute('href'));
        $page->getByLabel('Name', ['exact' => true])->fill('<script>window.financeInjected=true</script>');
        $page->getByLabel('Description', ['exact' => true])->fill('Synthetic source created through the browser');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/source');
        self::assertStringContainsString('<script>window.financeInjected=true</script>', $page->locator('body')->textContent());
        self::assertNull($page->evaluate('() => window.financeInjected'));
        $editUrl = $page->locator('tbody tr')->filter(['hasText' => '<script>window.financeInjected=true</script>'])->locator('a.action-edit')->getAttribute('href');
        self::assertNotNull($editUrl);
        $page->goto($editUrl);
        $page->getByLabel('Name', ['exact' => true])->fill('Browser renamed account');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->goto($editUrl);
        self::assertSame('Browser renamed account', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->getByLabel('Name', ['exact' => true])->fill('');
        $page->evaluate('() => document.querySelector("form[name=Source]").noValidate = true');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        Expect::locator($page->getByText('This value should not be blank'))->toBeVisible();
        $page->goto($editUrl);
        self::assertSame('Browser renamed account', $page->getByLabel('Name', ['exact' => true])->inputValue());
    }

    public function testSourceDeletionRequiresOwnerCsrfAndNoReferences(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/source');
        $document = $page->goto($this->getBaseUrl().'/home/source/new');
        self::assertNotNull($document);
        self::assertSame(200, $document->status());
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel="shortcut icon"]')->getAttribute('href'));
        $page->getByLabel('Name', ['exact' => true])->fill('Disposable synthetic source');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/source');
        $row = $page->locator('tbody tr')->filter(['hasText' => 'Disposable synthetic source']);
        $detailUrl = $row->locator('a.action-detail')->getAttribute('href');
        self::assertNotNull($detailUrl);
        $page->goto($detailUrl);
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $page->evaluate('() => document.querySelector("#action-confirmation-form [name=token]").value = "forged"');
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        self::assertResponseIsSuccessful();

        $this->visit('/home/source');
        $this->assertPageContains('Disposable synthetic source');
        $page->goto($detailUrl);
        $page->evaluate('(path) => document.querySelector(".action-delete").setAttribute("formaction", path)', '/home/source/'.$fixtures['admin_current']->getId().'/delete');
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());

        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        $page->goto($detailUrl);
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());

        $page = $this->visit('/home/source');
        self::assertStringNotContainsString('Disposable synthetic source', $page->locator('body')->textContent());
        $page = $this->visit('/home/source/'.$fixtures['demo_current']->getId());
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());

        $this->assertPageContains('This record is still referenced and was not deleted');
        $this->visit('/home/source');
        $this->assertPageContains('Demo current account');
    }
}
