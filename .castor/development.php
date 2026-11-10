<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

function checkoutId(): string
{
    $root = \dirname(__DIR__);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($root))), '-');

    return substr($slug, 0, 36).'-'.substr(hash('sha256', $root), 0, 10);
}

function repositoryState(): string
{
    $process = new Process(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], \dirname(__DIR__));
    runTaskProcess($process);

    return trim($process->getOutput());
}

function developmentNetwork(): string
{
    return 'pfa-development-'.posix_getuid().'-'.substr(hash('sha256', repositoryState()), 0, 10);
}

function proxyName(): string
{
    return 'pfa-proxy-'.posix_getuid().'-'.substr(hash('sha256', repositoryState()), 0, 10);
}

function stackProject(string $mode): string
{
    return 'pfa-'.checkoutId().'-'.$mode;
}

function stackUrl(string $mode): string
{
    return 'http://'.checkoutId().('browser' === $mode ? '.browser' : '').'.finance.localhost';
}

/** @param list<string> $command */
function developmentProcess(array $command): string
{
    $process = new Process($command, \dirname(__DIR__), timeout: 600);
    runTaskProcess($process);
    echo $process->getErrorOutput();

    return $process->getOutput();
}

/** @return list<string> */
function stackCommand(string $mode): array
{
    return ['docker', 'compose', '--project-name', stackProject($mode), '-f', 'compose.yaml', '-f', 'compose.override.yaml', ...('browser' === $mode ? ['-f', 'compose.browser.yaml'] : [])];
}

/** @return array<string, string> */
function chatGptEnvironment(string $mode): array
{
    $directory = \dirname(__DIR__).'/var/tooling';
    $portFile = $directory.'/chatgpt-'.$mode.'.port';
    if (is_link($portFile)) {
        throw new \RuntimeException('Refusing symlinked ChatGPT configuration.');
    }
    if (!is_file($portFile)) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            throw new \RuntimeException('Cannot allocate a local callback port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (false === $address) {
            throw new \RuntimeException('Cannot inspect local callback port.');
        }
        $separator = strrpos($address, ':');
        if (false === $separator) {
            throw new \RuntimeException('Invalid local callback address.');
        }
        file_put_contents($portFile, substr($address, $separator + 1));
        chmod($portFile, 0600);
    }
    $port = trim((string) file_get_contents($portFile));
    if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
        throw new \RuntimeException('Invalid local callback port.');
    }

    return ['CHATGPT_CALLBACK_PORT' => $port, 'CHATGPT_FINANCE_URL' => stackUrl($mode)];
}

/** @return array<string, string> */
function stackEnvironment(string $mode): array
{
    $directory = \dirname(__DIR__).'/var/tooling';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked runtime secrets.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $secret = $directory.'/mercure-'.$mode.'.key';
    if (is_link($secret)) {
        throw new \RuntimeException('Refusing symlinked Mercure secret.');
    }
    if (!is_file($secret)) {
        $file = fopen($secret, 'xb');
        if (false === $file) {
            throw new \RuntimeException('Cannot create Mercure secret.');
        }
        chmod($secret, 0600);
        fwrite($file, bin2hex(random_bytes(32)));
        fclose($file);
    }

    return [...chatGptEnvironment($mode), 'FINANCE_CHECKOUT_ID' => checkoutId(), 'FINANCE_PROJECT' => stackProject($mode), 'FINANCE_NETWORK' => developmentNetwork(), 'FINANCE_PUBLIC_URL' => stackUrl($mode), 'MERCURE_JWT_SECRET' => trim((string) file_get_contents($secret)), 'LOCAL_UID' => (string) posix_getuid(), 'LOCAL_GID' => (string) posix_getgid()];
}

/** @param list<string> $arguments */
function stackRun(string $mode, array $arguments): void
{
    $process = new Process([...stackCommand($mode), ...$arguments], \dirname(__DIR__), stackEnvironment($mode), timeout: 600);
    runTaskProcess($process);
    echo $process->getOutput();
    echo $process->getErrorOutput();
}

function proxyState(): string
{
    $directory = repositoryState().'/finance-development';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked proxy state.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    return $directory;
}

