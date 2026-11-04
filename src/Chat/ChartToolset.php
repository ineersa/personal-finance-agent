<?php

declare(strict_types=1);

namespace App\Chat;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ChartToolset implements ToolsetInterface
{
    public const string NAME = 'antv';
    public const int MAX_CHARTS = 4;
    public const int MAX_ARGUMENT_BYTES = 16384;
    public const int MAX_ITEMS = 100;
    public const int MAX_TEXT = 200;
    private int $calls = 0;
    /** @var list<Tool> */
    private array $tools = [];

    public function __construct(private readonly ToolsetInterface $connection, private readonly ChartImages $images, private readonly string $conversation, private readonly NativeAssistantOutput $output)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getTools(): array
    {
        try {
            return $this->tools = $this->connection->getTools();
        } catch (\Throwable $error) {
            $this->output->charts[] = ['file' => null, 'error' => 'Chart service unavailable. No chart was generated.'];
            throw $error;
        }
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        try {
            if (++$this->calls > self::MAX_CHARTS || \strlen(json_encode($arguments, \JSON_THROW_ON_ERROR)) > self::MAX_ARGUMENT_BYTES) {
                throw new \DomainException('Chart request limit exceeded.');
            }
            $this->validate($arguments);
            $schema = null;
            foreach ($this->tools as $tool) {
                if ($name === $tool->name) {
                    $schema = $tool->inputSchema;
                    break;
                }
            }
            if (null === $schema) {
                throw new \DomainException('Unknown chart tool.');
            }
            // AntV validates with Zod, but forwards the original argument object to the renderer.
            // Refuse undeclared top-level options (notably overriding the renderer's chart type).
            $schema['additionalProperties'] = false;
            $document = json_decode(json_encode((object) $arguments, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
            $definition = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
            if (!(new Validator())->validate($document, $definition)->isValid()) {
                throw new \DomainException('Invalid chart arguments.');
            }
            foreach (['width', 'height'] as $dimension) {
                if (isset($arguments[$dimension]) && (!\is_int($arguments[$dimension]) || $arguments[$dimension] < 100 || $arguments[$dimension] > ChartImages::MAX_SIDE)) {
                    throw new \DomainException('Chart dimensions must be between 100 and 1600 pixels.');
                }
            }
            $result = $this->connection->callTool($name, $arguments);
            if ($result->isError || 1 !== \count($result->content) || !$result->content[0] instanceof TextContent) {
                throw new \DomainException('The chart renderer did not return an image.');
            }
            $filename = $this->images->copy($this->conversation, $result->content[0]->text);
            $this->output->charts[] = ['file' => $filename, 'error' => null];

            return CallToolResult::success([new TextContent('Chart image saved and displayed inline. Do not insert remote images or repeat this chart call.')]);
        } catch (\Throwable) {
            // Never project upstream exception messages/URLs or provider payloads.
            $message = 'Chart unavailable: the input was unsupported, a resource limit was reached, or the rendering service failed. No image was saved. Do not retry automatically.';
            $this->output->charts[] = ['file' => null, 'error' => $message];

            return CallToolResult::error([new TextContent($message)]);
        }
    }

    private function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new \DomainException('Chart input is too deeply nested.');
        }
        if (\is_array($value)) {
            if (\count($value) > self::MAX_ITEMS) {
                throw new \DomainException('Chart input has too many items.');
            }
            foreach ($value as $key => $item) {
                if (\is_string($key)) {
                    $this->validate($key, $depth + 1);
                }
                $this->validate($item, $depth + 1);
            }
        } elseif (\is_string($value)) {
            if (mb_strlen($value) > self::MAX_TEXT || 1 === preg_match('~[<>\x00-\x1f]|[a-z][a-z0-9+.-]*\s*:|//~i', $value)) {
                throw new \DomainException('Chart text must be short plain text without links or markup.');
            }
        } elseif (\is_float($value) && !is_finite($value)) {
            throw new \DomainException('Chart values must be finite.');
        } elseif (!\is_int($value) && !\is_float($value) && !\is_bool($value) && null !== $value) {
            throw new \DomainException('Unsupported chart input.');
        }
    }
}
