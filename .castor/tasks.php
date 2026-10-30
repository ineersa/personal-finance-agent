<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

/**
 * @param list<string>           $command
 * @param (\Closure(): int)|null $operation
 */
function report(string $task, array $command, bool $runtime = true, bool $headed = false, ?\Closure $operation = null, string $artifact = ''): int
{
    $root = \dirname(__DIR__);
    $relative = 'var/qa/'.str_replace(':', '-', $task);
    $directory = $root.'/'.$relative;
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $lock = fopen($directory.'/lock', 'cb');
    if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
        throw new \RuntimeException('Task report is already locked: '.$task);
    }
    $started = microtime(true);
    $stdout = \in_array($task, ['cs:fix', 'cs:check', 'phpstan', 'lsp:check', 'mate:services', 'mate:logs', 'mate:profiles', 'mate:tools', 'mate:inspect'], true) ? 'stdout.json' : 'stdout.log';
    $result = ['task' => $task, 'command' => $command, 'started_at' => gmdate(\DATE_ATOM), 'status' => 'running', 'exit_code' => null, 'stdout' => $relative.'/'.$stdout, 'stderr' => $relative.'/stderr.log'];
    $save = static function () use (&$result, $directory): void {
        file_put_contents($directory.'/result.json', json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    };
    $save();
    file_put_contents($directory.'/'.$stdout, '');
    file_put_contents($directory.'/stderr.log', '');
    $container = $runtime ? 'pfa-qa-'.bin2hex(random_bytes(8)) : null;
    $interrupted = 0;
    $handlers = [];
    $process = new Process($command, $root, timeout: 600);
    pcntl_async_signals(true);
    foreach ([\SIGINT, \SIGTERM, \SIGHUP] as $signal) {
        $handlers[$signal] = pcntl_signal_get_handler($signal);
        pcntl_signal($signal, static function (int $received) use (&$interrupted, &$process, $runtime): void {
            $interrupted = 128 + $received;
            if ($process->isRunning()) {
                $process->signal($received);
                if ($runtime) {
                    $process->stop(10, \SIGKILL);
                }
            }
        });
    }
    try {
        if ('' !== $artifact && [] === glob($root.'/'.$artifact)) {
            throw new \RuntimeException('Missing diagnostic artifacts. Warm the development cache or record a local request first.');
        }
        if (null !== $operation) {
            ob_start(static function (string $output) use ($directory, $stdout): string {
                file_put_contents($directory.'/'.$stdout, $output, \FILE_APPEND);

                return '';
            }, 1);
            try {
                $exit = $operation();
            } finally {
                ob_end_flush();
            }
        } else {
            if (null !== $container) {
                $command = runtimeCommand($command, $container, $headed);
                $result['command'] = $command;
                $result['runtime_container'] = $container;
                $save();
            }
            $process = new Process($command, $root, ['NO_COLOR' => '1', 'COMPOSER_NO_INTERACTION' => '1'], timeout: 'test:browser' === $task ? 300 : 600);
            $exit = $process->run(static function (string $type, string $data) use ($directory, $stdout): void {
                file_put_contents($directory.'/'.(Process::ERR === $type ? 'stderr.log' : $stdout), $data, \FILE_APPEND);
            });
            if (0 === $exit && 'lsp:check' === $task) {
                $diagnostics = json_decode((string) file_get_contents($directory.'/'.$stdout), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($diagnostics) || true !== ($diagnostics['complete'] ?? false) || [] === ($diagnostics['projects'] ?? [])) {
                    throw new \RuntimeException('LSP analysis is incomplete.');
                }
                foreach ($diagnostics['projects'] as $project) {
                    if (true !== ($project['complete'] ?? false) || 'runtime' !== ($project['analysis']['mode'] ?? null) || 'ready' !== ($project['runtime']['state'] ?? null)) {
                        throw new \RuntimeException('LSP runtime analysis is incomplete.');
                    }
                }
            }
        }
    } catch (\Throwable $error) {
        file_put_contents($directory.'/stderr.log', $error->getMessage()."\n", \FILE_APPEND);
        $exit = 1;
    } finally {
        if (null !== $container) {
            try {
                cleanupRuntime($container);
            } catch (\Throwable $error) {
                file_put_contents($directory.'/stderr.log', $error->getMessage()."\n", \FILE_APPEND);
                $exit = 1;
            }
        }
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }
    if (0 !== $interrupted) {
        $exit = $interrupted;
    }
    $result['exit_code'] = $exit;
    $result['status'] = 0 === $exit ? 'passed' : 'failed';
    $result['finished_at'] = gmdate(\DATE_ATOM);
    $result['duration_ms'] = (microtime(true) - $started) * 1000;
    $save();
    flock($lock, \LOCK_UN);
    fclose($lock);
    echo json_encode(['task' => $task, 'status' => $result['status'], 'exit_code' => $exit, 'result' => $relative.'/result.json'], \JSON_THROW_ON_ERROR)."\n";

    return $exit;
}

#[AsTask(namespace: 'composer', name: 'validate')]
function composerValidate(): int
{
    return report('composer:validate', ['composer', 'validate', '--strict']);
}

#[AsTask(namespace: 'cs', name: 'fix')]
function csFix(): int
{
    return report('cs:fix', ['php', 'vendor/bin/php-cs-fixer', 'fix', '--format=json']);
}

#[AsTask(namespace: 'cs', name: 'check')]
function csCheck(): int
{
    return report('cs:check', ['php', 'vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--diff', '--format=json']);
}

#[AsTask(namespace: '', name: 'phpstan')]
function phpstan(): int
{
    return report('phpstan', ['php', 'vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=512M']);
}

#[AsTask(namespace: 'lsp', name: 'check')]
function lspCheck(): int
{
    return report('lsp:check', ['symfony', 'lsp:check', '--format=json', '--environment=dev']);
}

#[AsTask(namespace: '', name: 'test', description: 'Run browser tests headlessly; use --headed only when the owner requests visibility.')]
function browserTest(bool $headed = false): int
{
    return report('test', ['vendor/bin/castor', 'test', ...($headed ? ['--headed'] : [])], runtime: false, operation: static function () use ($headed): int {
        $prepared = testDatabasePrepare();
        if (0 !== $prepared) {
            return $prepared;
        }

        return report('test:browser', ['env', 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'php', 'vendor/bin/phpunit', '--log-junit', 'var/qa/test/junit.xml'], headed: $headed);
    });
}

/** @param array<string, callable(): int> $tasks */
function aggregate(string $task, array $tasks): int
{
    return report($task, ['vendor/bin/castor', $task], runtime: false, operation: static function () use ($tasks): int {
        $exit = 0;
        foreach ($tasks as $run) {
            $code = $run();
            if (0 === $exit && 0 !== $code) {
                $exit = $code;
            }
            if (\in_array($code, [128 + \SIGINT, 128 + \SIGTERM, 128 + \SIGHUP], true)) {
                break;
            }
        }

        return $exit;
    });
}

#[AsTask(namespace: 'qa', name: 'static')]
function qaStatic(): int
{
    return aggregate('qa:static', ['composer:validate' => composerValidate(...), 'cs:check' => csCheck(...), 'phpstan' => phpstan(...), 'lsp:check' => lspCheck(...)]);
}

#[AsTask(namespace: '', name: 'check', description: 'Run static checks and headless browser tests; --headed requires an owner request.')]
function check(bool $headed = false): int
{
    return aggregate('check', ['qa:static' => qaStatic(...), 'test' => static fn (): int => browserTest($headed)]);
}
