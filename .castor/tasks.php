<?php

namespace AppTasks;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

/** @param list<string> $command */
function report(string $task, array $command): int
{
    $root = dirname(__DIR__);
    $relative = 'var/qa/'.str_replace(':', '-', $task);
    $directory = $root.'/'.$relative;
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $lock = fopen($directory.'/lock', 'c');
    if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new \RuntimeException('Task report is already locked: '.$task);
    }
    $started = microtime(true);
    $stdout = in_array($task, ['cs:fix', 'cs:check', 'phpstan', 'lsp:check', 'mate:services', 'mate:logs', 'mate:profiles'], true) ? 'stdout.json' : 'stdout.log';
    $result = ['task' => $task, 'command' => $command, 'started_at' => gmdate(DATE_ATOM), 'status' => 'running', 'exit_code' => null, 'stdout' => $relative.'/'.$stdout, 'stderr' => $relative.'/stderr.log'];
    $save = static function () use (&$result, $directory): void {
        file_put_contents($directory.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    };
    $save();
    file_put_contents($directory.'/'.$stdout, '');
    file_put_contents($directory.'/stderr.log', '');
    try {
        $process = new Process($command, $root, ['NO_COLOR' => '1', 'COMPOSER_NO_INTERACTION' => '1'], timeout: 600);
        $exit = $process->run(static function (string $type, string $data) use ($directory, $stdout): void {
            file_put_contents($directory.'/'.(Process::ERR === $type ? 'stderr.log' : $stdout), $data, FILE_APPEND);
        });
        if (0 === $exit && 'lsp:check' === $task) {
            $diagnostics = json_decode((string) file_get_contents($directory.'/'.$stdout), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($diagnostics) || true !== ($diagnostics['complete'] ?? false) || [] === ($diagnostics['projects'] ?? [])) {
                throw new \RuntimeException('LSP analysis is incomplete.');
            }
            foreach ($diagnostics['projects'] as $project) {
                if (true !== ($project['complete'] ?? false) || 'runtime' !== ($project['analysis']['mode'] ?? null) || 'ready' !== ($project['runtime']['state'] ?? null)) {
                    throw new \RuntimeException('LSP runtime analysis is incomplete.');
                }
            }
        }
    } catch (\Throwable $error) {
        file_put_contents($directory.'/stderr.log', $error->getMessage()."\n", FILE_APPEND);
        $exit = 1;
    }
    $result['exit_code'] = $exit;
    $result['status'] = 0 === $exit ? 'passed' : 'failed';
    $result['finished_at'] = gmdate(DATE_ATOM);
    $result['duration_ms'] = (microtime(true) - $started) * 1000;
    $save();
    flock($lock, LOCK_UN);
    fclose($lock);
    echo json_encode(['task' => $task, 'status' => $result['status'], 'exit_code' => $exit, 'result' => $relative.'/result.json'], JSON_THROW_ON_ERROR)."\n";

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
    return report('cs:fix', [PHP_BINARY, 'vendor/bin/php-cs-fixer', 'fix', '--format=json']);
}

#[AsTask(namespace: 'cs', name: 'check')]
function csCheck(): int
{
    return report('cs:check', [PHP_BINARY, 'vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--diff', '--format=json']);
}

#[AsTask(namespace: '', name: 'phpstan')]
function phpstan(): int
{
    return report('phpstan', [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=512M']);
}

#[AsTask(namespace: 'lsp', name: 'check')]
function lspCheck(): int
{
    return report('lsp:check', ['symfony', 'lsp:check', '--format=json', '--environment=dev']);
}

#[AsTask(namespace: '', name: 'test')]
function browserTest(): int
{
    return report('test', [PHP_BINARY, 'bin/browser-suite']);
}

/** @param list<string> $tasks */
function aggregate(string $task, array $tasks): int
{
    return report($task, [PHP_BINARY, 'bin/qa-aggregate', ...$tasks]);
}

#[AsTask(namespace: 'qa', name: 'static')]
function qaStatic(): int
{
    return aggregate('qa:static', ['composer:validate', 'cs:check', 'phpstan', 'lsp:check']);
}

#[AsTask(namespace: '', name: 'qa')]
function qa(): int
{
    return aggregate('qa', ['qa:static', 'test']);
}
