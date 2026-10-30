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
        $urls = explode(',', (string) getenv('FINANCE_ROUTING_URLS'));
        self::assertGreaterThanOrEqual(2, \count(array_unique($urls)), 'Pass at least two live stack URLs.');
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        foreach ($urls as $url) {
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
                    self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isDisabled());
                    $page->evaluate('value => localStorage.setItem("routing-isolation", value)', (string) $index);
                }
                foreach ($urls as $index => $url) {
                    $page->goto($url);
                    self::assertSame((string) $index, $page->evaluate('() => localStorage.getItem("routing-isolation")'));
                    $page->evaluate('() => localStorage.removeItem("routing-isolation")');
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
