<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use Playwright\Symfony\Test\PlaywrightTestCase;

#[Group('profiler')]
final class HomepageProfilerTest extends PlaywrightTestCase
{
    public function testHomepageProfile(): void
    {
        $client = self::getPlaywrightClient();
        $client->enableProfiler();
        $this->visit('/');
        $this->assertPageContains('Make sense of your money.');

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        self::assertSame(200, $profile->getStatusCode());
        self::assertTrue($profile->hasCollector('request'));
    }
}
