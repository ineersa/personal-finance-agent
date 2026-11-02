<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;

/** Destructive browser proof: the Castor task accepts only the isolated browser stack. */
final class DemoMaintenanceTest extends TestCase
{
    public function testBusyImportsAndUnconfirmedRequestsCannotResetOrDelete(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = \dirname(__DIR__, 2).'/var/qa/maintenance-malformed.csv';
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->goto($url.'/home/settings/reset');
                $page->evaluate('() => document.querySelector("form[name=form]").noValidate = true');
                $page->getByRole('button', ['name' => 'Delete all data and restore demo'])->click();
                $page->getByText('Confirm the database-wide reset to continue.')->waitFor();
                $page->goto($url.'/home/settings/reset');
                $page->getByRole('checkbox')->check();
                $page->evaluate('() => document.querySelector("input[name=\"form[_token]\"]").value = "forged"');
                $page->getByRole('button', ['name' => 'Delete all data and restore demo'])->click();
                $page->getByText('The CSRF token is invalid.', ['exact' => false])->waitFor();

                // Castor has stopped only this isolated worker; queued state is deterministic.
                file_put_contents($path, "not,a,valid,statement\ninvalid,row\n");
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
                $page->goto($url.'/home/settings/reset');
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Delete all data and restore demo'])->click();
                $page->getByText('Reset refused: an import is queued or running.', ['exact' => false])->waitFor();
                file_put_contents(\dirname(__DIR__, 2).'/var/qa/maintenance-import.json', json_encode(['id' => $id], \JSON_THROW_ON_ERROR));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
                $context->close();
            }
        });
    }

    public function testNativeDeleteAndConfirmedResetRestoreCanonicalFixturesAndSignOutSessions(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $stale = $browser->newContext();
            $admin = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $stalePage = $stale->newPage();
                $this->login($stalePage, $url, 'demo@test.com', 'demo');
                $adminPage = $admin->newPage();
                $this->login($adminPage, $url, 'admin@test.com', 'admin');
                self::assertSame(403, $adminPage->goto($url.'/home/settings/reset')?->status());
                $email = 'maintenance-'.bin2hex(random_bytes(6)).'@example.test';
                $adminPage->goto($url.'/home/user/new');
                $adminPage->getByLabel('Email', ['exact' => true])->fill($email);
                $adminPage->getByLabel('New password', ['exact' => true])->fill('Synthetic-maintenance-07');
                $adminPage->locator('button[name="ea[newForm][btn]"]')->first()->click();
                $page->goto($url.'/home/statement');
                $foreignTarget = $page->locator('tbody tr a.action-detail')->first()->getAttribute('href');
                self::assertNotNull($foreignTarget);
                $foreign = $browser->newContext();
                try {
                    $foreignPage = $foreign->newPage();
                    $this->login($foreignPage, $url, $email, 'Synthetic-maintenance-07');
                    self::assertSame(403, $foreignPage->goto($url.'/home/settings/reset')?->status());
                    self::assertSame(405, $foreignPage->goto($foreignTarget.'/delete')?->status());
                    $foreignPage->goto($url.'/home/statement');
                    self::assertSame(403, $this->postNativeDelete($foreignPage, $foreignTarget.'/delete'), 'A valid-CSRF foreign-owner POST must be denied, not merely an unsupported method.');
                } finally {
                    $foreign->close();
                }
                $saved = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/var/qa/maintenance-import.json'), true, flags: \JSON_THROW_ON_ERROR);
                $page->goto($url.'/home/statement-processing/'.$saved['id']);
                $page->locator('[data-processing-status][data-status=failed]')->waitFor(['timeout' => 30000]);
                $page->goto($url.'/home/statement/'.$saved['id']);
                $uploadDownload = $page->locator('.action-download')->getAttribute('href');

                // Existing isolated stacks can predate the canonical export. Establish the
                // baseline through the real reset UI, not assumptions or direct fixture writes.
                $this->resetThroughSettings($page, $url);
                $this->login($page, $url, 'demo@test.com', 'demo');
                self::assertSame(404, $page->goto($url.'/home/statement-processing/'.$saved['id'])?->status());
                self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', $uploadDownload));
                $page->goto($url.'/home/statement');
                self::assertStringContainsString('69', $page->locator('.list-pagination-counter')->textContent(), 'Initial browser reset must restore canonical history before deletion checks.');
                $page->goto($url.'/home/transaction');
                self::assertStringContainsString('733', $page->locator('.list-pagination-counter')->textContent(), 'Initial browser reset must restore canonical approved transactions.');
                $this->login($adminPage, $url, 'admin@test.com', 'admin');
                $this->login($stalePage, $url, 'demo@test.com', 'demo');

                // Follow a real approved row to its statement, then prove linked-row removal.
                $page->goto($url.'/home/transaction?query=USD');
                $approvedUsdRows = $page->locator('tbody tr a.action-detail');
                self::assertGreaterThan(0, $approvedUsdRows->count(), 'Canonical demo history must expose approved USD transactions before following a detail link.');
                $transaction = $approvedUsdRows->first()->getAttribute('href');
                self::assertNotNull($transaction);
                $page->goto($transaction);
                self::assertSame(1, $page->locator('a[href*="/home/statement/"]')->count(), 'The approved transaction detail must link to its source statement.');
                $statement = $page->locator('a[href*="/home/statement/"]')->first()->getAttribute('href');
                self::assertNotNull($statement);
                $processing = $url.'/home/statement-processing/'.basename($statement);
                $page->goto($processing);
                self::assertGreaterThan(0, $page->locator('[data-draft-results] tbody tr')->count());
                $page->goto($statement);
                $download = $page->locator('.action-download')->getAttribute('href');
                $deleteAction = $page->locator('.action-delete')->getAttribute('formaction');
                self::assertNotNull($deleteAction);
                $adminPage->goto($url.'/home/user');
                self::assertSame(403, $this->postNativeDelete($adminPage, $deleteAction), 'Administrator roles do not authorize deleting another owner’s statement.');
                $this->deleteThroughModal($page, true);
                $page->goto($statement);
                self::assertSame(1, $page->locator('.action-delete')->count(), 'Forged CSRF must not delete the statement.');
                $this->deleteThroughModal($page);
                $page->waitForURL('**/home/statement');
                self::assertSame(404, $page->goto($transaction)?->status());
                self::assertSame(404, $page->goto($statement)?->status());
                self::assertSame(404, $page->goto($processing)?->status());
                self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', $download));

                $page->goto($url.'/home/settings');
                $page->getByLabel('Reporting currency')->selectOption('EUR');
                $page->getByRole('button', ['name' => 'Save preferences'])->click();
                $page->getByText('Reporting currency saved. Original amounts are unchanged.')->waitFor();
                self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
                $this->resetThroughSettings($page, $url);
                $stalePage->goto($url.'/home/source');
                $stalePage->waitForURL('**/login');
                $adminPage->goto($url.'/home/user');
                $adminPage->waitForURL('**/login');
                $this->login($page, $url, 'demo@test.com', 'demo');
                self::assertSame(404, $page->goto($url.'/home/statement-processing/'.$saved['id'])?->status());
                self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', $uploadDownload));
                $page->goto($url.'/home/settings');
                self::assertSame('CAD', $page->getByLabel('Reporting currency')->inputValue());
                $page->goto($url.'/home/source');
                self::assertSame(3, $page->locator('tbody tr[data-id]')->count());
                $page->goto($url.'/home/statement');
                self::assertStringContainsString('69', $page->locator('.list-pagination-counter')->textContent());
                $page->goto($url.'/home/transaction');
                self::assertStringContainsString('733', $page->locator('.list-pagination-counter')->textContent());
                self::assertSame(404, $page->goto($statement)?->status(), 'Reset must not reuse deleted statement identities.');
                $this->login($adminPage, $url, 'admin@test.com', 'admin');
                $adminPage->goto($url.'/home/user');
                self::assertSame(2, $adminPage->locator('tbody tr[data-id]')->count());
                $adminPage->goto($url.'/home/category');
                self::assertSame(21, $adminPage->locator('tbody tr[data-id]')->count());
            } finally {
                $admin->close();
                $stale->close();
                $context->close();
            }
        });
    }

    private function resetThroughSettings(PageInterface $page, string $url): void
    {
        $page->goto($url.'/home/settings');
        self::assertSame(1, $page->getByRole('link', ['name' => 'Reset demo data…'])->count(), 'Explicit local-demo reset enablement is required.');
        $page->getByRole('link', ['name' => 'Reset demo data…'])->click();
        $page->getByRole('checkbox')->check();
        $page->getByRole('button', ['name' => 'Delete all data and restore demo'])->click();
        $page->waitForURL('**/login');
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
        if ('1' !== getenv('FINANCE_DEMO_RESET_CONFIRM')) {
            self::markTestSkipped('Run only through browser:maintenance:test --confirm-reset against isolated storage.');
        }
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
