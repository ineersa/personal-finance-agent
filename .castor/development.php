<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgument;
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

    return ['FINANCE_CHECKOUT_ID' => checkoutId(), 'FINANCE_PROJECT' => stackProject($mode), 'FINANCE_NETWORK' => developmentNetwork(), 'FINANCE_PUBLIC_URL' => stackUrl($mode), 'MERCURE_JWT_SECRET' => trim((string) file_get_contents($secret)), 'LOCAL_UID' => (string) posix_getuid(), 'LOCAL_GID' => (string) posix_getgid()];
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
                stackRun($mode, ['stop']);
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

#[AsTask(namespace: 'browser', name: 'imports:test', description: 'Run real-network upload scenarios against this checkout’s isolated stack; hosted Jev calls are paid.')]
function browserImportsTest(bool $replay = false, bool $privacy = false, bool $boundary = false): int
{
    if ((int) $replay + (int) $privacy + (int) $boundary > 1) {
        throw new \InvalidArgumentException('Select only one verification mode.');
    }
    $arguments = ['--filter', $replay ? 'testPreviouslyCompletedImportsRemainUnchangedAfterOperatorRedelivery' : 'testThreeSourceUploads|testMalformedUpload|testQuotedCsv'];
    $file = 'tests/Live/StatementImportTest.php';
    if ($privacy || $boundary) {
        $arguments = [];
        $file = $privacy ? 'tests/Live/ImportPrivacyTest.php' : 'tests/Live/UploadBoundaryTest.php';
    }

    return report('browser:imports:test', ['env', 'FINANCE_IMPORT_URL='.stackUrl('browser'), 'FINANCE_IMPORT_REPLAY='.($replay ? '1' : '0'), 'FINANCE_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', $file, ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-imports-test/junit.xml'], networked: true);
}

#[AsTask(namespace: 'browser', name: 'lifecycle:test', description: 'Verify queued-owner role loss and native statement deletion on the isolated browser stack.')]
function browserLifecycleTest(bool $workerFailure = false): int
{
    $root = \dirname(__DIR__);
    foreach ([$root.'/var', $root.'/var/browser-live', $root.'/var/browser-live/browser', $root.'/var/browser-live/browser/application.db'] as $path) {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked lifecycle-test storage.');
        }
    }
    if (!is_file($root.'/var/browser-live/browser/application.db')) {
        throw new \RuntimeException('Initialize the isolated browser-stack database before lifecycle verification.');
    }

    if ($workerFailure) {
        return report('browser:lifecycle:worker-failure', [], runtime: false, operation: static function () use ($root): int {
            $storage = $root.'/var/browser-live/browser/statements';
            $backup = $storage.'.worker-failure-'.bin2hex(random_bytes(6));
            $ready = $root.'/var/qa/lifecycle-worker-failure.json';
            if (is_link($storage) || !is_dir($storage) || file_exists($backup) || is_link($backup) || is_link($ready) || is_link($ready.'.tmp')) {
                throw new \RuntimeException('Unexpected isolated worker-failure paths.');
            }
            if (is_file($ready)) {
                unlink($ready);
            }
            $container = 'pfa-qa-worker-failure-'.bin2hex(random_bytes(6));
            $browser = new Process(runtimeCommand(['env', 'FINANCE_IMPORT_URL='.stackUrl('browser'), 'FINANCE_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', 'tests/Live/StatementLifecycleTest.php', '--filter', 'testExhaustedWorkerFailure', '--fail-on-empty-test-suite'], $container, false, true), $root, timeout: 150);
            $execution = TaskExecution::$current ?? throw new \LogicException('Missing task execution.');
            $moved = false;
            try {
                stackRun('browser', ['stop', 'worker']);
                if (0 !== browserStatementCount("record.processingStatus IN ('queued', 'running')") || 0 !== browserBrokerMessageCount()) {
                    throw new \RuntimeException('Finish unrelated isolated imports before this scenario.');
                }
                $browser->start();
                $execution->process = $browser;
                $deadline = time() + 60;
                while (!is_file($ready)) {
                    $execution->throwIfInterrupted();
                    $browser->checkTimeout();
                    if (!$browser->isRunning() || time() >= $deadline) {
                        throw new \RuntimeException('Browser did not report a connected queued page.');
                    }
                    usleep(100000);
                }
                $saved = json_decode((string) file_get_contents($ready), true, flags: \JSON_THROW_ON_ERROR);
                $id = (int) $saved['id'];
                if ($id <= 0 || 1 !== browserStatementCount("record.processingStatus IN ('queued', 'running')") || 1 !== browserStatementCount('record.id = '.$id." AND record.processingStatus = 'queued'") || 1 !== browserBrokerMessageCount()) {
                    throw new \RuntimeException('The browser upload is not the only eligible isolated message.');
                }
                $moved = rename($storage, $backup);
                if (!$moved) {
                    throw new \RuntimeException('Cannot stage the isolated storage fault.');
                }
                $fault = fopen($storage, 'xb');
                if (false === $fault) {
                    throw new \RuntimeException('Cannot stage the isolated storage fault.');
                }
                fclose($fault);
                // Eager native Flysystem root creation now fails during handler construction.
                stackRun('browser', ['exec', '-T', '--user', posix_getuid().':'.posix_getgid(), 'php', 'env', 'JEV_API_KEY=', 'php', 'bin/console', 'messenger:consume', 'statement_import', '--limit=1', '--time-limit=30', '--no-interaction', '-v']);
                unlink($storage);
                if (!rename($backup, $storage)) {
                    throw new \RuntimeException('Restore the isolated statement directory before restarting its worker.');
                }
                $moved = false;
                $execution->process = $browser;

                return $browser->wait();
            } finally {
                try {
                    if ($browser->isStarted()) {
                        if ($browser->isRunning()) {
                            $browser->stop(10);
                        }
                        echo $browser->getOutput();
                        echo $browser->getErrorOutput();
                    }
                } finally {
                    if ($moved) {
                        if (is_file($storage)) {
                            unlink($storage);
                        }
                        if (!rename($backup, $storage)) {
                            throw new \RuntimeException('Isolated statement storage needs restoration; worker remains stopped.');
                        }
                    }
                    try {
                        cleanupRuntime($container);
                    } finally {
                        stackRun('browser', ['up', '-d', '--wait', 'worker']);
                    }
                }
            }
        });
    }

    return report('browser:lifecycle:test', ['vendor/bin/castor', 'browser:lifecycle:test'], runtime: false, operation: static function (): int {
        $command = ['env', 'FINANCE_IMPORT_URL='.stackUrl('browser'), 'FINANCE_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', 'tests/Live/StatementLifecycleTest.php'];
        try {
            stackRun('browser', ['stop', 'worker']);
            $exit = report('browser:lifecycle:busy', [...$command, '--filter', 'testQueuedImport', '--fail-on-empty-test-suite'], networked: true);
            if (0 === $exit) {
                // Consume deterministically while the browser-promoted owner has no finance access.
                // An empty provider key also makes accidental interpretation fail before any HTTP call.
                $consumer = ['exec', '-T', '--user', posix_getuid().':'.posix_getgid(), 'php', 'env', 'JEV_API_KEY=', 'php', 'bin/console', 'messenger:consume', 'statement_import', '--limit=1', '--time-limit=30', '--no-interaction', '-v'];
                $exit = report('browser:lifecycle:promoted-owner', [...stackCommand('browser'), ...$consumer], runtime: false, operation: static function () use ($consumer): int {
                    stackRun('browser', $consumer);

                    return 0;
                });
            }
        } finally {
            stackRun('browser', ['up', '-d', '--wait', 'worker']);
        }
        if (0 !== $exit) {
            return $exit;
        }

        return report('browser:lifecycle:verify', [...$command, '--filter', 'testPromotedOwner', '--fail-on-empty-test-suite'], networked: true);
    });
}

/** @phpstan-impure */
function browserBrokerMessageCount(): int
{
    $code = <<<'CODE'
        require 'vendor/autoload.php';
        if (!is_file('/app/var/queue/broker.db')) { throw new RuntimeException('Missing isolated broker database.'); }
        $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/app/var/queue/broker.db']);
        echo $db->createQueryBuilder()->select('COUNT(*)')->from('queue_messages')->executeQuery()->fetchOne();
        CODE;
    $query = new Process([...stackCommand('browser'), 'exec', '-T', 'php', 'php', '-r', $code], \dirname(__DIR__), stackEnvironment('browser'), timeout: 30);
    runTaskProcess($query);
    if (!ctype_digit(trim($query->getOutput()))) {
        throw new \RuntimeException('Could not inspect the isolated broker.');
    }

    return (int) trim($query->getOutput());
}
/** @phpstan-impure */
function browserStatementCount(string $where): int
{
    $query = new Process([...stackCommand('browser'), 'exec', '-T', 'php', 'php', 'bin/console', 'doctrine:query:dql', 'SELECT COUNT(record.id) FROM App\\Entity\\Statement record WHERE '.$where, '--hydrate=single-scalar', '--no-ansi'], \dirname(__DIR__), stackEnvironment('browser'), timeout: 30);
    runTaskProcess($query);
    if (1 !== preg_match('/(?:int\((\d+)\)|string\(\d+\) "(\d+)")/', $query->getOutput(), $match)) {
        throw new \RuntimeException('Could not verify the isolated queue precondition.');
    }

    return (int) ('' !== $match[1] ? $match[1] : $match[2]);
}

#[AsTask(namespace: 'browser', name: 'routes:test', description: 'Verify comma-separated live stack URLs in a real tooling-container browser.')]
function browserRoutesTest(#[AsArgument] string $urls = '', string $absent = ''): int
{
    return report('browser:routes:test', ['env', 'FINANCE_ROUTING_URLS='.$urls, 'FINANCE_UNREGISTERED_URLS='.$absent, 'FINANCE_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', 'tests/Live/RoutingTest.php', '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-routes-test/junit.xml'], networked: true);
}

#[AsTask(namespace: 'dev', name: 'proxy:stop', description: 'Stop the shared proxy only when no checkout has registered routes.')]
function developmentProxyStop(): int
{
    return report('dev:proxy:stop', ['docker', 'stop', proxyName()], runtime: false, operation: static function (): int {
        $state = proxyState();
        $lock = fopen($state.'/lock', 'cb');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the shared development proxy.');
        }
        try {
            if (is_file($state.'/routes.json') && [] !== json_decode((string) file_get_contents($state.'/routes.json'), true, flags: \JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Stop registered checkout stacks before stopping the shared proxy.');
            }
            if (assertProxyOwner('container', proxyName())) {
                developmentProcess(['docker', 'stop', proxyName()]);
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return 0;
    });
}
