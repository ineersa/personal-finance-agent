<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Enum\Direction;
use App\Enum\TransactionMeaning;
use App\Finance\Entity\Category;
use App\Tests\Support\FinanceNetworkBrowserTest;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\Component\Yaml\Yaml;

final class ChartTest extends FinanceNetworkBrowserTest
{
    public function testFinancialChartAndReload(): void
    {
        $this->login();
        $page = $this->page;
        $url = $this->url;
        $page->goto($url.'/home/chat');
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isVisible(), 'Prepare the replay grant or connect ChatGPT when explicitly recording.');
        $turnIndex = $page->locator('.chat-turn')->count();
        self::assertSame(0, $turnIndex, 'Create this journey\'s conversation only from the empty new-chat route.');
        $question = 'Show my September 2026 spending by category for Maple Harbour Rewards Visa · 4826 in CAD as a horizontal bar chart, excluding card repayments. Briefly summarize the biggest expenses.';
        $page->getByLabel('Your message', ['exact' => true])->fill($question);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->waitForURL('**/home/chat?conversation=*');
        $this->createdArtifactUrl = $page->url();
        $turn = $page->locator('.chat-turn')->nth($turnIndex);
        $turn->getByText($question, ['exact' => true])->waitFor();
        $turn->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        $tool = $turn->locator('.chat-tool')->filter(['hasText' => 'unovis_generate_bar_chart'])->first();
        self::assertSame(ExecutionStatus::Completed->value, $tool->getAttribute('data-tool-status'));
        $tool->locator('summary')->click();
        $arguments = json_decode($tool->locator('.chat-tool-arguments')->textContent(), true, flags: \JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/demo-statements/expected.json'), true, flags: \JSON_THROW_ON_ERROR);
        $statement = array_find($manifest['statements'], static fn (array $entry): bool => 'credit/2026-09.csv' === $entry['file']);
        self::assertNotNull($statement);
        $categories = Yaml::parseFile(\dirname(__DIR__, 2).'/fixtures/category.yaml')[Category::class];
        $expected = [];
        foreach ($statement['rows'] as $row) {
            if (strtolower(Direction::Debit->value) === $row['direction'] && TransactionMeaning::Expense->value === $row['meaning']) {
                $name = $categories[$row['category']]['__construct'][0];
                $expected[$name] = ($expected[$name] ?? 0) + (int) round((float) $row['amount'] * 100);
            }
        }
        $actual = [];
        foreach ($arguments['data'] as $row) {
            $actual[$row[$arguments['x']]] = (int) round((float) $row[$arguments['y']] * 100);
        }
        ksort($expected);
        ksort($actual);
        self::assertCount(\count($expected), $arguments['data']);
        self::assertSame($expected, $actual, 'Chart categories and CAD totals must match the canonical purchases, without repayments.');
        $firstCategory = $arguments['data'][0][$arguments['x']];
        $firstAmount = (new \NumberFormatter('en_US', \NumberFormatter::DECIMAL))->format($expected[$firstCategory] / 100);
        self::assertNotFalse($firstAmount);
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
        self::assertSame($firstAmount, $tooltip->textContent());
        // Wrapped SVG labels can omit whitespace between adjacent tspans.
        $ticks = $frame->locator('svg text');
        $labels = [];
        $tickCount = $ticks->count();
        for ($index = 0; $index < $tickCount; ++$index) {
            $labels[] = preg_replace('/\s/u', '', $ticks->nth($index)->textContent());
        }
        self::assertContains(preg_replace('/\s/u', '', $firstCategory), $labels);
        $page->reload();
        $document = $page->locator('.chat-turn')->nth($turnIndex)->locator('.chat-chart iframe');
        $document->waitFor();
        self::assertSame($src, $document->getAttribute('src'));
        $frame->locator('svg path[class$="-bar"]')->first()->hover();
        $tooltip->waitFor();
        self::assertSame($firstAmount, $tooltip->textContent());
        $this->deleteCreatedChat();
    }
}
