<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;

function productionProbeStorage(): string
{
    $root = \dirname(__DIR__).'/var/production-probe';
    foreach ([\dirname($root), $root, $root.'/data', $root.'/data/statements'] as $path) {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked production-probe storage.');
        }
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
    }

    return $root;
}

#[AsTask(namespace: 'production', name: 'probe', description: 'Isolated production rebuild proof: up (also rebuild), seed once, status, config, or stop. Preserves all data.')]
function productionProbe(#[AsArgument] string $action, bool $changedRoute = false): int
{
    if ($changedRoute) {
        if ('up' !== $action) {
            throw new \InvalidArgumentException('--changed-route is only valid with up.');
        }
        $path = \dirname(__DIR__).'/src/Controller/StatementDownloadController.php';
        $original = file_get_contents($path);
        if (false === $original) {
            throw new \RuntimeException('Cannot read download controller for the rebuild probe.');
        }
        $patched = str_replace("'/home/statement/{id}/download'", "'/home/statement/{id}/download-rebuilt'", $original, $count);
        if (1 !== $count) {
            throw new \RuntimeException('Expected exactly one original download route for the rebuild probe.');
        }
        file_put_contents($path, $patched);
        try {
            return developmentStack('production', 'up');
        } finally {
            if (file_get_contents($path) !== $patched) {
                throw new \RuntimeException('Download controller changed concurrently; refusing to overwrite it.');
            }
            file_put_contents($path, $original);
        }
    }
    if (\in_array($action, ['up', 'status', 'stop'], true)) {
        return developmentStack('production', $action);
    }
    if (!\in_array($action, ['seed', 'config'], true)) {
        throw new \InvalidArgumentException('Use up, seed, status, config or stop.');
    }

    return report('production:probe:'.$action, ['docker', 'compose', $action], runtime: false, operation: static function () use ($action): int {
        if ('config' === $action) {
            stackRun('production', ['config']);

            return 0;
        }
        $root = productionProbeStorage();
        if (is_link($root.'/data/app.db') || file_exists($root.'/data/app.db')) {
            throw new \RuntimeException('Probe database already exists. Refusing to seed again or reset data.');
        }
        foreach ([['doctrine:migrations:migrate', '--no-interaction'], ['hautelook:fixtures:load', '--append', '--no-interaction']] as $arguments) {
            stackRun('production', ['run', '--rm', '--no-deps', 'fixtures', 'php', 'bin/console', ...$arguments]);
        }

        return 0;
    });
}
