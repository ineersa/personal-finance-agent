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
    public function testSubscriptionOpeningAfterCompletionCatchesUpWithoutReload(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = tempnam(sys_get_temp_dir(), 'import-gap-');
            self::assertNotFalse($path);
            rename($path, $path.'.csv');
            $path .= '.csv';
            file_put_contents($path, "Date,Debit,Credit,Description\n2026-10-01,1.00,,Coffee\n2026-10-02,,2.00,Dinner refund\n");
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                // Delay only the processing document's connection, not the real worker or hub.
                $page->addInitScript(<<<'JS'
                    if (location.pathname.includes('/statement-processing/')) {
                        const NativeEventSource = window.EventSource;
                        window.EventSource = class extends EventTarget {
                            constructor(url, options) {
                                super();
                                window.releaseImportSubscription = () => {
                                    this.source = new NativeEventSource(url, options);
                                    for (const type of ['open', 'error', 'message']) {
                                        this.source.addEventListener(type, event => this.dispatchEvent(type === 'message'
                                            ? new MessageEvent(type, {data: event.data}) : new Event(type)));
                                    }
                                };
                            }
                            close() { this.source?.close(); }
                        };
                    }
                    JS);
                $page->goto($url.'/home/statement-upload');
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                self::assertContains($page->locator('[data-processing-status]')->getAttribute('data-status'), ['queued', 'running'], 'The document must capture an active state before delaying its subscription.');
                $page->evaluate('() => { window.importPageProbe = "missed-event"; }');
                $probe = $context->newPage();
                try {
                    $deadline = time() + 120;
                    do {
                        $probe->goto($page->url());
                        if ('completed' === $probe->locator('[data-processing-status]')->getAttribute('data-status')) {
                            break;
                        }
                        usleep(250000);
                    } while (time() < $deadline);
                    self::assertSame('completed', $probe->locator('[data-processing-status]')->getAttribute('data-status'));
                } finally {
                    $probe->close();
                }
                // Reload just the native stream source after its terminal event is already gone.
                $page->evaluate('() => window.releaseImportSubscription()');
                $page->locator('[data-processing-status][data-status="completed"]')->waitFor();
                self::assertSame('missed-event', $page->evaluate('() => window.importPageProbe'));
                self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count());
                self::assertSame(0, $page->locator('#import-notification')->count());
            } finally {
                unlink($path);
                $context->close();
            }
        });
    }

    public function testThreeSourceUploadsPreserveFactsAndPersistOnlyDrafts(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $page->goto($url.'/home/transaction');
                $approvedRows = $page->locator('tbody')->textContent();
                self::assertStringContainsString('PAYROLL LANTERN LABS', $approvedRows);
                $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/demo-statements/expected.json'), true, flags: \JSON_THROW_ON_ERROR);
                $labels = ['demo_current' => 'Maple Harbour Chequing · 7318', 'demo_card' => 'Maple Harbour Rewards Visa · 4826', 'demo_usd' => 'Maple Harbour US Dollar · 9054'];
                $processed = 0;
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
                    self::assertSame(0, $page->locator('#import-notification')->count(), 'Import completion must not render a global toast.');
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
                    ++$processed;
                    $page->goto($url.'/home/transaction');
                    self::assertSame($approvedRows, $page->locator('tbody')->textContent(), 'Importing drafts must not change the approved transaction list.');
                }
                self::assertSame(3, $processed);
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
                file_put_contents($malformed, "not,a,valid\nunequal,row\n");
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

    public function testQuotedCsvRejectsUnterminatedFieldsAndPreservesMultilineDescriptions(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = \dirname(__DIR__, 2).'/var/qa/import-quoting-'.bin2hex(random_bytes(6)).'.csv';
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                $cases = [
                    'failed' => "Date,Debit,Credit,Description\n2026-10-01,1.00,,\"Coffee\n2026-10-02,2.00,,Dinner",
                    // CRLF records, leading field whitespace, an embedded newline and doubled quotes.
                    // The final record deliberately has no trailing newline.
                    'completed' => "\xEF\xBB\xBFDate,Debit,Credit,Description\r\n2026-10-01,1.00,, \t\"Coffee\nwith \"\"oat\"\" milk\"\r\n2026-10-02,,2.00,\"Dinner refund\"",
                ];
                foreach ($cases as $expectedStatus => $content) {
                    file_put_contents($path, $content);
                    $page->goto($url.'/home/statement-upload');
                    $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                    $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                    $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                    $page->getByRole('checkbox')->check();
                    $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                    $page->waitForURL('**/home/statement-processing/*');
                    $page->locator('[data-processing-status][data-status='.$expectedStatus.']')->waitFor(['timeout' => 120000]);
                    $page->reload();
                    self::assertSame($expectedStatus, $page->locator('[data-processing-status]')->getAttribute('data-status'));
                    $rows = $page->locator('[data-draft-results] tbody tr');
                    if ('failed' === $expectedStatus) {
                        self::assertSame(0, $rows->count());
                        self::assertStringContainsString('unterminated quoted field', $page->getByRole('alert')->textContent());
                        continue;
                    }
                    self::assertSame(2, $rows->count(), 'A quoted newline must not swallow the following transaction.');
                    foreach ([["Coffee\nwith \"oat\" milk", '1.00', '2026-10-01', 'Debit'], ['Dinner refund', '2.00', '2026-10-02', 'Credit']] as $index => [$description, $amount, $date, $direction]) {
                        $cells = $rows->nth($index)->locator('td');
                        self::assertSame($date, $cells->nth(0)->textContent());
                        self::assertSame($description, $cells->nth(1)->textContent());
                        self::assertSame($amount, $cells->nth(2)->textContent());
                        self::assertSame('CAD', $cells->nth(3)->textContent());
                        self::assertSame($direction, $cells->nth(4)->textContent());
                    }
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
                $context->close();
            }
        });
    }

    public function testLocalizedCsvFormatsAndExtraColumnsPreserveExactFacts(): void
    {
        $this->withBrowser(function (BrowserInterface $browser, string $url): void {
            $context = $browser->newContext();
            $path = \dirname(__DIR__, 2).'/var/qa/localized-import.csv';
            try {
                $page = $context->newPage();
                $this->login($page, $url, 'demo@test.com', 'demo');
                foreach ([['31/10/2026', '30/10/2026', '1.234,56789012', '2,50'], ['10/31/2026', '10/30/2026', '1,234.56789012', '2.50']] as [$date, $refundDate, $debit, $credit]) {
                    $extra = str_repeat(',unused', 10);
                    file_put_contents($path, 'Date,Debit,Credit,Description'.$extra."\n".$date.',"'.$debit.'",,Coffee'.$extra."\n".$refundDate.',,"'.$credit.'",Coffee refund'.$extra."\n");
                    $page->goto($url.'/home/statement-upload');
                    $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                    $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                    $page->getByRole('checkbox')->check();
                    $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                    $page->waitForURL('**/home/statement-processing/*');
                    $page->locator('[data-processing-status][data-status="completed"]')->waitFor(['timeout' => 120000]);
                    $rows = $page->locator('[data-draft-results] tbody tr');
                    self::assertSame(2, $rows->count());
                    self::assertSame('2026-10-31', $rows->nth(0)->locator('td')->nth(0)->textContent());
                    self::assertSame('1234.56789012', $rows->nth(0)->locator('td')->nth(2)->textContent());
                    self::assertSame('2026-10-30', $rows->nth(1)->locator('td')->nth(0)->textContent());
                    self::assertSame('2.50', $rows->nth(1)->locator('td')->nth(2)->textContent());
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
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
