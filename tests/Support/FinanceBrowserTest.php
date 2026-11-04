<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Playwright\Symfony\Test\PlaywrightTestCase;

#[SkipDatabaseRollback]
abstract class FinanceBrowserTest extends PlaywrightTestCase
{
    use RefreshTenantDatabasesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }
}
