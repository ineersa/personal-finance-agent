<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;

#[AsTask(namespace: 'mate', name: 'services')]
function mateServices(string $query): int
{
    if ('' === trim($query)) {
        throw new \InvalidArgumentException('A narrow service query is required.');
    }

    return report('mate:services', ['php', 'vendor/bin/mate', 'tools:call', 'symfony-services', '--query='.$query, '--limit=10', '--format=json'], artifact: 'var/cache/dev/*Container.xml');
}

#[AsTask(namespace: 'mate', name: 'logs')]
function mateLogs(string $correlation): int
{
    if ('' === trim($correlation)) {
        throw new \InvalidArgumentException('A correlation identifier is required.');
    }

    return report('mate:logs', ['php', 'vendor/bin/mate', 'tools:call', 'monolog-context-search', '--key=correlation_id', '--value='.$correlation, '--limit=20', '--format=json'], artifact: 'var/log/*.log');
}

#[AsTask(namespace: 'mate', name: 'profiles')]
function mateProfiles(): int
{
    return report('mate:profiles', ['php', 'vendor/bin/mate', 'tools:call', 'symfony-profiler-list', '--limit=5', '--format=json'], artifact: 'var/cache/dev/profiler/*/*/*');
}

#[AsTask(namespace: 'mate', name: 'tools', description: 'Discover installed Mate tool schemas as JSON.')]
function mateTools(): int
{
    return report('mate:tools', ['php', 'vendor/bin/mate', 'tools:list', '--format=json']);
}

#[AsTask(namespace: 'mate', name: 'inspect', description: 'Inspect one Mate tool schema as JSON.')]
function mateInspect(string $tool): int
{
    return report('mate:inspect', ['php', 'vendor/bin/mate', 'tools:inspect', $tool, '--format=json']);
}

#[AsTask(namespace: 'mate', name: 'discover', description: 'Refresh official extensions while preserving curated project instructions.')]
function mateDiscover(): int
{
    $root = \dirname(__DIR__);
    $instructions = [];
    foreach (['AGENTS.md', 'mate/AGENT_INSTRUCTIONS.md', 'CLAUDE.md'] as $path) {
        $instructions[$path] = is_file($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;
    }
    try {
        return report('mate:discover', ['php', 'vendor/bin/mate', 'discover', '--no-interaction']);
    } finally {
        // Keep extension discovery, not upstream generic CLI/editor instructions.
        foreach ($instructions as $path => $content) {
            if (false !== $content) {
                file_put_contents($root.'/'.$path, $content);
            } elseif (is_file($root.'/'.$path)) {
                unlink($root.'/'.$path);
            }
        }
    }
}
