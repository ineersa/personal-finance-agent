<?php

declare(strict_types=1);

namespace App\Import;

enum StatementFormat: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';

    /** @return list<string> */
    public function mediaTypes(): array
    {
        return match ($this) {
            self::Csv => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            self::Pdf => ['application/pdf'],
            self::Xlsx => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        };
    }

    public static function isDocument(string $mediaType): bool
    {
        return \in_array($mediaType, [...self::Pdf->mediaTypes(), ...self::Xlsx->mediaTypes()], true);
    }

    /** @return array<string, list<string>> */
    public static function extensions(): array
    {
        $extensions = [];
        foreach (self::cases() as $format) {
            $extensions[$format->value] = $format->mediaTypes();
        }

        return $extensions;
    }
}
