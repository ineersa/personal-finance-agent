<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

#[AsTask(name: 'prod:smoke', description: 'Build and start a checkout-owned production preview without optional live updates.')]
function productionSmoke(): void
{
    exit(report('prod:smoke', [], runtime: false, operation: static function (): int {
        $root = \dirname(__DIR__);
        $directory = $root.'/var/qa/prod-smoke';
        $secret = $directory.'/runtime.env';
        if (is_link($directory) || is_link($secret)) {
            throw new \RuntimeException('Refusing symlinked production smoke paths.');
        }
        if (!is_file($secret)) {
            $handle = fopen($secret, 'xb');
            if (false === $handle) {
                throw new \RuntimeException('Cannot create private smoke environment.');
            }
            chmod($secret, 0600);
            fwrite($handle, 'APP_SECRET='.bin2hex(random_bytes(32))."\nIMPORT_UPDATES_ENABLED=0\n");
            fclose($handle);
        }
        $override = $directory.'/compose.yaml';
        file_put_contents($override, "services:\n  php:\n    env_file: !override\n      - path: ".$secret."\n        required: true\n");
        $command = ['docker', 'compose', '--project-name', 'pfa-'.checkoutId().'-prod-smoke', '-f', 'compose.yaml', '-f', 'compose.prod.yaml', '-f', $override];
        /** @param list<string> $arguments */
        $run = static function (array $arguments) use ($command, $root): string {
            $process = new Process([...$command, ...$arguments], $root, ['HTTP_PORT' => '0', 'COMPOSE_PROFILES' => ''], timeout: 600);
            runTaskProcess($process);

            return $process->getOutput();
        };
        if ('php' !== trim($run(['config', '--services']))) {
            throw new \RuntimeException('Production preview must not start the optional hub.');
        }
        try {
            $run(['up', '-d', '--build', '--wait']);
            $run(['exec', '-T', 'php', 'php', 'bin/console', 'about']);
            echo "Production preview healthy; optional hub excluded.\n";
        } finally {
            // Exact checkout-owned project only. Preserve its application volume.
            $run(['down']);
        }

        return 0;
    }));
}
