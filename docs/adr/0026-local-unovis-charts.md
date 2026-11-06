# ADR 0026: Local interactive Unovis charts

- Status: Accepted
- Date: 2026-11-05
- Supersedes: [ADR 0014](0014-inline-chat-and-antv-charts.md)
- Amended by: [ADR 0029](0029-native-chat-store.md), moving private-chart membership from duplicate turn JSON into linked application metadata. Existing chat rows are discarded, not converted; storage bytes are not purged by migration.

## Context

The owner selected interactive charts instead of AntV's hosted static images and explicitly approved execution of the packaged chart runtime inside a restricted iframe. Unovis generates self-contained HTML locally; its MCP tools return a server-local filename rather than an authenticated browser URL. The application must retain output with the trusted conversation without exposing server paths or allowing model-selected filesystem destinations.

## Decision

Keep the single chat, without an artifact entity, gallery, editor or MCP Apps host. Use the Unovis MCP HTTP service internally with no host port, Docker socket, database or credential mounts. Disable runtime font downloads with `UNOVIS_MCP_NO_DOWNLOAD=1`. Pin the owner fork at `e57001218dd00995aa13331bf731bd0bb4644fb9`; [upstream PR 986](https://github.com/f5/unovis/pull/986) fixes HTTP handler forwarding with current MCP SDKs. Build Node 24.22.2/pnpm 10.23.0 with frozen filtered dependencies and `pnpm build:mcp`, preserving the bundled widget assets and workspace dependencies. Do not run emitting TypeScript compilation afterward: it overwrites the bundled bootstrap.

Reuse native Symfony AI `ClientToolset`/`McpToolbox` discovery, schema conversion, forwarding and events. A thin composition adapter supplies only application-owned presentation and storage: forced HTML output, application-generated UUID output paths, private retention and normalized success text without server paths. The owner clarified on 2026-11-05 that the tool owns all chart argument validation, including schemas, titles, labels, values and dimensions. Remove application argument/count quotas and no-retry restrictions; ordinary punctuation is not rejected by an application regex. Model-supplied output type/path are overridden, not validated. Retain ADR 0025's SQLite calculation semantics.

Use a dedicated Compose-project-scoped chart output volume shared only with PHP/worker. The model cannot select output paths; import only the application's known regular, non-symlink file under `/chart-output`, capped at 2 MiB. Delete staging output after each call. Keep retained documents in existing private Flysystem conversation storage, referenced by existing turn JSON. No migration or generic artifact subsystem is needed. Existing retained PNG/JPG charts remain readable; do not remove owner history during the swap.

The pinned server separately registers `get_unovis_info` with an empty schema and a text-only result. Pass that named tool directly through the existing MCP client, without injecting output arguments, allocating a staging path or requiring a file. Keep native discovery and native error handling; do not hide it merely with a prompt prohibition. This narrow distinction does not introduce another tool catalog or duplicate generator schemas. Visualization guidance may recommend a chart when it materially helps the question, not only when explicitly requested. The existing generator path and sandbox boundary are unchanged.

Serve documents through the existing finance-access, conversation-owner and retained-file membership checks. Embed using `sandbox="allow-scripts"` without same-origin, popups, top navigation or downloads. Independently apply response CSP sandboxing, denying external connections, frames, objects, forms and base changes. Inline packaged scripts/styles and data images are permitted; never inject the document into the parent DOM. Model input is chart data/options, not arbitrary executable JavaScript. Authenticated delivery and execution isolation are separate boundaries.

Wrap the complete native ChainToolbox in Symfony AI FaultTolerantToolbox, matching the bundle default and upstream examples. Native McpToolbox converts isError results to tool execution exceptions, emits ToolCallFailed, and the outer native decorator returns error feedback to the model so it can correct and retry. No custom retry loop is introduced. Preserve server text errors and transport feedback while replacing the application-selected output path; retention failures use a safe storage-specific message. Success attaches only the retained file reference and does not put HTML into model history. Other server error text is trusted as diagnostic feedback from this private local service, not sanitized as arbitrary prose; no additional application logger is introduced. Existing conversation deletion and explicitly authorized demo reset remove retained files. No recovery subsystem is selected; interruption or failed best-effort staging cleanup may leave inaccessible staging/orphaned bytes. Cleanup must not overwrite actionable tool feedback with a private-path warning. Chart service logging remains disabled to avoid raw financial arguments. Local chart generation removes the external-renderer disclosure, not disclosure of financial context sent to the chat provider.

## Alternatives considered

- Keep AntV static images: rejected by the owner's approved interactive swap.
- Downgrade MCP SDK to 1.24.3: proved HTTP compatibility but carries security advisories; rejected.
- Add a transport bridge or per-chat process/container infrastructure: unnecessary with the narrow upstream HTTP fix.
- MCP Apps host or custom browser renderer: not selected; the packaged self-contained document suffices.

## Consequences and verification

The service must retain its built workspace dependencies. Fork maintenance is temporary until an appropriate official release includes the fix. The inherited production workspace audit reported 2 high, 10 moderate and 4 low advisories (high findings in Zod/Joi, not the SDK); this decision does not claim an audit-clean dependency tree or authorize unrelated dependency upgrades.

Throwaway HTTP/stdio generation and a real sandboxed browser verified three synthetic bars and hover tooltips with no observed external requests or console errors. That is not a comprehensive security audit or Finance application integration proof. The saved Finance chart journey must verify actual worker/Mercure delivery, private retention, hover and reload on isolated storage. Unsafe-input/failure coverage and stale inference recordings remain distinct verification gaps; never fabricate provider frames. See [TESTING.md](../../TESTING.md).
