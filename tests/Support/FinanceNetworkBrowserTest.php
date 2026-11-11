<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextBuilder;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;

/** Real HTTP browser journeys; the runner owns the isolated stack and fixtures. */
abstract class FinanceNetworkBrowserTest extends TestCase
{
    protected PageInterface $page;
    protected ?string $createdArtifactUrl = null;
    protected string $url;
    private ?PlaywrightClient $client = null;
    private ?BrowserInterface $browser = null;
    private ?BrowserContextInterface $context = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = (string) getenv('FINANCE_IMPORT_URL');
        self::assertMatchesRegularExpression('~^http://[a-z0-9-]+\.browser\.finance\.localhost$~D', $this->url);
        $address = getenv('FINANCE_PROXY_IP');
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
        $headed = 'false' === getenv('PLAYWRIGHT_HEADLESS');
        $this->client = PlaywrightFactory::create();
        try {
            $builder = $this->client->chromium()->withHeadless(!$headed)->withArgs([
                '--host-resolver-rules=MAP *.finance.localhost '.$address,
                '--window-size=2560,1440',
            ]);
            if ($headed) {
                $builder->withSlowMo(1000);
            }
            $this->browser = $builder->launch();
            $this->context = $this->browser->newContext($headed ? ['viewport' => null] : BrowserContextBuilder::create()->withViewport(1920, 1080)->toArray());
            $this->page = $this->context->newPage();
        } catch (\Throwable $error) {
            $this->closeBrowser();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if (null !== $this->createdArtifactUrl) {
                // Never race a worker/import with failure cleanup or hide the original failure.
                fwrite(\STDERR, 'Journey artifact retained for inspection (may still be active): '.$this->createdArtifactUrl."\n");
            }
            $this->closeBrowser();
        } finally {
            parent::tearDown();
        }
    }

    protected function visit(string $path): PageInterface
    {
        $this->page->goto($this->url.$path);

        return $this->page;
    }

    protected function login(string $email = 'demo@test.com', string $password = 'demo'): void
    {
        $page = $this->visit('/login');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click();
        $page->waitForURL('**/home/**');
    }

    protected function logout(): void
    {
        $this->page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click();
        $this->page->waitForURL('**/login');
    }

    protected function assertPageContains(string $text): void
    {
        self::assertStringContainsString($text, $this->page->locator('body')->textContent());
    }

    protected function deleteRecord(string $detailUrl): void
    {
        $this->page->goto($detailUrl);
        $this->page->locator('.action-delete')->click();
        $this->page->getByRole('button', ['name' => 'Confirm', 'exact' => true])->click();
        $this->page->locator('body.ea-index')->waitFor();
    }

    /** Call only for a conversation created by this journey from the empty /home/chat draft. */
    protected function deleteCreatedChat(): void
    {
        if (null === $this->createdArtifactUrl || $this->page->url() !== $this->createdArtifactUrl) {
            throw new \LogicException('Cleanup requires this journey\'s selected new conversation.');
        }
        parse_str((string) parse_url($this->createdArtifactUrl, \PHP_URL_QUERY), $query);
        $row = $this->page->locator('.chat-navigation-row.is-active');
        self::assertNotEmpty($query['conversation'] ?? null);
        self::assertSame($query['conversation'], $row->getAttribute('data-conversation-id'));
        self::assertSame(0, $this->page->locator('[data-chat-response-active]')->count(), 'Do not delete an active generation.');
        self::assertTrue($this->page->locator('.chat-turn')->last()->locator('.chat-answer[data-chat-saved]')->isVisible());
        $row->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $row->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
        $this->page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
        $this->page->locator('#modal-action-confirmation-button')->click();
        $this->page->waitForURL('**/home/chat');
        $this->createdArtifactUrl = null;
    }

    private function closeBrowser(): void
    {
        try {
            $this->context?->close();
        } finally {
            try {
                $this->browser?->close();
            } finally {
                $this->client?->close();
                $this->context = null;
                $this->browser = null;
                $this->client = null;
            }
        }
    }
}
