<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceNetworkBrowserTest;
use Symfony\Component\HttpFoundation\Response;

/** Migration inventory and proof boundaries: TESTING.md. */
final class ChatModelTest extends FinanceNetworkBrowserTest
{
    public const string FAILED_CHART_PROMPT = 'Use my approved September 2026 Maple Harbour Rewards Visa · 4826 purchases in CAD, excluding card repayments. Query spending totals by category. Then call unovis_generate_bar_chart exactly once: provide the query rows with fields category and amount, set x to category and y to missing_amount (do not include missing_amount in any data row). This is an intentional chart validation request. Do not repair the field, retry chart generation, or generate another chart. Briefly report the tool error.';

    private const string PROVIDER_REPLAY_MODE = '0';

    public function testMissingModelReturnsFormError(): void
    {
        $this->login();
        $page = $this->visit('/home/chat');
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        $page->evaluate('() => { const form = document.querySelector("form.chat-composer"); form.noValidate = true; form.elements.namedItem("model").removeAttribute("name"); }');
        $response = $page->waitForResponse('**/home/chat/message', ['action' => 'document.querySelector("form.chat-composer button[type=submit]").click()']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->status());
        self::assertTrue($page->getByLabel('Your message', ['exact' => true])->isVisible());
    }

    public function testMissingEffortReturnsFormError(): void
    {
        $this->login();
        $page = $this->visit('/home/chat');
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        $page->evaluate('() => { const form = document.querySelector("form.chat-composer"); form.noValidate = true; form.elements.namedItem("effort").removeAttribute("name"); }');
        $response = $page->waitForResponse('**/home/chat/message', ['action' => 'document.querySelector("form.chat-composer button[type=submit]").click()']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->status());
        self::assertTrue($page->getByLabel('Your message', ['exact' => true])->isVisible());
    }

