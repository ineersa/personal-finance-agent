<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\DataFixtures\StatementFileProcessor;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class StatementDeletionTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreCanonicalFiles();
    }

    protected function tearDown(): void
    {
        try {
            // Alice rolls back metadata, not filesystem deletions, including after failed assertions.
            $this->restoreCanonicalFiles();
        } finally {
            parent::tearDown();
        }
    }

    public function testFailedDatabaseRemovalKeepsOriginalDownloadable(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $statement = $fixtures['demo_statement'];
        $connection = self::getContainer()->get(Connection::class);
        // Native SQLite fault at deletion, after authorization but before commit.
        $connection->executeStatement("CREATE TEMP TRIGGER refuse_statement_delete BEFORE DELETE ON statement BEGIN SELECT RAISE(ABORT, 'synthetic deletion fault'); END");
        try {
            $this->loginUser($fixtures['demo']);
            $page = $this->visit('/home/statement/'.$statement->getId());
            $page->locator('.action-delete')->click();
            $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
            $page->getByText('Statement deletion could not be committed.', ['exact' => false])->waitFor();
            $download = $page->evaluate('async url => { const r = await fetch(url); return {status: r.status, body: await r.text()}; }', '/home/statement/'.$statement->getId().'/download');
            self::assertSame(200, $download['status']);
            self::assertSame($statement->sha256, hash('sha256', $download['body']));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS refuse_statement_delete');
        }
    }

    public function testCommittedDeleteReportsStorageFailureHonestly(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $statement = $fixtures['demo_statement'];
        $connection = self::getContainer()->get(Connection::class);
        $root = self::getContainer()->getParameter('statement_storage_directory');
        self::assertIsString($root);
        self::assertStringContainsString('/var/browser/', $root);
        $path = $root.'/'.$statement->storedFilename;
        $backup = $path.'.deletion-test-backup';
        try {
            self::assertTrue(rename($path, $backup));
            self::assertTrue(mkdir($path));
            // An occupied directory at the file path makes native Flysystem unlink fail.
            file_put_contents($path.'/synthetic-blocker', 'synthetic');
            $this->loginUser($fixtures['demo']);
            $page = $this->visit('/home/statement/'.$statement->getId());
            $page->locator('.action-delete')->click();
            $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
            $page->getByText('Statement records were deleted, but', ['exact' => false])->waitFor();
            self::assertStringContainsString('Orphaned bytes may remain in private storage.', $page->getByRole('alert')->textContent());
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM statement WHERE id = ?', [$statement->getId()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM financial_transaction WHERE statement_id = ?', [$statement->getId()]));
        } finally {
            if (is_file($path.'/synthetic-blocker')) {
                unlink($path.'/synthetic-blocker');
            }
            if (is_dir($path)) {
                rmdir($path);
            }
            if (is_file($backup)) {
                rename($backup, $path);
            }
        }
        // A later deletion removes only its own bytes, not another statement's orphan.
        $second = $fixtures['demo_card_statement'];
        $page = $this->visit('/home/statement/'.$second->getId());
        $page->locator('.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->locator('body.ea-index')->waitFor();
        self::assertResponseIsSuccessful();
        self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', '/home/statement/'.$second->getId()));
        self::assertFileExists($path);
    }

    private function restoreCanonicalFiles(): void
    {
        $root = self::getContainer()->getParameter('statement_storage_directory');
        self::assertIsString($root);
        self::assertStringEndsWith('/var/browser/statements', $root);
        $processor = self::getContainer()->get(StatementFileProcessor::class);
        $fixtures = FixtureStore::getFixtures();
        foreach (['demo_statement', 'demo_card_statement'] as $reference) {
            $processor->preProcess($reference, $fixtures[$reference]);
        }
    }
}
