<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;

#[AsTask(namespace: 'tooling', name: 'build', description: 'Build the authoritative development and QA runtime.')]
function toolingBuild(): int
{
    return report('tooling:build', ['docker', 'build', '--target', 'frankenphp_tooling', '-t', toolingImage(), '.'], runtime: false);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'composer', name: 'install', description: 'Install locked dependencies; pass additional native Composer options after --.')]
function composerInstall(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('composer:install', ['composer', 'install', '--no-interaction', '--no-progress', ...$arguments]);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'composer', name: 'require', description: 'Require dependencies through the authoritative runtime; pass native Composer arguments after --.')]
function composerRequire(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('composer:require', ['composer', 'require', '--no-interaction', '--no-progress', ...$arguments]);
}

#[AsTask(namespace: 'browser', name: 'install', description: 'Install the pinned Chromium browser without launching it.')]
function browserInstall(): int
{
    return report('browser:install', ['php', 'vendor/bin/playwright-install', 'chromium']);
}

#[AsTask(namespace: 'tooling', name: 'prepare', description: 'Build runtime, install dependencies, and prepare Chromium.')]
function toolingPrepare(): int
{
    return aggregate('tooling:prepare', ['tooling:build' => toolingBuild(...), 'composer:install' => composerInstall(...), 'browser:install' => browserInstall(...)]);
}

#[AsTask(namespace: 'dev', name: 'cache:warmup', description: 'Prepare compiled container metadata for Mate.')]
function devCacheWarmup(): int
{
    return report('dev:cache:warmup', ['php', 'bin/console', 'cache:warmup', '--env=dev', '--no-interaction']);
}

#[AsTask(namespace: 'dev', name: 'fixtures:load', description: 'Append synthetic Alice fixtures to this checkout dev database. Never purges or overwrites existing accounts.')]
function devFixturesLoad(): int
{
    return report('dev:fixtures:load', ['php', 'bin/console', 'hautelook:fixtures:load', '--env=dev', '--append', '--no-interaction']);
}

#[AsTask(namespace: 'test', name: 'database:prepare', description: 'Prepare only the fixed, owned SQLite browser-test database.')]
function testDatabasePrepare(): int
{
    $storage = \dirname(__DIR__).'/var/browser';
    foreach ([\dirname($storage), $storage, $storage.'/application.db'] as $path) {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked test database storage.');
        }
    }
    if (!is_dir($storage)) {
        mkdir($storage, 0700, true);
    }

    return report('test:database:prepare', ['php', 'bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction', '--allow-no-migration']);
}

#[AsTask(namespace: 'test', name: 'database:fresh', description: 'Verify migrations and mappings on new, isolated diagnostic storage without resetting existing databases.')]
function testDatabaseFresh(): int
{
    $root = \dirname(__DIR__);
    foreach ([$root.'/var', $root.'/var/browser'] as $path) {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked diagnostic storage.');
        }
    }
    $relative = 'var/browser/migrations-'.bin2hex(random_bytes(8));
    mkdir($root.'/'.$relative, 0700, true);
    // The dev configuration accepts this explicit URL; test always uses its fixed database.
    $command = ['env', 'DATABASE_URL=sqlite:////app/'.$relative.'/application.db', 'php', 'bin/console'];
    $exit = report('test:database:fresh-migrate', [...$command, 'doctrine:migrations:migrate', '--env=dev', '--no-interaction']);
    if (0 !== $exit) {
        return $exit;
    }

    return report('test:database:fresh-schema', [...$command, 'doctrine:schema:validate', '--env=dev']);
}

#[AsTask(namespace: 'charts', name: 'build', description: 'Build the pinned chart service without restarting any running service.')]
function chartsBuild(bool $browser = false): int
{
    $mode = $browser ? 'browser' : 'dev';

    return report('charts:build', [...stackCommand($mode), 'build', 'charts'], runtime: false, operation: static function () use ($mode): int {
        stackRun($mode, ['build', 'charts']);

        return 0;
    });
}

#[AsTask(namespace: 'browser', name: 'stack:up', description: 'Build and start only the isolated browser-test application.')]
function browserStackUp(): int
{
    return developmentStack('browser', 'up');
}

#[AsTask(namespace: 'browser', name: 'stack:status', description: 'Inspect only the isolated browser-test application.')]
function browserStackStatus(): int
{
    return developmentStack('browser', 'status');
}

#[AsTask(namespace: 'browser', name: 'stack:restart-worker', description: 'Restart only this checkout\'s isolated browser worker to load changed code and templates.')]
function browserStackRestartWorker(bool $force = false): int
{
    $command = ['restart', 'worker'];

    return report('browser:stack:restart-worker', [...stackCommand('browser'), ...$command], runtime: false, operation: static function () use ($command, $force): int {
        if ($force) {
            // Explicit abrupt shutdown, scoped to this checkout's isolated worker only.
            stackRun('browser', ['kill', '--signal', 'SIGKILL', 'worker']);
        }
        stackRun('browser', $command);

        return 0;
    });
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'browser', name: 'stack:console', description: 'Run Symfony console in this checkout\'s running isolated browser stack; pass arguments after --.')]
function browserStackConsole(#[AsArgsAfterOptionEnd] array $arguments = [], bool $worker = false): int
{
    $command = ['exec', '-T', '--user', posix_getuid().':'.posix_getgid(), $worker ? 'worker' : 'php', 'php', 'bin/console', ...$arguments];

    return report('browser:stack:console', [...stackCommand('browser'), ...$command], runtime: false, operation: static function () use ($command): int {
        stackRun('browser', $command);

        return 0;
    });
}

#[AsTask(namespace: 'browser', name: 'stack:restart-broker', description: 'Restart only this checkout\'s isolated broker to reload configuration; run only with no active work.')]
function browserStackRestartBroker(): int
{
    $command = ['restart', 'broker'];

    return report('browser:stack:restart-broker', [...stackCommand('browser'), ...$command], runtime: false, operation: static function () use ($command): int {
        stackRun('browser', $command);

        return 0;
    });
}

#[AsTask(namespace: 'browser', name: 'stack:stop', description: 'Stop only the isolated browser-test application without deleting storage.')]
function browserStackStop(): int
{
    return developmentStack('browser', 'stop');
}