    public function testFocusPreservesActiveLiveCardAndCatchesUpWhenDisconnected(): void
    {
        $this->login();
        $page = $this->visit('/home/chat');
        $page->getByLabel('Your message', ['exact' => true])->fill('Use finance_query to find the description of the approved CAD credit dated 2024-11-01 with amount 2860.00 in my records. Quote that description in your answer, then remember the code word cobalt-lantern.');
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->waitForURL('**/home/chat?conversation=*');
        $this->createdArtifactUrl = $page->url();
        $page->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        self::assertStringContainsString('PAYROLL LANTERN LABS', $page->locator('.chat-answer')->last()->textContent());
        $page->waitForFunction('() => document.querySelector("#import-live-subscription turbo-mercure-stream-source")?.es?.readyState === EventSource.OPEN');
        $menu = $page->locator('.chat-model-menu summary');
        $menu->click();
        $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->press('ArrowRight');
        $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true])->press('ArrowRight');
        $menu->click();
        $page->evaluate(<<<'JS'
            () => {
                window.snapshotObserved = false;
                let genuineProgressArrived = false;
                let fetches = 0;
                const originalFetch = window.fetch;
                window.fetch = function (...args) {
                    ++fetches;
                    return originalFetch.apply(this, args);
                };
                document.addEventListener('turbo:before-stream-render', event => {
                    const stream = event.detail.newStream;
                    if (stream.hasAttribute('data-import-snapshot')) {
                        window.snapshotObserved = true;
                    } else if (stream.templateElement.content.querySelector('[data-chat-response-active]')) {
                        genuineProgressArrived = true;
                    }
                });
                const observer = new MutationObserver(() => {
                    const root = document.querySelector('[data-chat-execution]');
                    const active = root?.querySelector('[data-chat-response-active]');
                    const source = document.querySelector('#import-live-subscription turbo-mercure-stream-source');
                    if (!genuineProgressArrived || !active || source?.es?.readyState !== EventSource.OPEN) return;
                    observer.disconnect();
                    const before = fetches;
                    // Exercise the real declarative UI action during genuine streamed progress,
                    // not OS focus timing. No transport state, response or pacing is fabricated.
                    window.dispatchEvent(new FocusEvent('focus'));
                    window.activeFocusResult = {
                        preserved: root === document.querySelector('[data-chat-execution]') && active.isConnected,
                        fetches: fetches - before,
                    };
                    // Reconnect the actual native custom element, not a fabricated EventSource.
                    // Its new connection is genuinely CONNECTING until the network opens it.
                    window.snapshotObserved = false;
                    const replacement = document.createElement(source.localName);
                    for (const {name, value} of source.attributes) {
                        if (name !== 'connected') replacement.setAttribute(name, value);
                    }
                    source.replaceWith(replacement);
                    const retrying = replacement.es.readyState === EventSource.CONNECTING;
                    const beforeCatchUp = fetches;
                    window.dispatchEvent(new FocusEvent('focus'));
                    window.disconnectedFocusResult = {
                        retrying,
                        fetches: fetches - beforeCatchUp,
                        quiet: document.querySelector('.chat-live-notice').hidden,
                    };
                });
                observer.observe(document, {subtree: true, childList: true});
            }
            JS);
        $page->getByLabel('Your message', ['exact' => true])->fill('What code word did I just ask you to remember? Reply with that code only.');
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->locator('.chat-turn')->nth(1)->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        self::assertStringContainsString('cobalt-lantern', $page->locator('.chat-answer')->last()->textContent());
        self::assertSame(['preserved' => true, 'fetches' => 0], $page->evaluate('() => window.activeFocusResult'));
        self::assertSame(['retrying' => true, 'fetches' => 1, 'quiet' => true], $page->evaluate('() => window.disconnectedFocusResult'));
        $page->waitForFunction('() => document.querySelector("#import-live-subscription turbo-mercure-stream-source")?.es?.readyState === EventSource.OPEN');
        // Prove native reconnect and canonical catch-up, not delivery of remaining
        // tokens from a short response that may finish before reconnect opens.
        $page->evaluate('() => { window.snapshotObserved = false; window.dispatchEvent(new FocusEvent("focus")); }');
        $page->waitForFunction('() => window.snapshotObserved === true');
        self::assertStringContainsString('cobalt-lantern', $page->locator('.chat-answer')->last()->textContent());
        $this->deleteCreatedChat();
    }

    public function testFailedChartToolShowsErrorInStreamAndAfterReload(): void
    {
        $this->login();
        $page = $this->visit('/home/chat');
        self::assertSame(0, $page->locator('.chat-turn')->count());
        // Observe actual incoming Turbo streams before rendering; never inject one.
        $page->addInitScript(<<<'JS'
            window.chartFailureStreamSeen = false;
            document.addEventListener('turbo:before-stream-render', event => {
                const stream = event.detail.newStream;
                if (stream.hasAttribute('data-import-snapshot')) return;
                const content = stream.templateElement.content;
                if (content.querySelector('.chat-tool-error')?.textContent.includes('unovis_generate_bar_chart')) {
                    window.chartFailureStreamSeen = true;
                }
            });
            JS);
        $page->getByLabel('Your message', ['exact' => true])->fill(self::FAILED_CHART_PROMPT);
        $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
        $page->waitForURL('**/home/chat?conversation=*');
        $this->createdArtifactUrl = $page->url();
        $page->locator('.chat-answer[data-chat-saved]')->waitFor(['timeout' => 120000]);
        $tool = $page->locator('.chat-tool')->filter(['hasText' => 'unovis_generate_bar_chart']);
        self::assertSame(1, $tool->count(), 'The recorded model must make exactly one chart call, without retry.');
        self::assertStringContainsString('Tool call failed', $tool->locator('summary')->textContent());
        $tool->locator('summary')->click();
        self::assertStringContainsString('missing_amount', $tool->locator('.chat-tool-arguments')->textContent());
        self::assertNotEmpty($tool->locator('.chat-tool-result')->textContent());
        self::assertSame(0, $page->locator('.chat-chart')->count());
        $result = $tool->locator('.chat-tool-result')->textContent();
        self::assertTrue($page->evaluate('() => window.chartFailureStreamSeen === true'), 'The real Mercure stream must carry the failed chart tool, not only a snapshot.');
        $page->reload();
        self::assertStringContainsString('Tool call failed', $tool->locator('summary')->textContent());
        $tool->locator('summary')->click();
        self::assertSame($result, $tool->locator('.chat-tool-result')->textContent());
        $this->deleteCreatedChat();
    }

    public function testRetainedChartRemainsVisibleAfterResponseFailure(): void
    {
        if (self::PROVIDER_REPLAY_MODE !== getenv('PROVIDER_CASSETTE_RECORD')) {
            self::fail('This failure scenario requires strict replay, never recording.');
        }
        // This genuine request follows the successful local chart call. Withhold
        // its response bytes so the actual provider boundary fails closed.
        $cassette = \dirname(__DIR__).'/Fixtures/providers/chat/8f95dea91f3d26891d7cfb143e41c1b2f632a023f21c02e9e63e2a3e85f9c792.json';
        $withheld = $cassette.'.withheld';
        self::assertFileExists($cassette);
        self::assertFileDoesNotExist($withheld);
        self::assertTrue(rename($cassette, $withheld));
        try {
            $this->login();
            $page = $this->visit('/home/chat');
            self::assertSame(0, $page->locator('.chat-turn')->count());
            $page->getByLabel('Your message', ['exact' => true])->fill('Show my September 2026 spending by category for Maple Harbour Rewards Visa · 4826 in CAD as a horizontal bar chart, excluding card repayments. Briefly summarize the biggest expenses.');
            $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click();
            $page->waitForURL('**/home/chat?conversation=*');
            $this->createdArtifactUrl = $page->url();
            $page->locator('.chat-progress')->filter(['hasText' => 'Failed'])->waitFor(['timeout' => 120000]);
            $chart = $page->locator('.chat-chart iframe');
            $chart->waitFor();
            $src = $chart->getAttribute('src');
            self::assertNotNull($src);
            $bar = $page->frameLocator('iframe[src="'.$src.'"]')->locator('svg path[class$="-bar"]')->first();
            $bar->waitFor();
            $bar->hover();
            $page->frameLocator('iframe[src="'.$src.'"]')->locator('.uv-tt-val')->waitFor();
            $page->reload();
            self::assertSame($src, $chart->getAttribute('src'));
            $bar->waitFor();
            // A failed generation is terminal but need not contain a saved text answer.
            $row = $page->locator('.chat-navigation-row.is-active');
            self::assertSame(0, $page->locator('[data-chat-response-active]')->count());
            $row->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
            $row->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
            $page->locator('#modal-action-confirmation-button')->click();
            $page->waitForURL('**/home/chat');
            $this->createdArtifactUrl = null;
        } finally {
            if (!rename($withheld, $cassette)) {
                throw new \RuntimeException('Restore the withheld chart cassette before any other replay run.');
            }
        }
    }
}
