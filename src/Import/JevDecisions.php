<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\AI\Platform\Bridge\Decision\Answer\Answers;
use Symfony\AI\Platform\Bridge\Decision\Evaluation;
use Symfony\AI\Platform\Bridge\Decision\Question\QuestionInterface;
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Bridge\TypeSafe\Jev;
use Symfony\AI\Platform\Bridge\TypeSafe\ModelCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;

final readonly class JevDecisions
{
    public function __construct(
        #[Autowire('%env(JEV_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        #[Autowire('%env(JEV_BASE_URL)%')]
        private string $baseUrl,
        #[Autowire('%env(JEV_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @param array<mixed>                     $state
     * @param array<string, QuestionInterface> $questions
     */
    public function evaluate(#[\SensitiveParameter] array $state, array $questions): Answers
    {
        if ('' === $this->apiKey) {
            throw new ImportException('Jev is not configured. Set the server-side JEV_API_KEY.');
        }
        // Do not use the traceable framework client: profiler collectors must not retain statement payloads.
        $platform = Factory::createPlatform($this->apiKey, HttpClient::create(['timeout' => 30, 'max_duration' => 60]), new ModelCatalog([
            $this->model => ['class' => Jev::class, 'capabilities' => []],
        ]), baseUrl: $this->baseUrl);
        try {
            $answers = $platform->invoke($this->model, new Evaluation($state, $questions))->asObject();
        } catch (\Throwable) {
            // Provider error bodies and exception chains may echo personal state or authentication details.
            throw new ImportException('Jev could not complete this request. No interpretation was accepted.');
        }
        if (!$answers instanceof Answers) {
            throw new ImportException('Jev returned an unsupported response.');
        }

        return $answers;
    }
}
