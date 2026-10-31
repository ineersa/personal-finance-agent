<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class StatementDownloadTest extends FinanceBrowserTest
{
    public function testOwnerDownloadsSyntheticBytesThroughStatementUiWithoutTrustingPathsOrFilenames(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        foreach (['demo_statement' => 'demo-october-2026.csv', 'demo_card_statement' => 'hostile_script_.csv'] as $reference => $filename) {
            $statement = $fixtures[$reference];
            $page = $this->visit('/home/statement/'.$statement->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(0, $page->locator('script[src*="hostile"]')->count());
            $link = $page->locator('a.action-download');
            self::assertSame(1, $link->count());
            $url = $link->getAttribute('href');
            $download = $page->evaluate(<<<'JS'
                async (url) => {
                    const response = await fetch(url + '?path=../../etc/passwd&url=file:///etc/passwd&filename=../../etc/passwd');
                    return {status: response.status, body: await response.text(), disposition: response.headers.get('content-disposition'), type: response.headers.get('content-type'), cache: response.headers.get('cache-control'), nosniff: response.headers.get('x-content-type-options')};
                }
                JS, $url);
            self::assertSame(200, $download['status']);
            self::assertSame($statement->sha256, hash('sha256', $download['body']));
            self::assertSame($statement->byteSize, \strlen($download['body']));
            self::assertStringContainsString('Synthetic fixture '.$reference, $download['body']);
            self::assertSame('attachment; filename='.$filename, $download['disposition']);
            self::assertSame('application/octet-stream', $download['type']);
            self::assertStringContainsString('no-store', $download['cache']);
            self::assertSame('nosniff', $download['nosniff']);
            foreach (['/statements/'.$statement->storageKey, '/var/statements/'.$statement->storageKey, '/var/browser/statements/'.$statement->storageKey, '/home/statement/not-a-statement/download', '/home/statement/%2E%2E%2Fetc%2Fpasswd/download'] as $path) {
                self::assertSame(404, $page->evaluate('async path => (await fetch(path)).status', $path), $path);
            }
        }
    }

    public function testForeignAndAdministrativeAccessCannotDownloadEvenOwnedAdministrativeFiles(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['demo']);
        $page = $this->visit('/home/statement');
        self::assertSame(404, $page->evaluate('async path => (await fetch(path)).status', '/home/statement/'.$fixtures['admin_statement']->getId().'/download'));
        $this->loginUser($fixtures['administrator']);
        $page = $this->visit('/home/user');
        foreach (['demo_statement', 'admin_statement'] as $reference) {
            self::assertSame(403, $page->evaluate('async path => (await fetch(path)).status', '/home/statement/'.$fixtures[$reference]->getId().'/download'));
        }
    }

    public function testAnonymousDownloadRequiresLogin(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $page = $this->visit('/home/statement/'.$fixtures['demo_statement']->getId().'/download');
        self::assertSame(1, $page->locator('input[name="_username"]')->count());
        self::assertStringNotContainsString('Synthetic fixture', $page->locator('body')->textContent());
    }
}
