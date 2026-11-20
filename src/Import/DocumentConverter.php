<?php

declare(strict_types=1);

namespace App\Import;

use App\Finance\Entity\Statement;
use App\Storage\StatementStorage;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Uid\Uuid;

/** Worker-only conversion of an already authorized statement; never register as an AI tool. */
final readonly class DocumentConverter
{
    public const string ENDPOINT = 'http://markitdown:3001/mcp';
    public const string TOOL = 'convert_to_markdown';
    public const string STAGING = '/statement-staging';
    public const int MAX_INPUT_BYTES = 10 * 1024 * 1024;
    public const int MAX_OUTPUT_BYTES = 2 * 1024 * 1024;
    public const int MAX_RESPONSE_BYTES = 4 * 1024 * 1024;
    public const int TIMEOUT_SECONDS = 60;
    private const array EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(private StatementStorage $storage)
    {
    }

    /** Caller must resolve the record in the trusted job owner's tenant before invoking this. */
    public function convert(Statement $statement): string
    {
        $extension = self::EXTENSIONS[$statement->mediaType] ?? null;
        if (null === $extension || $statement->byteSize <= 0 || $statement->byteSize > self::MAX_INPUT_BYTES) {
            throw new ImportException('Local conversion requires a PDF or XLSX of at most 10 MiB.');
        }
        $directory = self::STAGING.'/'.Uuid::v4()->toRfc4122();
        if (!mkdir($directory, 0755)) {
            throw new ImportException('Cannot prepare local conversion staging.');
        }
        $path = $directory.'/statement.'.$extension;
        try {
            $input = $this->storage->read($statement->storedFilename);
            try {
                $output = fopen($path, 'xb');
                if (false === $output) {
                    throw new ImportException('Cannot stage statement for local conversion.');
                }
                try {
                    $size = stream_copy_to_stream($input, $output, self::MAX_INPUT_BYTES + 1);
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($input);
            }
            if ($size !== $statement->byteSize || hash_file('sha256', $path) !== $statement->sha256) {
                throw new ImportException('Stored statement does not match its admitted metadata.');
            }
            // Only this disposable staging volume is visible to the read-only converter.
            chmod($directory, 0755);
            chmod($path, 0644);

            return $this->request($path);
        } catch (\Throwable) {
            // Do not retain upstream messages, paths, document content or provider payloads.
            throw new ImportException('Local document conversion failed. Verify that the digital PDF or XLSX contains readable text.');
        } finally {
            if ((is_file($path) && !unlink($path)) || !rmdir($directory)) {
                throw new ImportException('Local conversion staging cleanup failed; operator attention is required.');
            }
        }
    }

    private function request(string $path): string
    {
        $factory = new Psr17Factory();
        // Native untraced transport: never send statement text to the profiler or logs.
        $http = new AmpHttpClient([
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new ImportException('Local conversion response exceeded its limit.');
                }
            },
        ]);
        $client = Client::builder()->setClientInfo('finance-document-converter', '1.0')->setInitTimeout(10)->setRequestTimeout(self::TIMEOUT_SECONDS)->setMaxRetries(0)->build();
        try {
            $client->connect(new HttpTransport(self::ENDPOINT, httpClient: new Psr18Client($http, $factory, $factory), requestFactory: $factory, streamFactory: $factory, maxSseBufferBytes: self::MAX_RESPONSE_BYTES));
            $result = $client->callTool(self::TOOL, ['uri' => 'file://'.$path]);
            if ($result->isError) {
                throw new ImportException('Local conversion was unsuccessful.');
            }
            $text = '';
            foreach ($result->content as $content) {
                if (!$content instanceof TextContent) {
                    throw new ImportException('Local conversion returned unexpected content.');
                }
                $text .= $content->text;
                if (\strlen($text) > self::MAX_OUTPUT_BYTES) {
                    throw new ImportException('Converted document exceeded its text limit.');
                }
            }
            if ('' === trim($text)) {
                throw new ImportException('Local conversion returned no readable text.');
            }

            return $text;
        } finally {
            $client->disconnect();
        }
    }
}
