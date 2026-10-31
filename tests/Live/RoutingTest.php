<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;

/** Direct-network browser regression; invoked through browser:routes:test, not kernel interception. */
final class RoutingTest extends TestCase
{
    public function testDistinctLiveOrigins(): void
    {
        $urls = array_filter(explode(',', (string) getenv('FINANCE_ROUTING_URLS')));
        $absent = array_filter(explode(',', (string) getenv('FINANCE_UNREGISTERED_URLS')));
        $all = [...$urls, ...$absent];
        self::assertGreaterThanOrEqual(2, \count(array_unique($all)), 'Pass at least two distinct registered or unregistered URLs.');
        self::assertSame(\count($all), \count(array_unique($all)), 'Each origin must have one expected state.');
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        foreach ($all as $url) {
            self::assertMatchesRegularExpression('~^http://[a-z0-9.-]+\.finance\.localhost/?$~D', $url);
        }

        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless(true)->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                foreach ($urls as $index => $url) {
                    $response = $page->goto($url);
                    self::assertNotNull($response);
                    self::assertSame(200, $response->status());
                    self::assertSame('Make sense of your money.', $page->getByRole('heading', ['level' => 1])->textContent());
                    self::assertSame('rgb(17, 17, 18)', $page->evaluate('() => getComputedStyle(document.body).backgroundColor'));
                    self::assertTrue($page->getByRole('link', ['name' => 'Log in', 'exact' => true])->isVisible());
                    self::assertSame(0, $page->locator('.app-sidebar, .composer-preview, textarea')->count());
                    self::assertStringContainsString('demo@test.com', $page->locator('.demo-credentials')->textContent());
                    $page->evaluate('value => localStorage.setItem("routing-isolation", value)', (string) $index);
                }
                foreach ($urls as $index => $url) {
                    $page->goto($url);
                    self::assertSame((string) $index, $page->evaluate('() => localStorage.getItem("routing-isolation")'));
                    $page->evaluate('() => localStorage.removeItem("routing-isolation")');
                }
                foreach ($absent as $url) {
                    $response = $page->goto($url);
                    self::assertNotNull($response);
                    self::assertSame(404, $response->status(), 'Unregistered routes must not reach an application.');
                    self::assertSame('No development route registered', $page->locator('body')->textContent());
                }
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
