# ADR 0030: ChatGPT-only model and reasoning selection

- Status: Accepted
- Date: 2026-11-07
- Supersedes only the multi-provider **chat** scope in [ADR 0012](0012-configurable-ai-providers.md).
- Amends the fixed execution-time model/effort in [ADR 0024](0024-basic-chatgpt-worker-chat.md) and [ADR 0029](0029-native-chat-store.md), and narrows the chat history adaptation in [ADR 0018](0018-finance-review-sql-and-jev.md). Extraction decisions remain unchanged.

## Context

The owner selected ChatGPT only, requested model and supported reasoning-level controls, and asked whether Hatfield converts histories even between GPT models. The goal is minimal changes and maximal reuse of installed Symfony/Symfony AI facilities, not Hatfield's provider registry or runtime.

## Decision

Use native Symfony `EnumType` fields and backed enums for the ChatGPT models and wire-level reasoning efforts. Keep the closed composer small: just the question and bottom toolbar, with a literal **Model** chevron trigger beside Send. Native closed-by-default `details` opens a compact, dark/mint panel absolutely positioned above the composer, without an expanded top row or layout shift. Inside, stack the accessible native model dropdown and reasoning control vertically. Declarative Stimulus actions dismiss on outside click or Escape, returning focus to the trigger on Escape; Stimulus owns listener cleanup. Small declarative Stimulus behaviour progressively enhances the reasoning select into a native stepped range with a visible current label, supported-step labels and `aria-valuetext`. Its integer positions index only the selected model's supported efforts (GPT-6.1 Sol and GPT-6 Astra omit `none`), synchronizing the original enum field for submission. Retain the current effort when supported, otherwise select the model's first supported effort. The enum select remains the no-JavaScript fallback; native range keyboard handling needs no custom event listeners or widget dependency. Symfony Form validates enum membership and the model/effort combination server-side; admission also validates the combination. No credentials are form fields or queue payloads.

Offer only GPT-6 and higher, as selected by the owner. Curate their ChatGPT model/effort metadata from the read-only Hatfield `config/ai-catalog.yaml` (the `chatgpt` provider). Deduplicate its `minimal` alias of `low`; unsupported/null mappings are not choices. There is no universal cross-provider reasoning scale:

| Model | Supported wire efforts |
| --- | --- |
| `gpt-6.1-sol` | `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-6-astra` | `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-6-sol` | `none`, `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-6-luna` | `none`, `low`, `medium`, `high`, `xhigh`, `max` |

The existing `CHAT_MODEL` / `CHAT_REASONING_EFFORT` configure initial form defaults, still `gpt-6-luna` / `low`. Defaults must be a supported catalog combination; invalid configuration fails rather than silently choosing another model. New chats and older chats without selection metadata use these defaults. Persist the last submitted pair in nullable enum-backed `ChatConversation` metadata fields and restore it as native form initial data on navigation/reload. Child fields must not override supplied initial data with a hardcoded `data` option. A native OptionsResolver data normalizer fills only missing/null initial selections; POST enum/combination validation remains unchanged. Merely adjusting controls does not persist a preference. Within a selected chat, acknowledgement-only submissions leave the composer selection intact.

Capture both enums immutably in each `GenerateChat` message using the existing native PHP Messenger serializer. The handler uses those captured values, never current model/effort environment configuration. Credentials still resolve from the current trusted owner. Keep extraction independent, the existing per-generation native Agent/platform construction, native tool binding and direct Doctrine message store. The additive generated migration adds only nullable `model` and `effort` columns to `chat_metadata`; existing chats start null and no history is rewritten. Admission saves the validated pair in the normal short metadata flush before dispatch, for both new and existing chats. Queue messages remain immutable; metadata changes do not retarget accepted work. There is no preference table, session/localStorage setting or replacement message store. A failed dispatch may leave the last submitted metadata pair saved; no recovery subsystem or external-work transaction is introduced. Operator deployments must finish old-format queued chat jobs before switching application/worker code; no legacy-job fallback or queue purge is introduced.

### Request-only history compatibility

Hatfield `ConversationHistoryConversion` compares **exact qualified model identity**, not just provider identity. For other/unknown identities it strips Text signatures/native IDs and turns readable Thinking into text; its converter remaps tool-call/result IDs. Same-provider GPT switches are not exempt.

