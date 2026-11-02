<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;

final class ImportPrivacyTest extends TestCase
{
    public function testForeignWorkspaceCannotReadStatementOrSubscribeToItsPrivateTopic(): void
    {
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
        $saved = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/var/qa/statement-imports.json'), true, flags: \JSON_THROW_ON_ERROR);
        $statementUrl = $saved[0]['url'];
        self::assertStringStartsWith($url.'/home/statement-processing/', $statementUrl);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless(true)->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $owner = $browser->newContext();
            $administrator = $browser->newContext();
            $foreign = $browser->newContext();
            try {
                $ownerPage = $owner->newPage();
                $this->login($ownerPage, $url, 'demo@test.com', 'demo');
                $ownerPage->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                $ownerSubscription = $ownerPage->locator('turbo-mercure-stream-source')->getAttribute('src');
                self::assertNotNull($ownerSubscription);
                $ownerPage->goto($url.'/home/statement-upload');
                $foreignSourceId = $ownerPage->getByLabel('Source', ['exact' => true])->locator('option')->filter(['hasText' => 'Maple Harbour Chequing'])->getAttribute('value');
                self::assertNotNull($foreignSourceId);
                $adminPage = $administrator->newPage();
                $this->login($adminPage, $url, 'admin@test.com', 'admin');
                $email = 'import-isolation-'.bin2hex(random_bytes(6)).'@example.test';
                $password = 'Synthetic-import-isolation-07';
                $adminPage->goto($url.'/home/user/new');
                $adminPage->getByLabel('Email', ['exact' => true])->fill($email);
                $adminPage->getByLabel('New password', ['exact' => true])->fill($password);
                $adminPage->locator('button[name="ea[newForm][btn]"]')->first()->click();
                $adminPage->goto($url.'/home/user');
                $userDetail = $adminPage->locator('tbody tr')->filter(['hasText' => $email])->locator('a.action-detail')->getAttribute('href');
                self::assertNotNull($userDetail);
                $foreignPage = $foreign->newPage();
                $this->login($foreignPage, $url, $email, $password);
                $foreignPage->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                self::assertNotSame($ownerSubscription, $foreignPage->locator('turbo-mercure-stream-source')->getAttribute('src'));
                $status = $foreignPage->evaluate('async src => { const response = await fetch(src, { credentials: "include", headers: {Accept: "text/event-stream"} }); const status = response.status; await response.body?.cancel(); return status; }', $ownerSubscription);
                self::assertContains($status, [401, 403], 'The hub must refuse a foreign owner topic using this browser’s own subscriber cookie.');
                self::assertSame(404, $foreignPage->goto($statementUrl)?->status());
                $foreignPage->goto($url.'/home/statement-upload');
                self::assertStringNotContainsString('Maple Harbour', $foreignPage->getByLabel('Source', ['exact' => true])->textContent());
                $foreignPage->getByLabel('Source', ['exact' => true])->evaluate('(select, id) => { const option = new Option("Foreign source", id, true, true); select.add(option); }', $foreignSourceId);
                $foreignPage->getByLabel('CSV statement', ['exact' => true])->setInputFiles(\dirname(__DIR__, 2).'/resources/demo-statements/usd/2026-10.csv');
                $foreignPage->getByRole('checkbox')->check();
                $foreignPage->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                $foreignPage->getByText('The selected choice is invalid.')->waitFor();
                self::assertSame($url.'/home/statement-upload', $foreignPage->url());
                $adminPage->goto($userDetail);
                $adminPage->locator('.action-delete')->click();
                $adminPage->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
                $adminPage->locator('#modal-action-confirmation-button')->click();
            } finally {
                $foreign->close();
                $administrator->close();
                $owner->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }

    private function login(PageInterface $page, string $url, string $email, string $password): void
    {
        $page->goto($url.'/login');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
        $page->waitForURL('**/home/**');
    }
}
