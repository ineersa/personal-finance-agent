<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Enum\Direction;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;

/** Real web, broker, worker and hosted Jev; never kernel-intercepted or fixture-transaction wrapped. */
final class StatementImportTest extends TestCase
{
    public function testThreeSourceUploadsPreserveFactsAndPersistOnlyDrafts(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->goto($url.'/home/transaction');
                $approvedRows = $page->locator('tbody')->textContent();
                self::assertStringContainsString('Synthetic September salary', $approvedRows);
                $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/demo-statements/expected.json'), true, flags: \JSON_THROW_ON_ERROR);
                $labels = ['demo_current' => 'Maple Harbour Chequing · 7318', 'demo_card' => 'Maple Harbour Rewards Visa · 4826', 'demo_usd' => 'Maple Harbour US Dollar · 9054'];
                $saved = [];
                foreach ($manifest['statements'] as $statement) {
                    if ('upload_holdout' !== $statement['purpose']) {
                        continue;
                    }
                    $page->goto($url.'/home/statement-upload');
                    $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => $labels[$statement['source_fixture']]]);
                    $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                    $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles(\dirname(__DIR__, 2).'/resources/demo-statements/'.$statement['file']);
                    $page->getByRole('checkbox')->check();
                    $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                    $page->waitForURL('**/home/statement-processing/*');
                    $processingUrl = $page->url();
                    $page->evaluate('() => { window.importPageProbe = "sse-only"; }');
                    $page->locator('[data-processing-status]')->filter(['hasText' => 'Import complete'])->waitFor(['timeout' => 120000]);
                    self::assertSame('sse-only', $page->evaluate('() => window.importPageProbe'), 'Completion must update the current document without a page refresh.');
                    $page->locator('#import-notification a')->waitFor();
                    self::assertTrue($page->locator('#import-notification a')->isVisible());
                    $page->goto($processingUrl);
                    self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                    $rows = $page->locator('[data-draft-results] tbody tr');
                    self::assertSame(\count($statement['rows']), $rows->count());
                    foreach ($statement['rows'] as $index => $expected) {
                        $cells = $rows->nth($index)->locator('td');
                        self::assertSame($expected['date'], $cells->nth(0)->textContent());
                        self::assertSame($expected['description'], $cells->nth(1)->textContent());
                        self::assertSame($expected['amount'], $cells->nth(2)->textContent());
                        self::assertSame($statement['currency'], $cells->nth(3)->textContent());
                        self::assertSame(match ($expected['direction']) {
                            'credit' => Direction::Credit->value,
                            'debit' => Direction::Debit->value,
                        }, $cells->nth(4)->textContent());
                        self::assertSame($expected['meaning'], $cells->nth(6)->getAttribute('data-meaning'));
                    }
                    $page->reload();
                    self::assertSame(\count($statement['rows']), $page->locator('[data-draft-results] tbody tr')->count());
                    $saved[] = ['url' => $processingUrl, 'count' => \count($statement['rows'])];
                    $page->goto($url.'/home/transaction');
                    self::assertSame($approvedRows, $page->locator('tbody')->textContent(), 'Importing drafts must not change the approved transaction list.');
                }
                self::assertCount(3, $saved);
                file_put_contents(\dirname(__DIR__, 2).'/var/qa/statement-imports.json', json_encode($saved, \JSON_THROW_ON_ERROR));
            } finally {
                $context->close();
            }
        });
    }

    public function testMalformedUploadFailsWithoutDraftsAndAdministratorCannotReadImports(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->goto($url.'/home/statement-upload');
                self::assertStringNotContainsString('Admin private account', $page->getByLabel('Source', ['exact' => true])->textContent());
                $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                $malformed = \dirname(__DIR__, 2).'/var/qa/malformed.csv';
                file_put_contents($malformed, "not,a,valid\n");
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($malformed);
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                $processingUrl = $page->url();
                $page->locator('[data-processing-status]')->filter(['hasText' => 'Import failed'])->waitFor(['timeout' => 30000]);
                $page->reload();
                self::assertSame(0, $page->locator('[data-draft-results] tbody tr')->count());
                self::assertSame('Import failed', $page->locator('[data-processing-status]')->textContent());
                self::assertTrue($page->getByRole('button', ['name' => 'Retry processing'])->isVisible());
                self::assertSame(0, $page->getByRole('link', ['name' => 'Refresh processing status'])->count());
                self::assertStringContainsString('consistent comma-separated columns', $page->getByRole('alert')->textContent());
                $admin = $browser->newContext();
                try {
                    $adminPage = $admin->newPage();
                    $this->login($adminPage, $url, 'admin@test.com', 'admin');
                    self::assertSame(0, $adminPage->locator('turbo-mercure-stream-source')->count());
                    self::assertSame(403, $adminPage->goto($processingUrl)?->status());
                    self::assertSame(403, $adminPage->goto($url.'/home/statement-upload')?->status());
                } finally {
                    $admin->close();
                }
            } finally {
                $context->close();
            }
        });
    }

    public function testCompletionToastSurvivesNavigationToAnotherWorkspacePage(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->goto($url.'/home/statement-upload');
                $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Rewards Visa · 4826']);
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles(\dirname(__DIR__, 2).'/resources/demo-statements/credit/2026-10.csv');
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                $processingUrl = $page->url();
                $page->getByRole('link', ['name' => 'Sources', 'exact' => true])->click();
                $page->waitForURL('**/home/source');
                $page->locator('#import-notification a[href="'.parse_url($processingUrl, \PHP_URL_PATH).'"]')->waitFor(['timeout' => 120000]);
                self::assertSame($url.'/home/source', $page->url());
                self::assertStringContainsString('Import complete. Draft transactions are saved for review.', $page->locator('#import-notification')->textContent());
                self::assertSame(1, $page->locator('turbo-mercure-stream-source')->count());
                $page->locator('#import-notification a')->click();
                $page->waitForURL($processingUrl);
                self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                self::assertSame(17, $page->locator('[data-draft-results] tbody tr')->count());
            } finally {
                $context->close();
            }
        });
    }

    public function testPreviouslyCompletedImportsRemainUnchangedAfterOperatorRedelivery(): void
    {
        if ('1' !== getenv('FINANCE_IMPORT_REPLAY')) {
            self::markTestSkipped('Run browser:imports:test --replay after republishing the saved identifiers.');
        }
        $saved = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/var/qa/statement-imports.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->withBrowser(function (BrowserInterface $browser, string $url) use ($saved): void {
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                foreach ($saved as $statement) {
                    self::assertStringStartsWith($url.'/home/statement-processing/', $statement['url']);
                    $page->goto($statement['url']);
                    self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                    self::assertSame($statement['count'], $page->locator('[data-draft-results] tbody tr')->count());
                }
            } finally {
                $context->close();
            }
        });
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
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
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
