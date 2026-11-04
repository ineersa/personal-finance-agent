<?php

declare(strict_types=1);

namespace App\Finance\Sql;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class SqlExecutor
{
    public const int MAX_SQL_BYTES = 16384;
    public const int MAX_INPUT_BYTES = 32768;
    public const int MAX_OUTPUT_BYTES = 262144;
    public const int MAX_ROWS = 200;
    public const int MAX_COLUMNS = 50;
    public const int ADDRESS_SPACE_BYTES = 536870912;
    public const int CPU_SECONDS = 5;
    private const float WALL_SECONDS = 5.0;

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    /** @return array<string, mixed> */
    public function execute(string $path, string $sql): array
    {
        if ('' === trim($sql) || \strlen($sql) > self::MAX_SQL_BYTES) {
            return ['error' => 'Provide a nonempty SQL query of at most 16384 bytes.'];
        }
        $php = (new PhpExecutableFinder())->find(false);
        if (false === $php) {
            return ['error' => 'SQL execution is unavailable.'];
        }
        try {
            $input = json_encode(['path' => $path, 'sql' => $sql], \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['error' => 'SQL input must be valid UTF-8.'];
        }
        if (\strlen($input) > self::MAX_INPUT_BYTES) {
            return ['error' => 'SQL input exceeds its byte limit.'];
        }
        // Arguments are application-controlled; SQL and the trusted path travel only on stdin.
        $process = new Process([$php, '-d', 'memory_limit=64M', '-d', 'display_errors=0', '-d', 'log_errors=0', $this->root.'/bin/finance-sql.php'], $this->root, timeout: self::WALL_SECONDS);
        $process->setInput($input);
        $process->disableOutput();
        $output = '';
        $bytes = 0;
        try {
            $process->start(static fn (string $type, string $chunk): bool => false);
            $overflow = $process->waitUntil(static function (string $type, string $chunk) use (&$output, &$bytes): bool {
                $bytes += \strlen($chunk);
                if ($bytes > self::MAX_OUTPUT_BYTES) {
                    return true;
                }
                if (Process::OUT === $type) {
                    $output .= $chunk;
                }

                return false;
            });
            if ($overflow) {
                return ['error' => 'SQL output exceeds its byte limit.'];
            }
            if (0 !== $process->wait()) {
                return ['error' => 'SQL failed or exceeded its execution/resource limits.'];
            }
            $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($result) || !\is_array($result['columns'] ?? null) || !\is_array($result['rows'] ?? null) || !\is_bool($result['truncated'] ?? null)) {
                return ['error' => 'SQL execution returned an invalid result.'];
            }

            return ['columns' => $result['columns'], 'rows' => $result['rows'], 'truncated' => $result['truncated']];
        } catch (\Throwable) {
            // Never return process exceptions, stderr, SQL text or private filesystem paths.
            return ['error' => 'SQL failed or exceeded its execution/output limits.'];
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
