<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\AI\Platform\Bridge\Decision\Answer\Answers;
use Symfony\AI\Platform\Bridge\Decision\Evaluation;
use Symfony\AI\Platform\Bridge\Decision\Question\QuestionInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class JevDecisions
{
    public function __construct(
        #[Autowire(service: 'app.jev.platform')]
        private PlatformInterface $platform,
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
        try {
            $answers = $this->platform->invoke($this->model, new Evaluation($state, $questions))->asObject();
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