function assertProxyOwner(string $kind, string $name): bool
{
    $inspect = new Process(['docker', $kind, 'inspect', '--format', '{{index .Labels "finance.proxy.owner"}}', $name]);
    if ('container' === $kind) {
        $inspect = new Process(['docker', 'inspect', '--format', '{{index .Config.Labels "finance.proxy.owner"}}', $name]);
    }
    if (0 !== runTaskProcess($inspect, false)) {
        if (false === stripos($inspect->getErrorOutput(), 'no such') && false === stripos($inspect->getErrorOutput(), 'not found')) {
            throw new \RuntimeException('Cannot inspect development proxy resource: '.$inspect->getErrorOutput());
        }

        return false;
    }
    if ((string) posix_getuid() !== trim($inspect->getOutput())) {
        throw new \RuntimeException('Refusing foreign development proxy resource: '.$name);
    }

    return true;
}

function proxyAddress(): string
{
    if (!assertProxyOwner('container', proxyName())) {
        throw new \RuntimeException('Start a development or browser stack before running live routing scenarios.');
    }
    $address = trim(developmentProcess(['docker', 'inspect', '--format', '{{with index .NetworkSettings.Networks "'.developmentNetwork().'"}}{{.IPAddress}}{{end}}', proxyName()]));
    if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
        throw new \RuntimeException('The development proxy has no network address.');
    }

    return $address;
}

function ensureProxy(string $state): void
{
    if (!assertProxyOwner('network', developmentNetwork())) {
        developmentProcess(['docker', 'network', 'create', '--label', 'finance.proxy.owner='.posix_getuid(), developmentNetwork()]);
    }
    if (assertProxyOwner('container', proxyName())) {
        developmentProcess(['docker', 'start', proxyName()]);

        return;
    }
    // Reuse the application image's Caddy binary; no application or Docker socket is mounted.
    developmentProcess(['docker', 'run', '-d', '--name', proxyName(), '--label', 'finance.proxy.owner='.posix_getuid(), '--network', developmentNetwork(), '--publish', '127.0.0.1:80:80', '--volume', $state.':/etc/pfa:ro', '--env', 'XDG_CONFIG_HOME=/tmp/config', '--env', 'XDG_DATA_HOME=/tmp/data', '--no-healthcheck', 'pfa-'.checkoutId().'-php:dev', 'frankenphp', 'run', '--config', '/etc/pfa/Caddyfile']);
}

/** @param array<string, string> $routes */
function writeProxyConfig(string $state, array $routes): void
{
    TaskExecution::$current?->throwIfInterrupted();
    $config = "{\n\tadmin localhost:2019\n\tauto_https off\n}\n:80 {\n\trespond \"No development route registered\" 404\n}\n";
    ksort($routes);
    foreach ($routes as $hostname => $upstream) {
        if (1 !== preg_match('/^[a-z0-9.-]+\.finance\.localhost$/D', $hostname) || 1 !== preg_match('/^pfa-[a-z0-9-]+:(8080)$/D', $upstream)) {
            throw new \RuntimeException('Invalid development proxy route.');
        }
        $config .= "http://{$hostname} {\n\treverse_proxy {$upstream}\n}\n";
    }
    file_put_contents($state.'/Caddyfile.next', $config);
    TaskExecution::$current?->throwIfInterrupted();
    rename($state.'/Caddyfile.next', $state.'/Caddyfile');
}

