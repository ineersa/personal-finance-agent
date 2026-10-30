# ADR 0016: Convert PDF and XLSX statements locally with MarkItDown MCP

- Status: Accepted
- Date: 2026-10-29

## Context

Statement extraction needs document conversion without building a custom OCR engine or pipeline. The project owner selected Microsoft's MarkItDown and its official MCP server for local conversion, with financial extraction remaining an application responsibility.

## Decision

### Official converter and worker flow

Use official `microsoft/markitdown`, specifically `packages/markitdown-mcp`, running locally in Docker for PDF/XLSX conversion. Do not substitute the third-party `zcaceres/markdownify` wrapper. Parse CSV in PHP rather than sending it through this converter.

For PDF/XLSX, use the worker-driven flow: authorized upload → local document conversion through MCP → configured LLM structured row extraction → validated editable draft → user approval → approved transactions for analysis. Jev supplies categorization/decision tasks during draft preparation/review, not arbitrary-field extraction, as clarified in [ADR 0018](0018-finance-review-sql-and-jev.md). CSV is PHP-parsed without a mandatory LLM extraction step. Conversion output is intermediate document text, not validated financial records, and complex-table fidelity is not guaranteed.

Ordinary digital PDF/XLSX conversion uses local parser dependencies, not an LLM or mandatory API key. Subsequent AI-assisted processing follows its backend's data-transfer policy; local conversion does not make Jev or a hosted model local. The separate Jev backend in ADR 0018 does not expand the chat-provider scope in [ADR 0012](0012-configurable-ai-providers.md).

### OCR is not established by ordinary conversion

Do not build a custom OCR engine or pipeline from scratch. Ordinary conversion does not establish scanned/image-only PDF support or guarantee automatic/free OCR. An existing OCR plugin/backend would require explicit selection, configuration, and verification. Upstream optional vision/OCR or Azure integrations are not selected; neither Mistral, Azure, nor another paid OCR provider is adopted by this decision.

Scanned-PDF support and an OCR backend remain unresolved. This is not a product ban on all scanned uploads and must not be presented as universal OCR support.

### MCP boundary and safety

The official MCP server supports stdio and Streamable HTTP. Choose exact transport, package versions, dependencies/extras, and compatible APIs during bootstrap. Reuse Symfony AI MCP integration and the official PHP MCP SDK where compatible; this documentation does not establish compatibility.

A trusted worker resolves an owned statement into bounded, authorized converter input. Do not expose a generic file/URL conversion tool to the chat model. Give the converter only minimal read-only staging access, with no public unauthenticated service exposure. Bound conversion time, input size, and output size, and clean up staging resources. Exact integration and cleanup mechanics remain implementation details.

Treat uploaded content and converted text as untrusted data, not instructions. Keep ownership enforcement across upload, worker, conversion, and stored results. Do not log raw statements or conversions. Keep database write transactions short and outside converter/model calls.

## Alternatives considered

- Building OCR from scratch contradicts the owner's explicit preference to reuse existing software.
- Selecting a third-party wrapper or a paid OCR backend would go beyond the approved official local-conversion path.

## Consequences

- PDF, XLSX, and CSV are selected input types; supported banks/layouts, complex-table accuracy, scanned-PDF/OCR handling, and exact extraction mappings still require verification.
- Existing budgets, the shared one-active-AI-generation-per-user limit, temporary/saved history, and AntV inline charts remain unchanged. Conversion is not a route around AI admission or accounting rules.
- Verify actual converter/worker integration, known extracted values, ownership, hostile content, and no-text/error cases through headed browser checks in [TESTING.md](../../TESTING.md), using synthetic fixtures.
- No application code, runtime skill, MCP catalog, API/settings contract, container, dependency, converter call, or OCR integration has been implemented or validated by this decision.

## References

- [Microsoft MarkItDown](https://github.com/microsoft/markitdown).
- [Official MarkItDown MCP package](https://github.com/microsoft/markitdown/tree/main/packages/markitdown-mcp).