The installed `symfony/ai-agent`, `symfony/ai-platform` and `symfony/ai-open-responses-platform` are `v1.0.0-BETA1`; the ChatGPT bridge is `v0.3.1`. Inspection found:

- Native Agent loads/checkpoints the canonical input before its `InputProcessorInterface` processors. Only newly generated messages are merged back into the original input for tool-round/final saves. A processor can therefore supply a request-only view without rewriting stored history.
- ChatGPT `Contract/AssistantMessageNormalizer` replays signed Text through `MessageItem::fromText`, including native message IDs. Generic OpenResponses also replays the signed JSON Thinking item without a target-model check. Neither is automatically safe merely because both models are GPT.
- OpenResponses `Contract/ToolCallNormalizer` already emits only `call_id`, name and arguments, not provider-native function item IDs. Native ToolCallMessage uses that same call ID. Preserve these relationships unchanged; do not add remapping or a second converter.
- Native Agent gives each assistant the metadata of its own response round. `token_usage` implements `TokenUsageInterface` and exposes the provider-reported model. There is no reliable native requested-model identity for all historical messages, and providers may report a resolved snapshot rather than the configured alias.

Use one stateless native input processor to preserve a historical assistant unchanged only when its native usage model exactly equals the selected model. Otherwise create an unsigned request-only copy: Text keeps its text and phase (including phase retrieved through the native ChatGPT `MessageItem` helper); Thinking contributes only its readable summary as ordinary Text. Keep message IDs, metadata, ordering, all tool calls/results and canonical stored objects intact. No alias-stripping or guessed snapshot-equivalence mapping is added: missing/different/snapshot provenance is conservatively treated as unknown, even if it might actually be the same model. Existing fixture payloads/signatures remain byte-for-byte unchanged. New responses within the current native tool loop retain the native bridge behaviour.

No store decorator, custom serializer, request shaper, mutable shared current-model state, compactor or orchestration layer is added.

### Live connection feedback

The owner approved chat-only presentation safeguards for a disconnected live view: a compact accessible notice with a normal full-page reload link retaining the selected conversation, and a saved-history snapshot when the window regains focus, even while Mercure is disconnected. Reuse the existing catch-up controller, endpoint and deferred-render version guard. The notice follows the native source's `connected` attribute, not browser online status or inference status; hide it only when the native source is connected (or no live source is configured). A listener on the installed native EventSource also handles an initial error before a `connected` attribute existed. Remove that listener on controller disconnect; declarative Stimulus owns the chat-only window-focus action.

Native EventSource still owns transport and reconnects. This does not renew authorization, retry failed publication, resend inference, persist execution status or guarantee delivery of completion. A successful focus snapshot refreshes saved history but does not claim that live transport recovered. No polling, timer, retry framework, new saved special-case scenario or backend change is added. The owner separately authorized one isolated browser disconnect/reconnect diagnostic; source inspection is not its verification result.

## Alternatives considered

- Retain llama.cpp/z.ai/DeepSeek chat selection: superseded by the owner's ChatGPT-only scope; extraction is unaffected.
- Copy Hatfield's registry/converter/remapping: unnecessary with native messages and native OpenResponses tool normalization.
- Replay every signed item across all GPT models: not justified by Hatfield or the installed normalizers.
- Guess model aliases/snapshot identity or count context using invented estimates: not selected.

## Consequences and validation limits

Hatfield curates 272k context for all four selected GPT-6+ models. The installed native models/Agent do not provide a proactive tokenizer/context admission guard. The provider enforces its actual limit; a switch to a smaller model can fail visibly through the existing generation error path. Do not silently trim history or imply unlimited context. No proactive smaller-context compatibility claim or automatic compaction is made; the later task 23 remains separate.

The intercepted Playwright happy path uses the existing synthetic auth and native serialized in-memory Messenger transport to demonstrate supported options and immutable per-turn capture. It does not execute inference, consume a cassette, or establish provider acceptance of every model/effort or cross-model signed-history continuation. Real cross-process verification needs an isolated stack, worker/broker/Mercure and the owner's ChatGPT login there. Do not copy credentials from another checkout. Existing recordings must not be relabelled as multi-model proof.

ADR 0029's native streamed signed-Text integration gap remains; this change does not replace the result or persistence pipeline to repair it. Native package gaps are upstream candidates, not permission to invent replacement infrastructure.
