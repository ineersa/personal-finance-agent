<?php

declare(strict_types=1);

/*
 * Adapted from Symfony AI AmpToolExecutor, commit 57961ae9:
 * https://github.com/ineersa/ai/blob/57961ae9/src/agent/src/Toolbox/AmpToolExecutor.php
 *
 * Copyright (c) 2025-present Fabien Potencier
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace App\Chat;

use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\UpdateInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * Temporary upstream copy: replace with Symfony AI's executor when released.
 *
 * @author Illia Vasylevskyi <ineersa@gmail.com>
 */
final readonly class AmpToolExecutor implements ToolExecutorInterface
{
    public function __construct(private ToolboxInterface $toolbox)
    {
    }

    /**
     * @param ToolCall[] $toolCalls
     *
     * @return \Generator<int, UpdateInterface, mixed, ToolResult[]>
     */
    public function execute(array $toolCalls): \Generator
    {
        foreach ($toolCalls as $toolCall) {
            yield new Progress(Progress::STAGE_TOOL_CALL, \sprintf('Executing tool "%s".', $toolCall->getName()), $toolCall);
        }

        $futures = [];
        foreach ($toolCalls as $toolCall) {
            $futures[] = async(fn (): ToolResult => $this->toolbox->execute($toolCall));
        }

        [$errors, $results] = awaitAll($futures);
        if ([] !== $errors) {
            throw $errors[array_key_first($errors)];
        }

        ksort($results);

        return array_values($results);
    }
}
