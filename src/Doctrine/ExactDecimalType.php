<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class ExactDecimalType extends Type
{
    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || 1 !== preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/D', $value)) {
            throw new \InvalidArgumentException('A plain decimal string is required.');
        }

        return $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $this->convertToDatabaseValue($value, $platform);
    }
}
