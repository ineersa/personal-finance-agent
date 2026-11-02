<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Message\ProcessStatementMessage;
use Doctrine\DBAL\DriverManager;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/** Standalone real-browser recovery checks on test-owned storage, without worker choreography. */
final class StatementLifecycleTest extends TestCase
{
    public function testFailedAndRunningRetriesAndRunningRedeliveryPreserveOneResultSet(): void
    {
        $root = \dirname(__DIR__, 2).'/var/browser-live';
        foreach ([$root, $root.'/browser', $root.'/browser/application.db', $root.'/queue', $root.'/queue/broker.db', $root.'/queue/broker.sock'] as $path) {
            self::assertFalse(is_link($path), 'Refuse symlinked isolated storage.');
            self::assertFileExists($path);
        }
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $root.'/browser/application.db']);
        $queue = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $root.'/queue/broker.db']);
        self::assertSame(0, (int) $queue->fetchOne('SELECT COUNT(*) FROM queue_messages'), 'Run this scenario without unrelated pending imports.');
        $path = \dirname(__DIR__, 2).'/var/qa/lifecycle-retry.csv';
        file_put_contents($path, "Date,Debit,Credit,Description\n2026-10-01,1.00,,Coffee\n2026-10-02,,2.00,Coffee refund\n");
        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless(true)->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $context = $browser->newContext();
            $page = $context->newPage();
            $page->goto($url.'/login');
            $page->getByLabel('Email', ['exact' => true])->fill('demo@test.com');
            $page->getByLabel('Password', ['exact' => true])->fill('demo');
            $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
            $page->waitForURL('**/home/**');
            $page->goto($url.'/home/statement-upload');
            $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
            $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
            $page->getByRole('checkbox')->check();
            $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
            $page->waitForURL('**/home/statement-processing/*');
            $processing = $page->url();
            $id = (int) basename($processing);
            $page->locator('[data-status="completed"]')->waitFor(['timeout' => 120000]);
            self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count());
            $statement = $db->fetchAssociative('SELECT * FROM statement WHERE id = ?', [$id]);
            self::assertIsArray($statement);
            self::assertSame(basename($path), $statement['original_filename']);

            // Reproduce interrupted persisted states using only this scenario's uploaded record.
            // Browser retry, broker delivery, provider work and saved results remain real.
            foreach (['failed', 'running'] as $status) {
                $this->awaitEmptyQueue($queue);
                $db->transactional(static function ($db) use ($id, $status): void {
                    $db->delete('financial_transaction', ['statement_id' => $id]);
                    $db->update('statement', ['processing_status' => $status, 'processing_error' => null], ['id' => $id]);
                });
                $page->addInitScript(<<<'JS'
                    const nativeFetch = window.fetch;
                    window.fetch = async (...args) => {
                        const response = await nativeFetch(...args);
                        if (args[1]?.headers?.Accept === 'text/vnd.turbo-stream.html' && response.status === 200) {
                            const body = await response.text();
                            window.snapshotHeld = true;
                            response.text = () => new Promise(resolve => { window.releaseSnapshot = () => resolve(body); });
                        }
                        return response;
                    };
                    JS);
                $page->goto($processing);
                self::assertSame($status, $page->locator('[data-processing-status]')->getAttribute('data-status'));
                self::assertTrue($page->getByRole('button', ['name' => 'Retry processing', 'exact' => true])->isVisible());
                if ('failed' === $status) {
                    $page->waitForFunction('() => window.snapshotHeld === true');
                }
                $page->evaluate('() => window.retryProbe = "same-document"');
                $retry = $context->newPage();
                try {
                    $retry->goto($processing);
                    $retry->getByRole('button', ['name' => 'Retry processing', 'exact' => true])->click();
                    $page->locator('[data-status="completed"]')->waitFor(['timeout' => 120000]);
                    if ('failed' === $status) {
                        $page->evaluate('async () => { window.releaseSnapshot(); await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); }');
                    }
                    self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
                    self::assertSame('same-document', $page->evaluate('() => window.retryProbe'));
                    self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count());
                    self::assertSame(0, $page->getByRole('button', ['name' => 'Retry processing', 'exact' => true])->count());
                } finally {
                    $retry->close();
                }
            }

            $this->awaitEmptyQueue($queue);
            $db->transactional(static function ($db) use ($id): void {
                $db->delete('financial_transaction', ['statement_id' => $id]);
                $db->update('statement', ['processing_status' => 'running'], ['id' => $id]);
            });
            $transport = new TransportFactory()->createTransport('sqlite-queue://statement-imports', ['endpoint' => $root.'/queue/broker.sock'], new PhpSerializer());
            $message = new Envelope(new ProcessStatementMessage($id, (int) $statement['user_id'], $statement['stored_filename']));
            $transport->send($message);
            $transport->send($message);
            $this->awaitEmptyQueue($queue);
            $page->goto($processing);
            self::assertSame('completed', $page->locator('[data-processing-status]')->getAttribute('data-status'));
            self::assertSame(2, $page->locator('[data-draft-results] tbody tr')->count(), 'Running redelivery and its duplicate must save only one result set.');
        } finally {
            $browser?->close();
            $client->close();
            $db->close();
            $queue->close();
            unlink($path);
        }
    }

    private function awaitEmptyQueue(\Doctrine\DBAL\Connection $queue): void
    {
        $deadline = microtime(true) + 120;
        do {
            if (0 === (int) $queue->fetchOne('SELECT COUNT(*) FROM queue_messages')) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        self::fail('The isolated worker did not acknowledge its messages.');
    }
}
