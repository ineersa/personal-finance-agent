<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Playwright\Assertions\Expect;

trait SubmitsBrowserForms
{
    /** @param callable(): void $action */
    private function submit(callable $action): void
    {
        $client = self::getPlaywrightClient();
        // The pinned bridge stalls on automatic POST redirects. Use its native explicit path.
        $client->followRedirects(false);
        try {
            $action();
            $response = $this->getLastResponse();
        } finally {
            $client->followRedirects();
        }
        if (null !== $response && $response->isRedirect()) {
            $client->followRedirect();
        }
        Expect::locator($this->getPage()->locator('body'))->toBeVisible();
    }
}
