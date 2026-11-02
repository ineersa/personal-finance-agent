<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class StatementDownloadTest extends FinanceBrowserTest
{
    public function testListViewAndDownloadStatement(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $statement = $fixtures['demo_statement'];
        $this->visit('/home/statement?query='.rawurlencode($statement->originalFilename));
        $this->assertPageContains($statement->originalFilename);
        $page = $this->visit('/home/statement/'.$statement->getId());
        $link = $page->locator('a.action-download');
        self::assertTrue($link->isVisible());
        $download = $page->evaluate('async url => { const response = await fetch(url); return {status: response.status, body: await response.text(), disposition: response.headers.get("content-disposition")}; }', $link->getAttribute('href'));
        self::assertSame(200, $download['status']);
        self::assertSame($statement->sha256, hash('sha256', $download['body']));
        self::assertStringContainsString('attachment;', $download['disposition']);
    }
}
