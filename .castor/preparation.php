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

    return report('test:database:prepare', ['php', 'bin/console', 'dbal:run-sql', 'SELECT 1', '--env=test', '--no-interaction']);
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

#[AsTask(namespace: 'browser', name: 'stack:stop', description: 'Stop only the isolated browser-test application without deleting storage.')]
function browserStackStop(): int
{
    return developmentStack('browser', 'stop');
}
