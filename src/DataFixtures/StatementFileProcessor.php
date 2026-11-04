<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Finance\Entity\Statement;
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
        // Fixture IDs are trusted definitions, never request paths or uploaded filenames.
        if (1 === preg_match('/\Ahistory-(chequing|credit|usd)-(20[0-9]{2}-[0-9]{2})\.csv\z/D', $object->originalFilename, $matches)) {
            $filename = $matches[1].'/'.$matches[2].'.csv';
        } else {
            throw new \LogicException('No canonical bytes for statement fixture '.$id);
        }
        $content = file_get_contents(\dirname(__DIR__, 2).'/resources/demo-statements/'.$filename);
        if (false === $content) {
            throw new \RuntimeException('Cannot read canonical statement fixture bytes.');
        }
        $object->byteSize = \strlen($content);
        $object->sha256 = hash('sha256', $content);
        $object->mediaType = 'text/csv';
        $this->storage->write($object->storedFilename, $content);
    }

    public function postProcess(string $id, object $object): void
    {
    }
}
