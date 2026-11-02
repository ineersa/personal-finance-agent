<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    public function testChatPreview(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        self::assertResponseIsSuccessful();
        self::assertTrue($page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->isVisible());
        self::assertTrue($page->getByRole('textbox', ['name' => 'Your financial question'])->isDisabled());
        self::assertTrue($page->getByRole('button', ['name' => 'Send message', 'exact' => true])->isDisabled());
    }
}
