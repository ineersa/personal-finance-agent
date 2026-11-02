<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;

final class UploadBoundaryTest extends TestCase
{
    public function testExactTenMiBReachesParserButOneExtraByteIsRejectedBeforeQueueing(): void
    {
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $client = PlaywrightFactory::create();
        $browser = null;
        $path = \dirname(__DIR__, 2).'/var/qa/upload-boundary.csv';
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
                foreach ([10 * 1024 * 1024, 10 * 1024 * 1024 + 1] as $size) {
                    // Deliberately malformed, avoiding provider calls while proving real HTTP/PHP size admission.
                    file_put_contents($path, str_repeat('x', $size));
                    $page->goto($url.'/home/statement-upload');
                    $page->locator('turbo-mercure-stream-source[connected]')->waitFor(['state' => 'attached']);
                    $page->getByLabel('Source', ['exact' => true])->selectOption(['label' => 'Maple Harbour Chequing · 7318']);
                    $page->getByLabel('CSV statement', ['exact' => true])->setInputFiles($path);
                    $page->getByRole('checkbox')->check();
                    $page->getByRole('button', ['name' => 'Upload and prepare drafts'])->click();
                    if (10 * 1024 * 1024 === $size) {
                        $page->waitForURL('**/home/statement-processing/*');
                        $page->locator('[data-processing-status]')->filter(['hasText' => 'failed'])->waitFor(['timeout' => 30000]);
                        self::assertStringContainsString('consistent comma-separated columns', $page->getByRole('alert')->textContent());
                        self::assertSame(0, $page->locator('[data-draft-results] tbody tr')->count());
                    } else {
                        $page->getByText('The file is too large', ['exact' => false])->waitFor();
                        self::assertSame($url.'/home/statement-upload', $page->url());
                    }
                }
            } finally {
                $context->close();
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            $browser?->close();
            $client->close();
        }
    }
}
