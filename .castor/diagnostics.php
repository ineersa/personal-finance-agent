<?php

namespace AppTasks;

use Castor\Attribute\AsTask;

#[AsTask(namespace: 'mate', name: 'services')]
function mateServices(string $query): int
{
    return report('mate:services', [PHP_BINARY, 'bin/mate-diagnostic', 'services', $query]);
}

#[AsTask(namespace: 'mate', name: 'logs')]
function mateLogs(string $correlation): int
{
    return report('mate:logs', [PHP_BINARY, 'bin/mate-diagnostic', 'logs', $correlation]);
}

#[AsTask(namespace: 'mate', name: 'profiles')]
function mateProfiles(): int
{
    return report('mate:profiles', [PHP_BINARY, 'bin/mate-diagnostic', 'profiles']);
}
