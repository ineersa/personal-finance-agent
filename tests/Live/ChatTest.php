<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\TestCase;
use Playwright\Dialog\DialogInterface;
use Playwright\PlaywrightFactory;

final class ChatTest extends TestCase
{
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
                self::assertSame(0, $page->locator('.chat-turn')->count(), 'Start with an empty conversation; this test never clears existing owner messages.');
                $code = 'cobalt-lantern';
                $question = 'Use finance_query to find the description of the approved CAD credit dated 2024-11-01 with amount 2860.00 in my records. Quote that description in your answer, then remember the code word '.$code.'.';
                $page->getByLabel('Your message', ['exact' => true])->fill($question);
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $page->getByText($question, ['exact' => true])->waitFor();
                $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
                self::assertStringContainsString('PAYROLL LANTERN LABS', $page->locator('.chat-answer')->last()->textContent());

                $followUp = 'What code word did I just ask you to remember? Reply with that code only.';
                $page->getByLabel('Your message', ['exact' => true])->fill($followUp);
                $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
                $page->getByText($followUp, ['exact' => true])->waitFor();
                $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
                self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
                $page->reload();
                self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
                $page->events()->onDialog(static function (DialogInterface $dialog): void {
                    $dialog->accept();
                });
                $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click();
                $page->getByRole('heading', ['name' => 'What’s on your mind?', 'exact' => true])->waitFor();
                self::assertSame(0, $page->locator('.chat-turn')->count());
                self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isVisible());
            } finally {
                $context->close();
            }
        } finally {
            $browser?->close();
            $client->close();
        }
    }
}