function developmentStack(string $mode, string $action): int
{
    $exit = report($mode.':stack:'.$action, ['docker', 'compose', $action], runtime: false, operation: static function () use ($mode, $action): int {
        if ('status' === $action) {
            stackRun($mode, ['ps', '--format=json']);
            echo stackUrl($mode)."\n";
            echo 'ChatGPT callback: http://127.0.0.1:'.stackEnvironment($mode)['CHATGPT_CALLBACK_PORT']."/auth/callback\n";

            return 0;
        }
        $state = proxyState();
        $lock = fopen($state.'/lock', 'cb');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the shared development proxy.');
        }
        try {
            $routes = [];
            if (is_file($state.'/routes.json')) {
                $stored = json_decode((string) file_get_contents($state.'/routes.json'), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($stored)) {
                    throw new \RuntimeException('Invalid development route registry.');
                }
                foreach ($stored as $host => $target) {
                    if (!\is_string($host) || !\is_string($target)) {
                        throw new \RuntimeException('Invalid development route registration.');
                    }
                    $routes[$host] = $target;
                }
            }
            $hostname = (string) parse_url(stackUrl($mode), \PHP_URL_HOST);
            if ('up' === $action) {
                TaskExecution::$current?->throwIfInterrupted();
                $secret = \dirname(__DIR__).'/.env.local';
                if ('dev' === $mode && !file_exists($secret)) {
                    $file = fopen($secret, 'xb');
                    if (false === $file) {
                        throw new \RuntimeException('Cannot create the private development environment file.');
                    }
                    chmod($secret, 0600);
                    fwrite($file, 'APP_SECRET='.bin2hex(random_bytes(32))."\n");
                    fclose($file);
                }
                foreach ([\dirname(__DIR__).'/var', \dirname(__DIR__).'/var/browser-live', \dirname(__DIR__).'/var/browser-live/browser'] as $storage) {
                    if (is_link($storage)) {
                        throw new \RuntimeException('Refusing symlinked application storage.');
                    }
                }
                if ('browser' === $mode && !is_dir(\dirname(__DIR__).'/var/browser-live/browser')) {
                    mkdir(\dirname(__DIR__).'/var/browser-live/browser', 0700, true);
                }
                $queue = \dirname(__DIR__).'/var/'.('browser' === $mode ? 'browser-live/' : '').'queue';
                if (is_link($queue)) {
                    throw new \RuntimeException('Refusing symlinked queue storage.');
                }
                if (!is_dir($queue)) {
                    mkdir($queue, 0700, true);
                }
                stackRun($mode, ['build']);
                writeProxyConfig($state, $routes);
                ensureProxy($state);
                stackRun($mode, ['up', '-d', '--wait']);
                $routes[$hostname] = stackProject($mode).':8080';
            } else {
                stackRun($mode, ['down']);
                unset($routes[$hostname]);
            }
            writeProxyConfig($state, $routes);
            if (assertProxyOwner('container', proxyName()) && 'true' === trim(developmentProcess(['docker', 'inspect', '--format', '{{.State.Running}}', proxyName()]))) {
                developmentProcess(['docker', 'exec', proxyName(), 'frankenphp', 'reload', '--config', '/etc/pfa/Caddyfile']);
            }
            TaskExecution::$current?->throwIfInterrupted();
            file_put_contents($state.'/routes.json.next', json_encode($routes, \JSON_THROW_ON_ERROR)."\n");
            TaskExecution::$current?->throwIfInterrupted();
            rename($state.'/routes.json.next', $state.'/routes.json');
            echo stackUrl($mode)."\n";
            if ('up' === $action) {
                echo 'ChatGPT callback: http://127.0.0.1:'.stackEnvironment($mode)['CHATGPT_CALLBACK_PORT']."/auth/callback\n";
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return 0;
    });
    if (0 === $exit && 'stop' !== $action) {
        echo stackUrl($mode)."\n";
    }

    return $exit;
}

#[AsTask(namespace: 'dev', name: 'stack:up', description: 'Build and start this checkout behind its local hostname.')]
function developmentStackUp(): int
{
    return developmentStack('dev', 'up');
}

#[AsTask(namespace: 'dev', name: 'stack:status', description: 'Inspect this checkout and print its development URL.')]
function developmentStackStatus(): int
{
    return developmentStack('dev', 'status');
}

#[AsTask(namespace: 'dev', name: 'stack:stop', description: 'Stop this checkout only, preserving data and the shared proxy.')]
function developmentStackStop(): int
{
    return developmentStack('dev', 'stop');
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'browser', name: 'live:test', description: 'Run browser tests against the isolated live stack; pass native PHPUnit arguments after --.')]
function browserLiveTest(#[AsArgsAfterOptionEnd] array $arguments = [], bool $headed = false): int
{
    if ([] === $arguments) {
        throw new \InvalidArgumentException('Pass the PHPUnit test selection after --.');
    }

    return report('browser:live:test', ['env', 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'FINANCE_IMPORT_URL='.stackUrl('browser'), 'FINANCE_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-live-test/junit.xml'], headed: $headed, networked: true);
}

#[AsTask(namespace: 'dev', name: 'proxy:stop', description: 'Stop the shared proxy; --for-upgrade permits registered routes and removes only its owned container.')]
function developmentProxyStop(bool $forUpgrade = false): int
{
    return report('dev:proxy:stop', ['docker', 'stop', proxyName()], runtime: false, operation: static function () use ($forUpgrade): int {
        $state = proxyState();
        $lock = fopen($state.'/lock', 'cb');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the shared development proxy.');
        }
        try {
            if (!$forUpgrade && is_file($state.'/routes.json') && [] !== json_decode((string) file_get_contents($state.'/routes.json'), true, flags: \JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Stop registered checkout stacks before stopping the shared proxy.');
            }
            if (assertProxyOwner('container', proxyName())) {
                developmentProcess(['docker', 'stop', proxyName()]);
                if ($forUpgrade) {
                    developmentProcess(['docker', 'rm', proxyName()]);
                    echo "Shared proxy removed for upgrade; registered routes and application stacks are preserved. Run dev:stack:up from the prepared checkout to restore all preview URLs.\n";
                }
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return 0;
    });
}
