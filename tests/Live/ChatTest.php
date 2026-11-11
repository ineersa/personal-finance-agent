<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Chat\ChatModel;
use App\Finance\Sql\TenantSqlTool;
use App\Tests\Support\FinanceNetworkBrowserTest;
use App\Twig\Components\ChatNavigation;
use Symfony\AI\Agent\Execution\ExecutionStatus;

final class ChatTest extends FinanceNetworkBrowserTest
{
    public function testWhitespacePreservesNativeDeleteConfirmation(): void
    {
        $this->login();
        $page = $this->page;
        $url = $this->url;
        $page->goto($url.'/home/chat');
        $href = $page->locator('.chat-navigation-row a.ea-sidebar-item-link')->first()->getAttribute('href');
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
    }

    public function testStreamedReplyAndFollowUp(): void
    {
        $this->login();
        $page = $this->page;
        $url = $this->url;
        $page->goto($url.'/home/chat');
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isVisible(), 'The owner must connect ChatGPT in this isolated stack before the live test.');
        $menu = $page->locator('.chat-model-menu summary');
        $menu->click();
        self::assertSame(ChatModel::Gpt6Luna->value, $page->getByLabel('Model', ['exact' => true])->inputValue());
        self::assertSame('Low', $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->getAttribute('aria-valuetext'));
        $model = $page->getByLabel('Model', ['exact' => true]);
        $effort = $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true]);
        self::assertSame(4, $model->locator('option')->count());
        $model->selectOption(ChatModel::Gpt61Sol->value);
        $effort->press('End');
        $effort->press('ArrowLeft');
        self::assertSame('4', $effort->getAttribute('max'));
        self::assertSame('Extra High', $effort->getAttribute('aria-valuetext'));
        $model->selectOption(ChatModel::Gpt6Luna->value);
        $effort->press('Home');
        $effort->press('ArrowRight');
        self::assertSame('Low', $effort->getAttribute('aria-valuetext'));
        $menu->click();
        $initialTurns = $page->locator('.chat-turn')->count();
        self::assertSame(0, $initialTurns, 'Create this journey\'s conversation only from the empty new-chat route.');
        $code = 'cobalt-lantern';
        $question = 'Use finance_query to find the description of the approved CAD credit dated 2024-11-01 with amount 2860.00 in my records. Quote that description in your answer, then remember the code word '.$code.'.';
        $page->getByLabel('Your message', ['exact' => true])->fill($question);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->waitForURL('**/home/chat?conversation=*');
        $this->createdArtifactUrl = $page->url();
        $page->locator('.chat-turn')->nth($initialTurns)->getByText($question, ['exact' => true])->waitFor();
        $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        self::assertStringContainsString('PAYROLL LANTERN LABS', $page->locator('.chat-answer')->last()->textContent());
        $tool = $page->locator('.chat-turn')->nth($initialTurns)->locator('.chat-tool')->filter(['hasText' => TenantSqlTool::QUERY_NAME])->first();
        self::assertSame(ExecutionStatus::Completed->value, $tool->getAttribute('data-tool-status'));
        self::assertNull($tool->getAttribute('open'));
        $callId = $tool->getAttribute('data-tool-call-id');
        self::assertNotEmpty($callId);
        $tool->locator('summary')->click();
        self::assertStringContainsString('SELECT', strtoupper($tool->locator('.chat-tool-arguments')->textContent()));
        self::assertStringContainsString('PAYROLL LANTERN LABS', $tool->locator('.chat-tool-result')->textContent());

        $followUp = 'What code word did I just ask you to remember? Reply with that code only.';
        $menu->click();
        $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->press('ArrowRight');
        $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->press('ArrowRight');
        self::assertSame(ChatModel::Gpt6Luna->value, $page->getByLabel('Model', ['exact' => true])->inputValue());
        self::assertSame('High', $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->getAttribute('aria-valuetext'));
        $menu->click();
        $page->getByLabel('Your message', ['exact' => true])->fill($followUp);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->locator('.chat-turn')->nth($initialTurns + 1)->getByText($followUp, ['exact' => true])->waitFor();
        $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
        self::assertNotNull($tool->getAttribute('open'));

        $modelFollowUp = 'Repeat the code word from the start of this conversation. Reply with that code only.';
        $menu->click();
        $page->getByLabel('Model', ['exact' => true])->selectOption(ChatModel::Gpt6Sol->value);
        self::assertSame(ChatModel::Gpt6Sol->value, $page->getByLabel('Model', ['exact' => true])->inputValue());
        self::assertSame('High', $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->getAttribute('aria-valuetext'));
        $menu->click();
        $page->getByLabel('Your message', ['exact' => true])->fill($modelFollowUp);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->locator('.chat-turn')->nth($initialTurns + 2)->getByText($modelFollowUp, ['exact' => true])->waitFor();
        $page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
        self::assertNotNull($tool->getAttribute('open'));
        $page->reload();
        $menu->click();
        self::assertSame(ChatModel::Gpt6Sol->value, $page->getByLabel('Model', ['exact' => true])->inputValue());
        self::assertSame('High', $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->getAttribute('aria-valuetext'));
        $menu->click();
        self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
        self::assertSame($callId, $tool->getAttribute('data-tool-call-id'));
        $tool->locator('summary')->click();
        self::assertStringContainsString('PAYROLL LANTERN LABS', $tool->locator('.chat-tool-result')->textContent());
        $this->assertCreatedConversationHistory($code);
        $ownId = $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id');
        $navigationCount = $page->locator('.chat-navigation-row')->count();
        $this->deleteCreatedChat();
        self::assertSame(0, $page->locator('.chat-navigation-row[data-conversation-id="'.$ownId.'"]')->count());
        self::assertSame($navigationCount - 1, $page->locator('.chat-navigation-row')->count());
    }

    private function assertCreatedConversationHistory(string $code): void
    {
        $page = $this->page;
        $ownId = $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id');
        self::assertNotEmpty($ownId);
        $ownRow = $page->locator('.chat-navigation-row[data-conversation-id="'.$ownId.'"]');
        $canonicalRows = $page->locator('.chat-favourites-group .chat-navigation-row');
        $canonicalIds = [];
        for ($index = 0; $index < $canonicalRows->count(); ++$index) {
            $canonicalIds[] = $canonicalRows->nth($index)->getAttribute('data-conversation-id');
        }
        self::assertNotEmpty($canonicalIds, 'Canonical favourites provide read-only pagination cursors.');
        self::assertSame(0, $page->locator('.chat-favourites-group')->getByRole('link', ['name' => 'Show more', 'exact' => true])->count(), 'Discover the complete canonical favourites page before selecting a cursor.');
        $otherHref = $canonicalRows->first()->locator('a.ea-sidebar-item-link')->getAttribute('href');
        self::assertNotNull($otherHref);

        $this->visit($page->getByRole('link', ['name' => 'New chat', 'exact' => true])->getAttribute('href'));
        self::assertSame(0, $page->locator('.chat-navigation-row.is-active')->count());
        $this->visit($ownRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $ownRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $ownRow->getByRole('button', ['name' => 'Favourite', 'exact' => true])->click();
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();
        $ownRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $ownRow->getByRole('button', ['name' => 'Remove favourite', 'exact' => true])->click();
        $page->locator('.chat-chats-group .chat-navigation-row.is-active')->waitFor();
        $ownRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $ownRow->getByRole('button', ['name' => 'Favourite', 'exact' => true])->click();
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();

        // Repository ordering is UUID descending with an exclusive cursor. The newest
        // canonical favourite excludes itself and the newer own chat; selection appends own.
        $before = $canonicalIds[0];
        $this->visit('/home/chat?conversation='.$ownId.'&'.ChatNavigation::FAVOURITES_BEFORE.'='.$before);
        $olderRows = $page->locator('.chat-favourites-group .chat-navigation-row');
        $expectedIds = [...\array_slice($canonicalIds, 1, ChatNavigation::VISIBLE), $ownId];
        self::assertSame(\count($expectedIds), $olderRows->count());
        foreach ($expectedIds as $index => $expectedId) {
            self::assertSame($expectedId, $olderRows->nth($index)->getAttribute('data-conversation-id'));
        }
        self::assertSame($ownId, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        $this->visit($page->locator('.chat-favourites-group')->getByRole('link', ['name' => 'Show latest', 'exact' => true])->getAttribute('href'));
        $this->visit($otherHref);
        $this->visit($ownRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();
        $page->reload();
        self::assertSame($ownId, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        self::assertStringContainsString($code, $page->locator('.chat-answer')->last()->textContent());
        self::assertTrue($page->locator('.chat-favourites-group .chat-navigation-row.is-active')->isVisible());

        $this->visit($otherHref);
        $page->locator('.chat-navigation-row[data-conversation-id="'.$canonicalIds[0].'"].is-active')->waitFor();
        $page->getByRole('button', ['name' => 'Search chats', 'exact' => true])->click();
        $search = $page->getByRole('searchbox', ['name' => 'Search chats', 'exact' => true]);
        $search->fill('"'.$code.'"');
        $search->press('Enter');
        $result = $page->locator('.chat-search-results li a[href*="conversation='.$ownId.'"]');
        $result->waitFor();
        self::assertTrue($result->locator('span')->filter(['hasText' => $code])->isVisible());
        $this->visit($result->getAttribute('href'));
        $page->locator('.chat-favourites-group .chat-navigation-row.is-active')->waitFor();
        self::assertSame($ownId, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
    }
}
