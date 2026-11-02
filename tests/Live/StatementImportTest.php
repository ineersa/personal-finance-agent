<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;

final class StatementImportTest extends TestCase
{
    public function testUploadEditAndApproveDraftThenDeleteStatement(): void
    {
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless(true)->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $page->goto($url.'/login');
                $page->getByLabel('Email', ['exact' => true])->fill('demo@test.com');
                $page->getByLabel('Password', ['exact' => true])->fill('demo');
                $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
                $page->waitForURL('**/home/**');
                $page->goto($url.'/home/transaction');
                $approvedRows = $page->locator('tbody')->textContent();
                $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/demo-statements/expected.json'), true, flags: \JSON_THROW_ON_ERROR);
                $statement = array_find($manifest['statements'], static fn (array $entry): bool => 'chequing/2026-10.csv' === $entry['file']);
                self::assertNotNull($statement);
                $page->goto($url.'/home/statement-upload');
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles(\dirname(__DIR__, 2).'/resources/demo-statements/'.$statement['file']);
                $page->getByRole('checkbox')->check();
                $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $page->waitForURL('**/home/statement-processing/*');
                $page->locator('[data-processing-status][data-status="completed"]')->waitFor(['timeout' => 120000]);
                $rows = $page->locator('[data-draft-results] tbody tr');
                self::assertSame(\count($statement['rows']), $rows->count());
                self::assertSame($statement['rows'][0]['description'], $rows->first()->locator('td')->nth(1)->textContent());
                $amount = (new \NumberFormatter('en_US', \NumberFormatter::CURRENCY))->formatCurrency((float) $statement['rows'][0]['amount'], 'CAD');
                self::assertNotFalse($amount);
                self::assertSame($amount, $rows->first()->locator('td')->nth(2)->textContent());
                $id = basename((string) parse_url($page->url(), \PHP_URL_PATH));
                $rows->first()->getByRole('link', ['name' => 'Review draft', 'exact' => true])->click();
                $page->waitForURL('**/home/draft-review/*');
                $reviewUrl = $page->url();
                $transactionId = basename((string) parse_url($reviewUrl, \PHP_URL_PATH));
                $page->getByLabel('Transaction date', ['exact' => true])->fill('2026-10-02');
                $page->getByLabel('Amount', ['exact' => true])->fill('123.4500');
                $page->getByLabel('Currency', ['exact' => true])->fill('USD');
                $page->getByLabel('Direction', ['exact' => true])->selectOption(['label' => 'Debit']);
                $page->getByLabel('Description', ['exact' => true])->fill('Reviewed October transaction');
                $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour US Dollar · 9054']);
                $page->getByLabel('Category', ['exact' => true])->selectOption(['label' => 'Groceries']);
                $page->getByRole('button', ['name' => 'Save draft', 'exact' => true])->click();
                $page->getByText('Draft saved. Approval is still required.', ['exact' => true])->waitFor();
                $page->goto($reviewUrl);
                self::assertSame('123.4500', $page->getByLabel('Amount', ['exact' => true])->inputValue());
                self::assertSame('Reviewed October transaction', $page->getByLabel('Description', ['exact' => true])->inputValue());
                $page->goto($url.'/home/transaction');
                self::assertSame($approvedRows, $page->locator('tbody')->textContent(), 'Drafts must not change approved transactions.');
                $page->goto($reviewUrl);
                $page->getByRole('button', ['name' => 'Approve transaction', 'exact' => true])->click();
                $page->waitForURL('**/home/statement-processing/'.$id);
                self::assertSame(\count($statement['rows']) - 1, $page->locator('[data-draft-results] tbody tr')->count());
                $page->goto($url.'/home/transaction/'.$transactionId.'/edit');
                self::assertSame('2026-10-02', $page->getByLabel('Transaction Date', ['exact' => true])->inputValue());
                self::assertSame('123.4500', $page->getByLabel('Amount', ['exact' => true])->inputValue());
                self::assertSame('USD', $page->getByLabel('Currency', ['exact' => true])->inputValue());
                self::assertSame('Reviewed October transaction', $page->getByLabel('Description', ['exact' => true])->inputValue());
                self::assertSame('Maple Harbour US Dollar · 9054', $page->getByLabel('Source', ['exact' => true])->locator('option:checked')->textContent());
                self::assertSame('Groceries', $page->getByLabel('Category', ['exact' => true])->locator('option:checked')->textContent());
                $page->goto($url.'/home/statement/'.$id);
                $page->locator('.action-delete')->click();
                $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
                $page->locator('#modal-action-confirmation-button')->click();
                $page->locator('body.ea-index')->waitFor();
                self::assertSame(404, $page->evaluate('async url => (await fetch(url)).status', $url.'/home/statement/'.$id));
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
