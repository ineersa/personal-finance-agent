<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\DataFixtures\StatementFileProcessor;
use App\Entity\PendingStatementFileCleanup;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testCleanupNeverDeletesAFileStillReferencedAfterAnIncompleteReset(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $retained = $fixtures['demo_statement'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new PendingStatementFileCleanup($retained->storedFilename));
        $em->flush();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/statement/'.$fixtures['demo_card_statement']->getId());
        $page->locator('.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->getByRole('heading', ['name' => 'Statements', 'exact' => true])->waitFor();
        $download = $page->evaluate('async url => { const r = await fetch(url); return {status: r.status, body: await r.text()}; }', '/home/statement/'.$retained->getId().'/download');
        self::assertSame(200, $download['status']);
        self::assertSame($retained->sha256, hash('sha256', $download['body']));
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
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pending_statement_file_cleanup'));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS refuse_statement_delete');
        }
    }

    public function testCommittedDeleteRetainsCleanupIntentWhenStorageFails(): void
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
            $page->getByText('Statement records were deleted.', ['exact' => false])->waitFor();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM statement WHERE id = ?', [$statement->getId()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM financial_transaction WHERE statement_id = ?', [$statement->getId()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM pending_statement_file_cleanup WHERE filename = ?', [$statement->storedFilename]));
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
        // A later native browser deletion drains prior committed cleanup, including the restored file.
        $second = $fixtures['demo_card_statement'];
        $page = $this->visit('/home/statement/'.$second->getId());
        $page->locator('.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->getByRole('heading', ['name' => 'Statements', 'exact' => true])->waitFor();
        self::assertFileDoesNotExist($path);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pending_statement_file_cleanup'));
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
