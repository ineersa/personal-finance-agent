<?php

declare(strict_types=1);

namespace App\Chat;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Adds private file retention; schemas, discovery and execution remain MCP responsibilities. */
#[Exclude]
final readonly class ChartToolset implements ToolsetInterface
{
    public const string NAME = 'unovis';
    public const string ENDPOINT = 'http://charts:3737/mcp';
    public const string OUTPUT_TYPE = 'html';
    public const string PROMPT_LINE = 'Unovis chart tools are discovered from the local MCP service and save interactive HTML inline in this conversation.';
    public const string GUIDELINES = 'Use the discovered chart tools for requested visualizations of authorized SQL results. Calculate values in {finance_name}; do not invent or mentally calculate chart data. Rendering is local. The application manages outputType and outputPath for private inline HTML presentation. Follow each tool schema for chart options. If a tool returns an error, use its feedback to correct the call and try again when appropriate. Charts appear inline automatically; do not emit HTML, scripts, image Markdown or chart links.';

    public function __construct(private ToolsetInterface $connection, private ChartFiles $files, private string $conversation, private NativeAssistantOutput $output)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getTools(): array
    {
        return $this->connection->getTools();
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $path = $this->files->outputPath();
        try {
            try {
                // Chart options belong to the server; only presentation and the destination belong to us.
                $result = $this->connection->callTool($name, [...$arguments, 'outputType' => self::OUTPUT_TYPE, 'outputPath' => $path]);
            } catch (\Throwable $error) {
                return CallToolResult::error([new TextContent(str_replace($path, '[private chart output]', $error->getMessage()))]);
            }
            if ($result->isError) {
                // Preserve actionable server feedback, never the app-selected private output path.
                $messages = [];
                foreach ($result->content as $content) {
                    if ($content instanceof TextContent) {
                        $messages[] = new TextContent(str_replace($path, '[private chart output]', $content->text));
                    }
                }

                return CallToolResult::error([] !== $messages ? $messages : [new TextContent('The chart service returned an error without text details.')]);
            }
            try {
                $filename = $this->files->retain($this->conversation, $path);
            } catch (\Throwable) {
                return CallToolResult::error([new TextContent('The chart output could not be retained privately. No document was attached.')]);
            }
            $this->output->charts[] = ['file' => $filename, 'error' => null];

            return CallToolResult::success([new TextContent('Interactive chart saved privately and displayed inline.')]);
        } finally {
            if (is_file($path) || is_link($path)) {
                // Best-effort staging cleanup must not replace tool feedback with a private filesystem path.
                @unlink($path);
            }
        }
    }
}
