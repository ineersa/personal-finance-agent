<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ChatConversation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'app:demo:export-chats', description: 'Export five explicitly selected English synthetic recordings from isolated browser storage to canonical fixtures.')]
final class ExportDemoChatsCommand extends Command
{
    private const int RECORDINGS = 5;
    private const int FAVOURITE_RECORDINGS = 4;

    public function __construct(private readonly EntityManagerInterface $em, #[Autowire('%kernel.project_dir%')] private readonly string $root, #[Autowire('%kernel.environment%')] private readonly string $environment)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Exactly five recorded English conversation IDs; first four become favourite fixtures.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The isolated stack mounts host var/browser-live as /app/var; application source is read-only.
        if ('test' !== $this->environment || $this->root.'/var/browser/application.db' !== ($this->em->getConnection()->getParams()['path'] ?? null)) {
            throw new \RuntimeException('Only this checkout isolated browser database may supply recordings.');
        }
        $ids = $input->getArgument('ids');
        if (!\is_array($ids) || self::RECORDINGS !== \count($ids) || self::RECORDINGS !== \count(array_unique($ids))) {
            throw new \InvalidArgumentException('Supply exactly five distinct, reviewed English synthetic recording IDs.');
        }
        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => 'demo@test.com']) ?? throw new \RuntimeException('Canonical demo owner unavailable.');
        $definitions = [];
        $titles = [];
        foreach (array_values($ids) as $index => $id) {
            $chat = $this->em->getRepository(ChatConversation::class)->findOneBy(['id' => $id, 'user' => $owner]) ?? throw new \RuntimeException('Selected owned recording unavailable.');
            if (ExecutionStatus::Completed !== $chat->generationStatus || [] === $chat->turns || [] === $chat->history) {
                throw new \RuntimeException('Only completed nonempty recordings may be exported.');
            }
            foreach ($chat->turns as $turn) {
                if ([] !== ($turn['charts'] ?? [])) {
                    throw new \RuntimeException('Chart recordings need private file fixture support before export.');
                }
            }
            // Do not export connection identity, registration IDs, credentials or in-flight lifecycle IDs.
            // Native messages (including provider signatures) remain lossless; review before committing.
            $definitions['demo_chat_'.($index + 1)] = [
                '__construct' => ['@demo', null, $chat->model, $chat->reasoningEffort],
                'id' => $chat->id,
                'favourite' => $index < self::FAVOURITE_RECORDINGS,
                'history' => $this->literalArray($chat->history),
                'turns' => $this->literalArray($chat->turns),
            ];
            $titles['demo_chat_'.($index + 1)] = trim(str_replace(["\r", "\n"], ' ', mb_substr($chat->getLabel(), 0, 160)));
        }
        $yaml = "# Synthetic finance conversations recorded through the real model; never credentials or owner data.\n".Yaml::dump([ChatConversation::class => $definitions], 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        foreach ($titles as $name => $title) {
            $yaml = str_replace('  '.$name.':', '  # '.$title."\n  ".$name.':', $yaml);
        }
        if (false === file_put_contents($this->root.'/var/demo-chat-export.yaml', $yaml)) {
            throw new \RuntimeException('Unable to write canonical chat fixtures.');
        }
        $output->writeln('Exported five selected English chat fixtures to var/demo-chat-export.yaml (four favourites, one ordinary chat). Review and copy to fixtures/central/chat.yaml on the host.');

        return Command::SUCCESS;
    }

    /** @param array<mixed> $value */
    private function literalArray(array $value): string
    {
        // Alice tokenizes DSL delimiters even inside PHP-quoted literals. A controlled
        // base64 alphabet keeps signed model text data, never executable expression source.
        return '<(json_decode(base64_decode(\''.base64_encode(json_encode($value, \JSON_THROW_ON_ERROR)).'\'), true, 512, JSON_THROW_ON_ERROR))>';
    }
}
