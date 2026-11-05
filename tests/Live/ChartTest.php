<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Chat\ChatActivity;
use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;

final class ChartTest extends TestCase
{
    public function testFinancialChartAndReload(): void
    {
        $url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $client = PlaywrightFactory::create();
        $browser = null;
        try {
            $browser = $client->chromium()->withHeadless('false' !== getenv('PLAYWRIGHT_HEADLESS'))->withArgs(['--host-resolver-rules=MAP *.finance.localhost '.$address])->launch();
            $context = $browser->newContext();
            try {
                $page = $context->newPage();
                $page->goto($url.'/login');
                $page->getByLabel('Email', ['exact' => true])->fill('demo@test.com');
                $page->getByLabel('Password', ['exact' => true])->fill('demo');
                $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
                $page->waitForURL('**/home/**');
                $page->goto($url.'/home/chat');
                self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isVisible(), 'Connect ChatGPT in this isolated stack before the real chart test; existing replay has no chart interaction.');
                $turnIndex = $page->locator('.chat-turn')->count();
                $question = 'Use finance_query to calculate SUM(amount) for my approved CAD credit dated 2024-11-01 with amount 2860.00. Generate exactly one interactive bar chart using unovis_generate_bar_chart with one data item: category Synthetic payroll, value equal to that SQL total. Use x category, y value, width 600, height 400 and outputType html. Do not supply outputPath. Give a brief text explanation too.';
                $page->getByLabel('Your message', ['exact' => true])->fill($question);
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $turn = $page->locator('.chat-turn')->nth($turnIndex);
                $turn->getByText($question, ['exact' => true])->waitFor();
                $turn->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
                $tool = $turn->locator('.chat-tool')->filter(['hasText' => 'unovis_generate_bar_chart'])->first();
                self::assertSame(ChatActivity::COMPLETED, $tool->getAttribute('data-tool-status'));
                $tool->locator('summary')->click();
                $arguments = json_decode($tool->locator('.chat-tool-arguments')->textContent(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('Synthetic payroll', $arguments['data'][0]['category']);
                self::assertSame(2860.0, (float) $arguments['data'][0]['value']);
                $document = $turn->locator('.chat-chart iframe');
                $document->waitFor();
                $src = $document->getAttribute('src');
                self::assertStringStartsWith('/home/chat/', $src);
                self::assertStringEndsWith('.html', $src);
                self::assertSame('allow-scripts', $document->getAttribute('sandbox'));
                $frame = $page->frameLocator('iframe[src="'.$src.'"]');
                $bar = $frame->locator('svg path[class$="-bar"]')->first();
                $bar->waitFor();
                $bar->hover();
                $tooltip = $frame->locator('.uv-tt-val');
                $tooltip->waitFor();
                self::assertSame('2,860', $tooltip->textContent());
                $frame->locator('svg text')->filter(['hasText' => 'Synthetic payroll'])->first()->waitFor();
                $page->reload();
                $document = $page->locator('.chat-turn')->nth($turnIndex)->locator('.chat-chart iframe');
                $document->waitFor();
                self::assertSame($src, $document->getAttribute('src'));
                $frame->locator('svg path[class$="-bar"]')->first()->hover();
                $tooltip->waitFor();
                self::assertSame('2,860', $tooltip->textContent());
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
