<?php

declare(strict_types=1);

namespace App\Tests;

use Playwright\Symfony\Client\BrowserRegistry;
use Playwright\Symfony\Test\PlaywrightTestCase;

abstract class HeadedBrowserTestCase extends PlaywrightTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (BrowserRegistry::fromEnvironment()->isHeadless()) {
            throw new \RuntimeException('Headed browser configuration is required before creating a browser. PLAYWRIGHT_HEADLESS must be the literal string false.');
        }
    }
}
