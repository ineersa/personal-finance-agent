<?php

declare(strict_types=1);

namespace App\Chat;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Adds conversation policy/file retention; discovery and execution remain native MCP responsibilities. */
#[Exclude]
final class ChartToolset implements ToolsetInterface
{
    public const string NAME = 'unovis';
    public const string ENDPOINT = 'http://charts:3737/mcp';
    public const string OUTPUT_TYPE = 'html';
    public const string PROMPT_LINE = 'Unovis chart tools are discovered from the local MCP service and save interactive HTML inline in this conversation.';
    public const int MAX_CHARTS = 4;
    public const int MAX_ARGUMENT_BYTES = 16384;
    public const int MAX_ITEMS = 100;
    public const int MAX_DEPTH = 8;
    public const int MAX_TEXT = 200;
    public const string GUIDELINES = 'Use the discovered chart tools only for requested visualizations of authorized SQL results. Calculate values in {finance_name}; do not invent or mentally calculate chart data. Rendering is local. Use outputType="{chart_output_type}"; never supply outputPath, executable code or external resources. Charts appear inline automatically; do not emit HTML, scripts, image Markdown or chart links. Do not repeat or automatically retry failed calls. Limits: {chart_max_calls} calls per response, {chart_max_bytes} bytes of arguments per call, {chart_max_items} items per collection, depth {chart_max_depth}, {chart_max_text} characters per string. Use plain labels without links, markup or control characters. Requested width/height, if present, must be integer pixels from 100 to {chart_max_side}.';
    private int $calls = 0;

    public function __construct(private readonly ToolsetInterface $connection, private readonly ChartFiles $files, private readonly string $conversation, private readonly NativeAssistantOutput $output)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getTools(): array
    {
        try {
            return $this->connection->getTools();
        } catch (\Throwable $error) {
            $this->output->charts[] = ['file' => null, 'error' => 'Chart service unavailable. No chart was generated.'];
            throw $error;
        }
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $path = $this->files->outputPath();
        try {
            if (++$this->calls > self::MAX_CHARTS || \strlen(json_encode($arguments, \JSON_THROW_ON_ERROR)) > self::MAX_ARGUMENT_BYTES) {
                throw new \DomainException('Chart request limit exceeded.');
            }
            if (\array_key_exists('outputPath', $arguments) || (isset($arguments['outputType']) && self::OUTPUT_TYPE !== $arguments['outputType'])) {
                throw new \DomainException('Only application-managed HTML output is supported.');
            }
            $this->validate($arguments);
            foreach (['width', 'height'] as $dimension) {
                if (isset($arguments[$dimension]) && (!\is_int($arguments[$dimension]) || $arguments[$dimension] < 100 || $arguments[$dimension] > ChartFiles::MAX_SIDE)) {
                    throw new \DomainException('Chart dimensions exceed their resource limits.');
                }
            }
            // The server validates its published recipe schema. It never receives a model-selected path.
            $result = $this->connection->callTool($name, [...$arguments, 'outputType' => self::OUTPUT_TYPE, 'outputPath' => $path]);
            if ($result->isError) {
                throw new \DomainException('The chart service did not generate a document.');
            }
            $filename = $this->files->retain($this->conversation, $path);
            $this->output->charts[] = ['file' => $filename, 'error' => null];

            // Do not expose server paths, generated source or model-controlled result text to chat/history.
            return CallToolResult::success([new TextContent('Interactive chart saved privately and displayed inline. Do not repeat this chart call.')]);
        } catch (\Throwable) {
            $message = 'Chart unavailable: the input was unsupported, a resource limit was reached, or the local rendering service failed. No chart was saved. Do not retry automatically.';
            $this->output->charts[] = ['file' => null, 'error' => $message];

            return CallToolResult::error([new TextContent($message)]);
        } finally {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
    }

    private function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
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
