<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Chat\ChatActivity;
use App\Finance\Sql\TenantSqlTool;
use PHPUnit\Framework\TestCase;
use Playwright\PlaywrightFactory;

final class ChatTest extends TestCase
{
    public function testWhitespacePreservesNativeDeleteConfirmation(): void
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
                $page->goto($url.'/home/chat');
                $href = $page->locator('.chat-favourites-group .chat-navigation-row a.ea-sidebar-item-link')->first()->getAttribute('href');
                self::assertNotNull($href);
                $page->goto($url.$href);
                $input = $page->getByLabel('Your message', ['exact' => true]);
                $input->fill('   ');
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $page->locator('[data-chat-view-target="error"]')->filter(['hasText' => 'Write a message first.'])->waitFor();
                self::assertSame('   ', $input->inputValue());
                $selected = $page->locator('.chat-navigation-row.is-active');
                $selected->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
                $selected->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
                $modal = $page->locator('#modal-action-confirmation');
                $modal->waitFor(['state' => 'visible']);
                $modal->getByRole('button', ['name' => 'Cancel', 'exact' => true])->click();
                $modal->waitFor(['state' => 'hidden']);
                self::assertTrue($selected->isVisible());
                self::assertSame('   ', $input->inputValue());
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }

    public function testStreamedReplyAndFollowUp(): void
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
                $page->goto($url.'/home/chat');
                self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isVisible(), 'The owner must connect ChatGPT in this isolated stack before the live test.');
                $initialTurns = $page->locator('.chat-turn')->count();
                $code = 'cobalt-lantern';
                $question = 'Use finance_query to find the description of the approved CAD credit dated 2024-11-01 with amount 2860.00 in my records. Quote that description in your answer, then remember the code word '.$code.'.';
                $page->getByLabel('Your message', ['exact' => true])->fill($question);
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $page->locator('.chat-turn')->nth($initialTurns)->getByText($question, ['exact' => true])->waitFor();
                $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
                self::assertStringContainsString('PAYROLL LANTERN LABS', $page->locator('.chat-answer')->last()->textContent());
                $tool = $page->locator('.chat-turn')->nth($initialTurns)->locator('.chat-tool')->filter(['hasText' => TenantSqlTool::QUERY_NAME])->first();
                self::assertSame(ChatActivity::COMPLETED, $tool->getAttribute('data-tool-status'));
                self::assertNull($tool->getAttribute('open'));
                $callId = $tool->getAttribute('data-tool-call-id');
                self::assertNotEmpty($callId);
                $tool->locator('summary')->click();
                self::assertStringContainsString('SELECT', strtoupper($tool->locator('.chat-tool-arguments')->textContent()));
                self::assertStringContainsString('PAYROLL LANTERN LABS', $tool->locator('.chat-tool-result')->textContent());

                $followUp = 'What code word did I just ask you to remember? Reply with that code only.';
                $page->getByLabel('Your message', ['exact' => true])->fill($followUp);
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $page->locator('.chat-turn')->nth($initialTurns + 1)->getByText($followUp, ['exact' => true])->waitFor();
                $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
                self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
                self::assertNotNull($tool->getAttribute('open'));
                $page->reload();
                self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
                self::assertSame($callId, $tool->getAttribute('data-tool-call-id'));
                $tool->locator('summary')->click();
                self::assertStringContainsString('PAYROLL LANTERN LABS', $tool->locator('.chat-tool-result')->textContent());
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
