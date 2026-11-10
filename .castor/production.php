<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\Component\Process\Process;

#[AsTask(namespace: 'prod', name: 'smoke', description: 'Smoke-check the compiled production PHP/charts images on checkout-owned storage; does not initialize the full demo.')]
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
        // Host port zero requests an ephemeral binding, not a valid OAuth redirect port.
        file_put_contents($override, "services:\n  php:\n    environment:\n      IMPORT_UPDATES_ENABLED: '0'\n      CHATGPT_CALLBACK_PORT: '".OAuthConfig::DEFAULT_PORT."'\n");
        $command = ['docker', 'compose', '--env-file', $secret, '--project-name', 'pfa-'.checkoutId().'-prod-smoke', '-f', 'compose.yaml', '-f', 'compose.prod.yaml', '-f', $override];
        /** @param list<string> $arguments */
        $run = static function (array $arguments) use ($command, $root, $secret): string {
            $process = new Process([...$command, ...$arguments], $root, ['HTTP_PORT' => '0', 'CHATGPT_CALLBACK_PORT' => '0', 'DEMO_ENV_FILE' => $secret, 'COMPOSE_PROFILES' => ''], timeout: 600);
            runTaskProcess($process);

            return $process->getOutput();
        };
        try {
            $run(['up', '-d', '--build', '--no-deps', '--wait', 'php', 'charts']);
            $run(['exec', '-T', 'php', 'php', 'bin/console', 'about']);
            echo "Production PHP/charts image smoke healthy. Full demo initialization, workers and live updates are not exercised here.\n";
        } finally {
            // Exact checkout-owned project only. Preserve its application volume.
            $run(['down']);
        }

        return 0;
    }));
}
