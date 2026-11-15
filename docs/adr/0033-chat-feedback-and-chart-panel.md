# ADR 0033: Continuous chat feedback and a responsive chart panel

- Status: Accepted
- Stop amended by: [ADR 0034](0034-chat-stop-mailbox.md), allowing the owner-authorized shared mailbox and bounded control presence without replacing native history or cancellation.
- Date: 2026-11-13
- Supersedes: the inline-only chart presentation and temporary fork pin in [ADR 0026](0026-local-unovis-charts.md), not its private generation/storage/isolation design.
- Amends: presentation in [ADR 0029](0029-native-chat-store.md); native history, execution and transient-only status remain unchanged.

## Context

The owner reported silent periods after submission and readable reasoning, intermittent late final-answer display, and awkward oversized charts at the bottom of streaming conversations. The owner approved a conversation/chart split inspired by an artifact panel, independent scrolling for the first implementation, and responsive charts styled to match the application rather than fixed pixel dimensions.

Upstream Unovis shipped the HTTP transport fix by removing its obsolete capability sniff in PR 987, rather than merging our forwarding workaround in PR 986. The maintainer confirmed availability in 1.8; 1.8.1 is the current official release.

## Decision

Keep native Symfony AI progress and targeted Twig/Turbo updates over private Mercure. Give readable reasoning/commentary and tool calls a chronological activity disclosure distinct from answer text. Activity opens while running and collapses on completion; individual tool details remain collapsed by default and retain user expansion during updates. Do not display hidden reasoning or provider signatures.

Show immediate submission feedback, keep waiting feedback until active progress or a saved answer exists (a checkpointed question alone is insufficient), and retain an animated status at the end of the active turn. Phase labels reflect native model/delta/tool events. A browser-local elapsed clock makes longer pauses visible without inventing work, percentages, deadlines or durable execution state. Native result callbacks flush throttled tail content; canonical completion remains authoritative. This does not establish why an external provider paused or guarantee token-by-token output when no text deltas exist.

Round/content stream fragments carry the existing transient active marker themselves, allowing subsequent live content to restore activity after an initial start event missed around navigation or a replaced catch-up snapshot. Open each newly encountered live disclosure element once, tracking element identity rather than just its reused DOM ID; respect a manual collapse during subsequent updates to that same element. Canonical completion removes transient markers and renders collapsed history. Do not infer ongoing execution from saved history or introduce durable running state. The specific owner-reported occurrence has not been reproduced by fault injection.

### Model selector presentation and local default

Amend ADR 0030's literal Model trigger and no-localStorage decision. Show the selected model label, colored by its current reasoning effort, on the closed trigger. Use Hatfield's read-only `config/themes/cyberpunk.yaml` thinking palette: none `#4a5568`, low `#00ffff`, medium `#ff00ff`, high `#ff3366`, xhigh `#ff0000`, max `#9214fa`. Supply the palette through the existing reasoning enum/EnumType options, retaining one mapping for the trigger, slider track/stops/thumb and current label. Retain native keyboard controls and accessible level text; color is supplementary.

The existing Stimulus controller stores only the explicitly chosen model in localStorage under a named key and restores offered values on a new, unsubmitted draft. Existing conversation metadata and submitted forms remain authoritative; configured reasoning defaults are unchanged for new chats. Merely visiting a conversation must not replace the browser's preference. Unavailable browser storage leaves the ordinary controls/defaults usable. Store no credentials, financial data or provider payloads, and add no backend preference table or session mechanism.

### Chart panel

Each producing turn contains labelled chart buttons. A collapsible right panel displays the selected saved chart with a simple selector and independent scrolling. Without a panel the conversation remains centered. Every genuinely new chart URL opens the panel as soon as its link arrives during the response, without waiting for the final answer or transferring keyboard focus. Earlier opening/selection/closing does not disable later new-chart discovery. Track already-seen URLs so reconciliation of existing links does not undo deliberate closing or selection; only a new chart may automatically change that presentation. Narrow layouts use the same panel at full width with Back to conversation. This is presentation of existing conversation attachments, not a generic artifact entity, gallery, editor, MCP Apps host or synchronization subsystem.

Size the chart viewport from the available width with a compact 2:1 aspect ratio and a half-viewport height ceiling, not fixed pixel dimensions or the full conversation height. The panel hugs its chart content and retains scrolling when constrained. This also reduces oversized existing saved charts without rewriting their documents.

Keep iframe elements outside the streamed reply/snapshot region and reuse them while selecting charts or reconciling answers. Only visited charts create their iframe/image, with loading feedback. Preserve authenticated membership/ownership checks, script-only sandbox, no same-origin privilege and existing CSP/network isolation. Existing saved HTML and images remain readable. No chart-document migration or rewrite is required.

Pin official Unovis 1.8.1 at commit `002633e531a70cc27b88ebf68a4e48d1d4abbaad`, retaining Node/pnpm, frozen workspace build and no runtime font downloads. Use its native responsive HTML renderer. The application controls dark theme, the restrained Finance palette and a trusted presentation-only stylesheet inserted when retaining new documents. Omit model-selected width/height overrides so the native renderer and available panel space determine dimensions; do not add chart-data validation. Other semantic chart arguments remain tool-owned. Store an optional escaped display title alongside the existing chart file/message metadata; historical records use a generic numbered label. No schema migration or parallel transcript is needed.

## Alternatives considered

- Keep large inline chart iframes: rejected because charts move through the viewport as the conversation grows and compete with the answer.
- Synchronize chart/chat scrolling: deferred; independent scrolling is predictable and avoids unnecessary coupling.
- A generic artifact workspace or MCP Apps protocol: unnecessary for the existing private HTML documents.
- Add a second execution state machine or recovery machinery: rejected; this is transient feedback and native event presentation.
- Keep the temporary fork: unnecessary after the official HTTP fix.

## Consequences and verification

On 2026-11-13 the owner explicitly requested updating the project documentation after clarifying that execution of the established suite is routine authorized validation. The AGENTS/TESTING changes record that instruction: new special-case coverage or ad hoc special-case investigation still requires permission, but existing saved scenarios and standard gates do not require repeated approval. The owner also required an actual UI walkthrough and implemented-design approval before independent review; the live-preview instructions distinguish the normal dev preview from replay-only automated storage. These are owner-requested workflow clarifications, not permission to add special-case matrices or use the owner's preview for destructive verification.

Snapshot/reload rendering of an actively running exchange ending in a User or ToolCall message must retain the next native round's activity and answer content attach points. They use the same last-tool-call/user anchor as the native round stream; installed Turbo append deduplicates matching direct-child IDs. This lets subsequent real deltas attach after a missed round-start event without replaying or fabricating stream events. Completed, failed and cancelled history must not add a speculative pending round.

Chart presentation now belongs to the application while Unovis still owns rendering and argument validation. Existing saved documents retain their previous internal styling; new documents receive the Finance presentation without exposing chart HTML to the parent DOM. A loading event indicates document load, not a comprehensive chart-rendering or security audit.

Verify the existing native-network chat/chart happy paths with genuine provider recordings and the real worker, broker, Mercure and upstream MCP. Check activity expansion, progress delivery, side-panel selection/close, hover and reload. Source inspection and a 50ms publish interval alone do not establish streaming latency. Do not fabricate new recordings or add timing/fault/concurrency/layout matrices without owner permission. Actual outcomes and remaining upstream gaps belong in the issue QA summary.
