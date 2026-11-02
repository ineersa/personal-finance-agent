<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;

/** Import/deletion proof on the isolated browser stack; never resets the database. */
final class StatementLifecycleTest extends TestCase
{
    public function testExhaustedWorkerFailureUpdatesConnectedPage(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = \dirname(__DIR__, 2).'/var/qa/lifecycle-worker-failure.csv';
            try {
                file_put_contents($path, "Date,Debit,Credit,Description\n2026-10-01,1.00,,Coffee\n2026-10-02,,2.00,Dinner refund\n");
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->addInitScript(<<<'JS'
                    const nativeFetch = window.fetch;
                    window.fetch = async (...args) => {
                        const response = await nativeFetch(...args);
                        if (args[1]?.headers?.Accept === 'text/vnd.turbo-stream.html') window.snapshotStatus = response.status;
                        return response;
                    };
                    JS);
                $page->goto($url.'/home/statement-upload');
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                $page->waitForFunction('() => window.snapshotStatus === 204');
                self::assertSame('queued', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                $page->evaluate('() => { window.failurePageProbe = "connected"; window.connectionChanges = 0; new MutationObserver(() => ++window.connectionChanges).observe(document.querySelector("turbo-mercure-stream-source"), {attributes: true, attributeFilter: ["connected"]}); }');
                $ready = \dirname(__DIR__, 2).'/var/qa/lifecycle-worker-failure.json';
                file_put_contents($ready.'.tmp', json_encode(['id' => (int) basename($page->url())], \JSON_THROW_ON_ERROR));
                rename($ready.'.tmp', $ready);
                $page->locator('[data-processing-status][data-status="failed"]')->waitFor(['timeout' => 60000]);
                self::assertStringContainsString('The worker could not handle this statement.', $page->getByRole('alert')->textContent());
                self::assertTrue($page->getByRole('link', ['name' => 'Open retry options'])->isVisible());
                self::assertSame(0, $page->locator('[data-draft-results] tbody tr')->count());
                self::assertSame('connected', $page->evaluate('() => window.failurePageProbe'));
                self::assertSame(0, $page->evaluate('() => window.connectionChanges'), 'The failure must arrive on the existing subscription, not catch-up after reconnect.');
            } finally {
                unlink($path);
                $context->close();
            }
        });
    }

    public function testQueuedImportCannotBeDeletedAndOwnerCanBePromoted(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = \dirname(__DIR__, 2).'/var/qa/lifecycle-retry.csv';
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                // Castor has stopped only this isolated worker; queued state is deterministic.
                file_put_contents($path, "Date,Debit,Credit,Description\n2026-10-01,1.00,,Coffee\n2026-10-02,,2.00,Dinner refund\n");
                $page->goto($url.'/home/statement-upload');
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                self::assertSame('queued', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                $id = basename($page->url());
                $page->goto($url.'/home/statement/'.$id);
                $this->deleteThroughModal($page);
                $page->getByText('Deletion refused: wait for this import to finish.', ['exact' => false])->waitFor();
                self::assertSame($url.'/home/statement/'.$id, $page->url(), 'Busy deletion must return to the retained statement, not an exception page.');
                self::assertStringContainsString('Deletion refused', $page->getByRole('alert')->textContent());
                self::assertSame(200, $page->goto($url.'/home/statement/'.$id)?->status());
                // Promote only after admission while the isolated worker is stopped.
                $admin = $browser->newContext();
                try {
                    $adminPage = $admin->newPage();
                    $this->login($adminPage, $url, 'admin@test.com', 'admin');
                    $adminPage->goto($url.'/home/user');
                    $ownerId = $adminPage->locator('tbody tr:has-text("demo@test.com")')->getAttribute('data-id');
                    self::assertNotNull($ownerId);
                    $adminPage->goto($url.'/home/user/'.$ownerId.'/edit');
                    $adminPage->locator('select[name="User[roles][]"]')->selectOption(['ROLE_ADMIN', 'ROLE_USER'], ['force' => true]);
                    $adminPage->locator('button[name="ea[newForm][btn]"][value="saveAndReturn"]')->click();
                    self::assertSame('/home/user', parse_url($adminPage->url(), \PHP_URL_PATH), 'Native saveAndReturn target: '.$adminPage->url());
                    $adminPage->goto($url.'/home/user/'.$ownerId.'/edit');
                    self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $adminPage->locator('select[name="User[roles][]"]')->evaluate('(select) => Array.from(select.selectedOptions).map(option => option.value).sort()'));
                    file_put_contents(\dirname(__DIR__, 2).'/var/qa/lifecycle-import.json', json_encode(['id' => $id, 'ownerId' => $ownerId], \JSON_THROW_ON_ERROR));
                } finally {
                    $admin->close();
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
                $context->close();
            }
        });
    }

    public function testPromotedOwnerFailsWithoutDraftsAndOwnedDeletionIsAuthorized(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $admin = $browser->newContext();
            $foreign = $browser->newContext();
            $createdUserId = null;
            try {
                $page = $context->newPage();
                $adminPage = $admin->newPage();
                $this->login($adminPage, $url, 'admin@test.com', 'admin');
                $saved = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/var/qa/lifecycle-import.json'), true, flags: \JSON_THROW_ON_ERROR);
                // Castor consumed the queued message while this owner was admin.
                $adminPage->goto($url.'/home/user/'.$saved['ownerId'].'/edit');
                $adminPage->locator('select[name="User[roles][]"]')->selectOption(['ROLE_USER'], ['force' => true]);
                $adminPage->locator('button[name="ea[newForm][btn]"][value="saveAndReturn"]')->click();
                self::assertSame('/home/user', parse_url($adminPage->url(), \PHP_URL_PATH));
                $adminPage->goto($url.'/home/user/'.$saved['ownerId'].'/edit');
                self::assertSame(['ROLE_USER'], $adminPage->locator('select[name="User[roles][]"]')->evaluate('(select) => Array.from(select.selectedOptions).map(option => option.value).sort()'));
                $this->login($page, $url, 'demo@test.com', 'demo');
                $processing = $url.'/home/statement-processing/'.$saved['id'];
                $statement = $url.'/home/statement/'.$saved['id'];
                // Hold a real failed snapshot body while another tab retries successfully.
                $page->addInitScript(<<<'JS'
                    if (location.pathname.includes('/statement-processing/')) {
                        const nativeFetch = window.fetch;
                        let held = false;
                        window.fetch = async (...args) => {
                            const response = await nativeFetch(...args);
                            if (!held && args[1]?.headers?.Accept === 'text/vnd.turbo-stream.html') {
                                held = true;
                                const text = await response.text();
                                window.snapshotHeld = true;
                                response.text = () => new Promise(resolve => {
                                    window.releaseFailedSnapshot = () => resolve(text);
                                });
                            }
                            return response;
                        };
                    }
                    JS);
                $page->goto($processing);
                self::assertSame('failed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                self::assertStringContainsString('the owner no longer has access to personal finances', $page->locator('body')->textContent());
                self::assertSame(0, $page->locator('[data-draft-results] tbody tr')->count());
                $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                $page->waitForFunction('() => window.snapshotHeld === true');
                $page->evaluate('() => window.retryPageProbe = "same-document"');
                $retry = $context->newPage();
                try {
                    $retry->goto($processing);
                    $retry->getByRole('button', ['name' => 'Retry processing', 'exact' => true])->click();
                    $page->locator('[data-processing-status][data-status="completed"]')->waitFor(['timeout' => 120000]);
                    self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count());
                    $page->evaluate('async () => { window.releaseFailedSnapshot(); await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); }');
                    self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'), 'A late failed snapshot must not overwrite the successful retry stream.');
                    self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count());
                    self::assertSame('same-document', $page->evaluate('() => window.retryPageProbe'));
                } finally {
                    $retry->close();
                }
                $page->goto($statement);
                $download = $page->locator('.action-download')->getAttribute('href');
                $deleteAction = $page->locator('.action-delete')->getAttribute('formaction');
                self::assertNotNull($deleteAction);
                $adminPage->goto($url.'/home/user');
                self::assertSame(403, $this->postNativeDelete($adminPage, $deleteAction));

                $email = 'lifecycle-'.bin2hex(random_bytes(6)).'@example.test';
                $adminPage->goto($url.'/home/user/new');
                $adminPage->getByLabel('Email', ['exact' => true])->fill($email);
                $adminPage->getByLabel('New password', ['exact' => true])->fill('Synthetic-lifecycle-07');
                $adminPage->locator('button[name="ea[newForm][btn]"][value="saveAndReturn"]')->click();
                $createdUserId = $adminPage->locator('tbody tr')->filter(['hasText' => $email])->getAttribute('data-id');
                self::assertNotNull($createdUserId);
                $foreignPage = $foreign->newPage();
                $this->login($foreignPage, $url, $email, 'Synthetic-lifecycle-07');
                self::assertSame(405, $foreignPage->goto($deleteAction)?->status());
                $foreignPage->goto($url.'/home/statement');
                self::assertSame(403, $this->postNativeDelete($foreignPage, $deleteAction), 'A valid-CSRF foreign-owner POST must be denied.');
                $this->deleteThroughModal($page, true);
                $page->goto($statement);
                self::assertSame(1, $page->locator('.action-delete')->count(), 'Forged CSRF must retain the statement.');
                $this->deleteThroughModal($page);
                self::assertSame(404, $page->goto($statement)?->status());
                self::assertSame(404, $page->goto($processing)?->status());
                self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', $download));
            } finally {
                try {
                    if (null !== $createdUserId) {
                        $adminPage->goto($url.'/home/user/'.$createdUserId);
                        $adminPage->locator('.action-delete')->click();
                        $adminPage->locator('#modal-action-confirmation-button')->click();
                    }
                } finally {
                    $foreign->close();
                    $admin->close();
                    $context->close();
                }
            }
        });
    }

    private function postNativeDelete(PageInterface $page, string $target): int
    {
        self::assertSame(1, $page->locator('#action-confirmation-form input[name=token]')->count());

        return $page->evaluate(<<<'JS'
            async target => {
                const form = document.querySelector('#action-confirmation-form');
                const response = await fetch(target, {method: 'POST', credentials: 'same-origin', body: new FormData(form)});
                return response.status;
            }
            JS, $target);
    }

    private function deleteThroughModal(PageInterface $page, bool $forged = false): void
    {
        $page->locator('.action-delete')->click();
        $page->locator('#modal-action-confirmation-button')->waitFor();
        self::assertStringContainsString('all linked transactions', $page->locator('#action-confirmation-message')->textContent());
        if ($forged) {
            $page->evaluate('() => document.querySelector("#action-confirmation-form [name=token]").value = "forged"');
        }
        $page->locator('#modal-action-confirmation-button')->click();
    }

    private function login(PageInterface $page, string $url, string $email, string $password): void
    {
        $page->goto($url.'/login');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
        $page->waitForURL('**/home/**');
    }

    /** @param callable(BrowserInterface, string): void $scenario */
    private function withBrowser(callable $scenario): void
    {
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\\.browser\\.finance\\.localhost$~D', $url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless(true)->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $scenario($browser, $url);
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
