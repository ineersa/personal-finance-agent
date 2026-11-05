# ADR 0028: Native SQLite full-text chat search

- Status: Accepted
- Date: 2026-11-05
- Amends: ADR 0027's search exclusion only.

## Context

The owner requested chat search and approved native SQLite FTS5 after the isolated application runtime reported SQLite 3.40.1 with ENABLE_FTS5. Installed Doctrine DBAL's SQLitePlatform generates ordinary tables, not FTS virtual tables. A replacement platform or schema generator would be disproportionate.

## Decision

Use a native FTS5 index in the central database over only existing readable first-question titles, questions and answers. Do not index native history, tool arguments/results, reasoning, signatures, chart references or credentials. Bind literal Unicode words joined with AND, not user-supplied FTS grammar. Join every match back to the authoritative owner-scoped chat table, covering saved and temporary chats. Provide a simple sidebar GET search, direct result links, up to twenty matches and bounded escaped plain-text snippets. Unicode61 tokenization supports ordinary Unicode words without adding a language-specific stemmer.

SQLite triggers synchronize admission, persisted terminal answers and deletes (including pruning and native fixture deletion); running streaming projections do not reindex each token. Migration backfills retained readable projections. No async indexing job, index recovery service, external engine or dependency is introduced. Short existing write transactions include their native trigger work. Existing conversation history is not rewritten.

The owner explicitly approved a **narrow migration exception**: generate the migration skeleton through Doctrine using Castor and verified isolated diagnostic storage, then hand-author only FTS-specific creation, backfill, synchronization and drop SQL. Review and migrate/schema-validate that isolated database before preview application. This does not change the repository's general generated-migration rule. Native per-connection schema_filter excludes the virtual index and its shadow tables from Doctrine diffs. Future conversation-table rebuild migrations must preserve/recreate the index triggers; Doctrine does not model them.

The owner selected five English real-model synthetic demo recordings, favourites-first order, exported as shared canonical central Alice fixtures. The Ukrainian recording is excluded at the owner's request. Export only explicitly selected completed chart-free IDs from isolated browser storage, preserving native history and readable output, but not connection identity or credentials. Export does not modify original recorded rows or restore deleted rows. Provider signatures are preserved losslessly; continuation with different credentials is unverified and no conversion workaround is introduced. Review native metadata before committing fixture output.

Use the existing Castor browser-stack console to invoke `app:demo:export-chats` with exactly five reviewed IDs in the desired . The isolated stack mounts source read-only, so the command writes `var/demo-chat-export.yaml` (host `var/browser-live/demo-chat-export.yaml`); review and copy it to `fixtures/central/chat.yaml`. Native Alice interprets DSL tokens inside model strings. A whole-array `var_export` identity expression also fails because its FunctionTreeTokenizer scans delimiters without respecting PHP quotes. Therefore history and turns use native Alice identity expressions decoding base64 JSON arrays: only controlled function names and encoded data appear in expression source, never executable model text. Readable question/provenance comments identify recordings. This is fixture representation, not a new provider, fixture loader or history converter. Native fixture loading on fresh disposable diagnostic storage was compared with the five original SQLite rows: all history/turn structures match exactly, including signatures, intermediate failed SQL and successful retries. Metadata keys were limited to finish_reason/token_usage; no credential/header-bearing keys were found.

Sidebar forms and navigation retain EasyAdmin's native full-page lifecycle, not Turbo Drive opt-ins. Installed EasyAdmin initializes its action-confirmation listeners only at DOMContentLoaded; replacing the page through Drive bypasses that initialization. Delete triggers are type=button so unavailable JavaScript cannot accidentally submit deletion; native EasyAdmin submits the containing CSRF-protected form only after modal confirmation. Keep the existing composer Turbo opt-in and in-place transcript/sidebar-state streams; morph stable initialized buttons rather than replace the sidebar. Browser tests reuse the native form-submission bridge helper and ordinary navigation, not application behavior added to satisfy the harness.

## Alternatives considered

- LIKE scans would not provide the requested native full-text index.
- An external search service adds deployment and synchronization infrastructure for a small local demo.
- A custom Doctrine platform/schema generator is larger than the explicitly approved SQLite-specific migration exception.

## Consequences

FTS5 must be available in the deployed SQLite build. Search is literal word matching, not arbitrary query syntax, fuzzy matching, substring search, semantic search or a language-specific stemmer. Tokenization treats punctuation as separators. Search result snapshots update on search/navigation, not live per token. Database backups include the search index, which contains synthetic or owner-readable finance prose and must receive the same protection as conversations. No special-case test matrix is authorized by this ADR.

## Owner-approved persistent-chat redesign (2026-11-05)

This decision supersedes the temporary/Save/inline-search portions above: retain every chat automatically, never prune on creation, and replace Save with reversible Favourite/Remove favourite. Existing `saved` database values map to the domain favourite property; no data rewrite or migration is needed. New is a plain compact compose link to an unstored draft; the first admitted message creates the conversation. Existing empty rows remain untouched. Favourites and other Chats form nonduplicate lists with independent five-entry Show more disclosures and selected-extra visibility. Remove retention icons/tooltips and inline search. Search uses native EasyAdmin Modal/Bootstrap dismissal, a small Stimulus shown-event autofocus listener with disconnect cleanup, and an internal Turbo Frame GET for existing owner-scoped FTS results. Result links and sidebar navigation use native full-page navigation to retain EasyAdmin confirmation initialization. Only existing selected composers opt into Turbo streams; draft first-submit uses a native POST redirect. Confirmed inactive-only owner-scoped deletion and native recovery are unchanged. The five English recordings remain lossless: four favourites, one ordinary chat.
