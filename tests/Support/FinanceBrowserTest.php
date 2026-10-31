<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseTrait;
use Playwright\Symfony\Test\PlaywrightTestCase;

#[SkipDatabaseRollback]
abstract class FinanceBrowserTest extends PlaywrightTestCase
{
    use RefreshDatabaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }
}
