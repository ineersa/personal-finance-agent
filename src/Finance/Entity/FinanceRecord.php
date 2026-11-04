<?php

declare(strict_types=1);

namespace App\Finance\Entity;

abstract class FinanceRecord
{
    abstract public function getId(): ?int;
}
