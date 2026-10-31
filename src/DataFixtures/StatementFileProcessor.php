<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Statement;
use Fidry\AliceDataFixtures\ProcessorInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

// Development and browser tests use the same synthetic bytes and native Alice lifecycle.
final readonly class StatementFileProcessor implements ProcessorInterface
{
    public function __construct(#[Target('statements.storage')] private FilesystemOperator $storage)
    {
    }

    public function preProcess(string $id, object $object): void
    {
        if (!$object instanceof Statement) {
            return;
        }
        $content = "date,description,amount,currency\n2026-10-01,Synthetic fixture {$id},12.34,EUR\n";
        $object->storageKey = substr(hash('sha256', 'synthetic-statement-'.$id), 0, 32);
        $object->byteSize = \strlen($content);
        $object->sha256 = hash('sha256', $content);
        $object->mediaType = 'text/csv';
        $this->storage->write($object->storageKey, $content);
    }

    public function postProcess(string $id, object $object): void
    {
    }
}
