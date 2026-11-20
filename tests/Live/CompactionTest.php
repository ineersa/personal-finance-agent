<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\FinanceNetworkBrowserTest;

final class CompactionTest extends FinanceNetworkBrowserTest
{
    public function testContinueFinancialAnalysisAfterHandoffAndReload(): void
    {
        $this->login();
        $page = $this->page;
        $page->goto($this->url.'/home/chat');
        $question = 'For this review use only CAD purchases on my Maple Harbour Rewards Visa during July through September 2026, excluding transfers and keeping refunds separate. My decision is to review groceries separately from dining, never combine them. Query the individual purchase and refund records for these three months with dates, categories and amounts, then compare monthly category totals and explain the largest changes without a chart. Keep the answer concise; do not list every transaction.';
        $page->getByLabel('Your message', ['exact' => true])->fill($question);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->waitForURL('**/home/chat?conversation=*');
        $this->createdArtifactUrl = $page->url();
        $page->locator('.chat-turn')->first()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->waitFor();
        $answer = $page->locator('.chat-turn')->first()->locator('.chat-answer')->last()->textContent();
        self::assertNotSame('', trim($answer));
        $page->reload();
        $page->locator('.chat-turn')->first()->getByText($question, ['exact' => true])->waitFor();
        self::assertSame($answer, $page->locator('.chat-turn')->first()->locator('.chat-answer')->last()->textContent());

        $followUp = 'Continue my earlier review with the same account, period, currency and category decision. State that decision explicitly, then query the records again to compare August with September purchases for those two food categories. Keep refunds separate and exclude transfers. No chart.';
        $page->getByLabel('Your message', ['exact' => true])->fill($followUp);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->getByText($followUp, ['exact' => true])->waitFor();
        $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->waitFor();
        $page->reload();
        $page->locator('.chat-turn')->first()->getByText($question, ['exact' => true])->waitFor();
        $page->getByText($followUp, ['exact' => true])->waitFor();
        self::assertSame(2, $page->locator('.chat-turn')->count());
        self::assertSame($answer, $page->locator('.chat-turn')->first()->locator('.chat-answer')->last()->textContent());
        self::assertNotSame('', trim($page->locator('.chat-turn')->last()->locator('.chat-answer')->last()->textContent()));
        $continuedAnswer = strtolower($page->locator('.chat-turn')->last()->locator('.chat-answer')->last()->textContent());
        self::assertStringContainsString('grocer', $continuedAnswer);
        self::assertStringContainsString('dining', $continuedAnswer);
        self::assertSame(0, $page->locator('.chat-error')->count());
        $this->deleteCreatedChat();
    }
}
