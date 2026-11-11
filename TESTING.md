# Testing

## Scope and approval policy

Keep the suite small: **one happy-path scenario per implemented view or action**. A single CRUD journey may cover its related list, create, detail, edit, and delete operations. Do not duplicate those operations in separate scenarios or hide extra cases in loops and data providers.

**Ask the owner for explicit permission before adding or running special-case tests.** This includes negative paths, boundary values, security matrices, fault injection, concurrency, recovery, and layout permutations. Fixing a bug does not automatically authorize another regression scenario. Database-reset tests remain excluded.

This policy replaces the earlier broad coverage requirements. It does not remove application authorization, validation, or privacy requirements. Do not claim that happy-path coverage proves those boundaries.

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects real Playwright browsers for application behavior. PHPUnit is the browser harness, not a separate unit, kernel, service, or API-only testing layer. Keep the existing harness and generic Castor tasks; do not add scenario-specific runners, orchestration, cross-run manifests, or supplementary datasets.

## Native-network suite

`FinanceNetworkBrowserTest` uses native Playwright against the isolated stack's real
HTTP URL and proxy address supplied by `browser:live:test`. It does not boot a kernel,
intercept requests, load fixtures or roll back database writes. Main prepares canonical
fixtures once using the existing append-only stack setup; tests never reset storage.
Each scenario opens and closes its own browser/context and signs in through `/login`.
Headed launches request a 2560×1440 window with native 1000ms slow motion and
`viewport: null`, allowing the native window content area without window-size assertions.
Headless launches use a 1920×1080 viewport without slow motion.

The non-provider journeys are AuthenticationTest, CategoryCrudTest, HomepageTest,
SettingsTest, SourceCrudTest and UserCrudTest. CRUD creates
uniquely named records and deletes only those records through normal confirmation;
Settings restores its original preferences in `finally`. The owner additionally approved
one invalid-currency validation step within the existing SourceCrudTest scenario; this
does not authorize other negative tests. These classes need no provider calls.

Statement download is covered only within the existing live import journey, after
approval and before deleting its own uploaded statement. It checks the visible filename,
authenticated download response, uploaded CSV checksum and attachment filename, then
clicks Download. Provider interactions use genuine reviewed recordings during replay.

Run only this slice with the existing runner (stack must already be prepared):

```sh
vendor/bin/castor browser:live:test -- --filter='AuthenticationTest|CategoryCrudTest|HomepageTest|SettingsTest|SourceCrudTest|UserCrudTest' tests/E2E
```

The obsolete intercepted base and application mocks have been removed. Default
`test` discovers both E2E and Live native-network journeys. See the precise
[old-to-new coverage inventory](#legacy-chatmodel-coverage-inventory) for coverage
boundaries. DemoWorkspaceTest and TransactionCrudTest were consolidated:

| Removed legacy coverage | Existing journey replacement |
|---|---|
| DemoWorkspace connection gate | UserCrudTest's newly created ordinary user: disconnected heading, Continue with ChatGPT and connection-required message before admin deletes only that user |
| DemoWorkspace history controls | Live ChatTest after its unchanged three recorded turns: new-chat navigation, own-chat favourite/unfavourite/favourite, bookmarked older-page cursor and Show latest, reopen/reload with saved reply/favourite state, code-word search/open, own-chat confirmed deletion |
| TransactionCrudTest | Live StatementImportTest's own imported row: approved list, edit amount/description, persisted amount/currency/description, detail and confirmed deletion, then original statement download/checksum/disposition and statement deletion |

History cursors come from read-only canonical favourite DOM IDs. The repository sorts
IDs descending and excludes the cursor; the test checks older canonical rows plus
the selected own chat appended outside that page. No fixed fixture UUID, extra chat,
canonical favourite mutation, provider generation or supplementary data is added.
Legacy literal `provisional` reply/search assertions now check the existing recorded
code word; the old fixed row counts are derived from the discovered sidebar rows.
Approved transaction coverage checks the imported USD row, not canonical CAD salary.
Validation results and owner walkthrough acceptance belong on the task board.

### Legacy ChatModel coverage inventory

| Original scenario | Native-network replacement and limits |
|---|---|
| `testChatCapturesEachModelAndReasoningSelection` | Three-turn `Live/ChatTest`: Luna/Low → Luna/High → Sol/High, real job admission/worker, remembered context and saved model/effort after reload. Also checks four choices and GPT-6.1's Extra High slider/range before restoring Luna/Low. The owner-selected three turns replace the old queued-envelope assertions; there is no GPT-6.1 inference claim. |
| `testMissingModelReturnsFormError` | Real CSRF-protected composer browser POST with only model name removed; HTTP 422 and visible composer. |
| `testMissingEffortReturnsFormError` | Same previously approved scope for absent effort; actual HTTP 422. |
| `testFocusPreservesActiveLiveCardAndCatchesUpWhenDisconnected` | Observes genuinely streamed active DOM, dispatches the existing declarative UI focus action and counts fetches without substituting responses. Reconnects the actual native custom element, checks its genuine CONNECTING state, quiet notice and snapshot request, then native reconnect OPEN and canonical snapshot catch-up. The observer is armed on the connected page before the existing follow-up, after the first answer completes; actual follow-up SSE is required before replacement, but remaining same-response tokens after replacement are not asserted. No fake EventSource, injected Turbo messages or artificial provider pacing. This is not an OS-focus or Firefox-abort reproduction. |
| `testBrowserSubmissionNormalizesForeignToolIdsWithoutRewritingHistory` | Native full-request matching checks Luna history retains the native item `id` on same-model requests and Sol/High cassette `b701934f1011…` strips it while retaining matching plain `call_id` in call/output. Chat reload retains the original composite tool ID and displayed history. Browser-visible history and real wire requests replace direct serialization assertions; byte-identical hidden metadata is not asserted. |
| `testFailedChartToolShowsErrorInStreamAndAfterReload` | Bounded canonical Visa query and one missing-field MCP chart request, genuinely recorded against the model and real MCP server. Incoming failed-tool Turbo markup is observed before rendering; disclosure/error arguments/result survive reload. The successful chart's separate SQL error is not mislabeled as MCP-error coverage. |
| `testRetainedChartRemainsVisibleAfterResponseFailure` | Withholds genuine final model cassette `8f95dea91f3d…` after the real successful chart tool result, restores in `finally`, waits for actual Failed status and checks the retained interactive chart before/after reload. No synthetic result/file/history or fake terminal event. Failure status itself is transient, not asserted after reload. Do not run concurrently with another replay suite. |

Refreshing the explicit-MCP capture (main only, verified task-owned recording stack and real
private grant required):

```sh
PROVIDER_CASSETTE_RECORD=1 vendor/bin/castor browser:stack:up
vendor/bin/castor browser:live:test -- tests/E2E/ChatModelTest.php --filter=testFailedChartToolShowsErrorInStreamAndAfterReload
```

Review and promote the genuine request-keyed captures privately, preserving originals.
Expected chain: real finance query → one chart with missing `missing_amount` field →
actual MCP validation error → model reports failure without retry. Model compliance
must be checked in every new recording. Restore replay with `PROVIDER_CASSETTE_RECORD=0
vendor/bin/castor browser:stack:up`, then `vendor/bin/castor browser:replay:prepare`.
Main validates changed scenarios and static QA; the transition runs the full gate.
Track validation and individual owner walkthroughs on the external board.

## Current suite

| Scenario | Happy path |
|---|---|
| Homepage | Public landing and login link |
| Authentication | Login and logout |
| Chat connection gate (within Users) | Own disconnected ordinary user's Chat view and connection action; no provider call |
| Settings | Save and reload reporting currency and money locale; verify localized money display |
| Sources | Create, list, edit, detail, delete |
| Transactions (within Live import) | List, detail, edit, reload and delete only its own approved imported transaction |
| Categories | Ordinary-owner create, list, edit, detail, delete |
| Users | Administrator create, list, edit email/password, verify login, detail, delete |
| Live import | Upload one canonical CSV, correct draft fields and classifications, save and continue editing, save and return, delete another row, approve remaining statement rows, reload corrections, view statement detail, verify and click the original CSV download, delete that uploaded statement |
| Live chat | Three recorded turns Luna/Low → Luna/High → Sol/High through the real worker/Mercure stack, reload, own-chat history/favourites/cursor/search and deletion |

Migrated CRUD/authentication views remain in `tests/E2E/`; real-network import, chat and chart journeys are in `tests/Live/`. Jev/chat HTTP uses genuine cassettes in default strict replay; the application, worker, broker, Mercure and local MCP remain real. Recording alone requires owner-managed provider authentication. Apart from the owner-approved source invalid-currency step and explicitly approved legacy chat regressions described below, the journeys do not cover other invalid submissions, repeat approval, ownership attacks, failure/retry, redelivery, quotas, hostile input or concurrent processing. Those require separate permission. Reset remains untested by owner instruction.

## Prepare the tooling runtime

Use Linux amd64, Docker, Docker Compose 2.24.4 or newer, Git, and host PHP 8.5 CLI with PCNTL/POSIX. Castor owns application runtime selection. Only owner-requested headed runs need display access and host `xauth`.

If `vendor/bin/castor` is missing, bootstrap the locked dependencies once:

```sh
 docker build --target frankenphp_dev -t personal-finance-agent-bootstrap .
 docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/app" --entrypoint composer personal-finance-agent-bootstrap install --no-interaction
```

Then use the host Castor entry point:

```sh
vendor/bin/castor tooling:prepare
vendor/bin/castor cs:fix
vendor/bin/castor test
vendor/bin/castor qa:static
```

`tooling:prepare` builds the development tooling image, installs locked dependencies and Chromium. Its disposable containers run as the host UID/GID. Private browser/tooling files live under ignored `var/tooling/`. Do not invoke another PHP runner around Castor.

## Running tests

`vendor/bin/castor test` prepares checkout-owned stack/migrations, initial append-only canonical fixtures and synthetic replay grant, then runs all E2E and Live native-network scenarios headlessly. Recording mode is rejected. Native PHPUnit arguments go after `--`:

```sh
vendor/bin/castor test -- tests/E2E/SourceCrudTest.php
vendor/bin/castor test -- --list-tests
```

Castor must not hardcode test filenames, method filters, or scenario selection. PHPUnit owns test discovery and selection. There is no per-scenario Castor task or custom test coordinator.

Use `test --headed` or `check --headed` only when the owner requests a visible run. Missing display access blocks a requested headed run; it does not justify silently changing modes.

Run `cs:fix`, relevant saved happy-path scenarios, and `qa:static` after code changes. The task workflow runs the literal `castor check` as the sole combined full browser/static gate at CODE-REVIEW. Do not run it prematurely during task-start. The default gate starts the real isolated application but never makes hosted model calls.

Report actual elapsed time, results, and any gaps. Do not inflate assertion counts or add test matrices. Routine validation must stay short; ask permission before expanding its scope or runtime. Run only against an idle checkout-owned browser stack: default preparation stops the owned worker, clears/rebuilds the test container, migrates/prepares fixtures and grant, then starts the worker before browser execution. This prevents long-lived workers retaining removed test service overrides. Preparation failure may leave the worker stopped; inspect the printed report rather than retrying providers.

## Fixtures and isolation

Both development and tests use the canonical `fixtures/central/*.yaml` account definitions and `fixtures/*.yaml` tenant definitions. Do not create a supplementary test dataset or mutate development data for verification. Manual currency-rate CRUD and its scenario have been removed; rates are maintained by canonical history and ECB refresh.

Native-network storage is isolated under `var/browser-live/browser/`: central identity,
tenant finance files, broker and chart documents belong to this checkout's stack.
The first bootstrap uses Doctrine migrations and native append-only canonical Alice
loading; existing storage is never purged. DAMA remains installed, but neither it nor
Alice transaction rollback isolates independent workers. Each journey deletes only its
own UI-created records; no kernel interception, synthetic session bypass or service
substitution remains.

The tenant redesign uses a clean fixture-backed development cutover, not an upgrade converter. Before resetting existing disposable dev/test databases, main must verify exact paths and active stack ownership. Do not delete uploads, OAuth credentials, unrelated volumes or another checkout's data. Fresh storage applies central migrations normally; fixture loading provisions tenant schemas automatically. For subsequent finance migrations, use `vendor/bin/castor app:console -- app:tenant:migrate` (or the matching browser-stack console). Generate finance migrations with `doctrine:migrations:diff --em=finance --configuration=config/migrations/finance.yaml` through Castor's isolated-database option, not against published tenant files.

Every journey signs in through the real login form. Filesystem cleanup is through
the application's owned deletion actions, never broad directory cleanup.

## Isolated live stack

Use checkout-scoped tasks and their printed URL. Do not invent ports, Compose project names, or routing environment variables.

```sh
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

For a fresh isolated stack, migrate and append the canonical fixtures once:

```sh
vendor/bin/castor browser:stack:console -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor browser:stack:console -- hautelook:fixtures:load --append --no-interaction
```

Do not rerun fixture loading over existing fixture identities or purge data to make it pass. Use `admin@test.com / admin` or `demo@test.com / demo`. Configure the provider using the existing development setup; do not put credentials in test commands or reports.

After changing worker code or streamed templates, run `vendor/bin/castor browser:stack:restart-worker` before the live journey. It restarts only this checkout's isolated browser worker, leaving the web preview, broker and storage intact.

For explicitly authorized abrupt-worker diagnostics only, `vendor/bin/castor browser:stack:restart-worker --force` sends SIGKILL to this isolated worker before restarting it. This is not a saved recovery guarantee: native Messenger redelivery may repeat work, and the application has no claims, attempt fencing, durable busy state or checkpoint-restart engine (ADR 0029). After an approved broker configuration change, with no active owned work, `vendor/bin/castor browser:stack:restart-broker` restarts only that broker and preserves storage; the current reservation is 90 seconds with 5-second worker keepalive. Neither command purges storage or queued jobs. Do not run crash-point or concurrency checks without explicit permission.

Before the chat journey, ask the owner to sign into this stack's Finance URL as `demo@test.com / demo`, then use **Continue with ChatGPT** and complete consent. Confirm the connected status in Settings. Do not start provider tests until that login is complete. The browser must run on the same host as the loopback OAuth callback.

Chat uses the package-managed store at `var/private/chatgpt-auth` inside the application, mapped to `var/browser-live/private/chatgpt-auth` in this isolated stack. Web and worker share those files and the same user registration-store identifier. OAuth attempts and subscription topics use the connection epoch. Chat jobs carry trusted owner/conversation identifiers and native input messages; the worker rechecks ownership and uses the current owner registration, not a saved conversation epoch. Disconnect retains registration for reconnecting. The committed public client ID and installation ID are shared across worktrees and users; grants and attempts remain isolated. Fresh stores receive only the configured registration through the native atomic store API; matching saved grants are unchanged and mismatched client registrations fail without a rewrite. Fresh-store authorization reuse and provider UI deduplication remain unverified; do not reconnect or copy credentials merely to test this configuration. There is no account-switch action. Do not copy credentials from another checkout, inspect token contents, or put credentials in fixtures, cassettes, commands or reports. The chat test sends synthetic text only; real provider requests occur solely in explicit recording mode. It requires access to `gpt-6-luna`; model registration alone does not establish entitlement. Missing login or model access is a failure, not a skipped/passing integration.

The editable system prompt is `prompts/chat.txt`, loaded by Symfony AI's file-based prompt processor. `CHAT_MODEL=gpt-6-luna` and `CHAT_REASONING_EFFORT=low` initialize the composer's model/reasoning defaults. Each submitted combination is validated and captured immutably in its queued `GenerateChat`; the worker executes that selection, not current environment defaults. Later UI or default changes cannot retarget admitted work. The last submitted model/effort pair is saved on owned conversation metadata before dispatch and restored by the form on navigation/reload. Unsaved control changes are not preferences. New chats and older chats with null selection fields use configured defaults. Apply the additive selection migration before serving this revision; canonical native messages are unchanged. Only provider-emitted readable summaries/commentary are displayed, so those sections may be absent. The worker binds the finance toolbox to the conversation owner and chart storage to that trusted conversation. Initial user-role context supplies the schema and `prompts/finance-context.txt` once before the first question; only real questions appear as visible turns. Progress comes from the native Agent execution and is not persisted as a separate state machine. The sanitizer still filters HTML and links, but its input-length limit is disabled for complete long replies; this removes its input-size denial-of-service bound.

Pages and reconnect snapshots render native history through Twig. Live response content comes from a handler-local native stream listener; tool cards render native progress calls/results directly. Streaming updates only the affected response/card/chart, then completion reconciles that user's reply from the execution's native messages. The initial turn is published only on the first native model-request progress event, after input persistence. Native conversation/user/tool identifiers anchor the DOM and tool-detail state; chart iframes keep stable IDs and existing morph updates without `data-turbo-permanent`, which enters the pinned Turbo cloning path. This is a presentation boundary, not a replay or recovery subsystem; source inspection alone does not prove live timing or iframe continuity.

Chat keeps native EventSource CONNECTING retries quiet. A terminal CLOSED source receives one native custom-element replacement; the reload notice is a last resort if that recovery also terminates. Intentional navigation is suppressed. This notice does not report inference failure. The shared focus/acknowledgement handler defers snapshots while the source is connected and a response has `data-chat-response-active`, preserving live progress before its canonical checkpoint. Disconnected focus still requests a snapshot; newer streamed updates invalidate stale snapshots. There is no polling, authorization renewal or inference resend. The focus regression checks these presentation boundaries, not the unresolved Firefox terminal-abort root cause.

Request-only foreign/unknown-model history conversion removes the ChatGPT bridge's native tool-item suffix from composite call IDs and copies only explicitly matching results in that converted round. Exact-model calls/results, unmatched/plain IDs and canonical history remain unchanged. Both model/effort enum fields use native `NotNull` validation alongside the existing supported-combination check and normal form-error response. These are source-level contracts, not claims that special-case browser checks passed; new negative/fault scenarios require owner approval.

The approved legacy ChatModel regressions now use real HTTP and local services.
Missing model/effort submit the actual CSRF-protected form. Focus exercises the
declarative UI action during observed real streamed progress, then the native source's
disconnect/reconnect lifecycle; no EventSource state or Turbo messages are fabricated.
It checks canonical catch-up and real follow-up delivery without artificial pacing.
Explicit MCP chart failure uses a genuine missing-field capture; incoming stream markup is observed before render and saved
details checked after reload. Retained-chart failure withholds the genuine final
model cassette and restores it in `finally`, exercising the actual fail-closed
provider boundary. Validation reports, not this description, establish passing runs.
Exact request/history ID coverage and limitations are recorded in the linked inventory.

Additional fault/recovery scenarios still require explicit owner approval. A fresh HTTP 200 is not proof of SSE delivery; report transport and UI outcomes separately.

Reporting examples and their supplied verification provenance live in [docs/examples/finance-reporting](docs/examples/finance-reporting/README.md). That reference directory is not loaded into the application. Use a new isolated conversation for the updated initial-context example; existing conversations receive the refreshed system policy but retain their initial snapshot. For one representative authorized reporting journey, ask an explicit-period/currency question and inspect SQL evidence, gross purchases/refunds/net labels, material coverage, a justified dark chart with matching aggregates and the final Markdown. Do not automatically execute the 12-query/six-chart reference collection as a live-model matrix. Source/schema review and supplied scratch-SQL results are not a live application pass. `get_unovis_info` now uses a text-only native MCP pass-through; generator output and private retention remain unchanged.

Prompt changes alter model request bodies, so existing strict provider cassettes may no longer match. Do not rewrite native provider frames to compensate; any fresh synthetic recording requires the normal explicit authorization and privacy review. Actual saved-suite and live outcomes must be reported separately.

Recording the current saved chat happy path requires access to both models: it asserts the initial `gpt-6-luna`/`low` defaults and completes the SQL-backed first reply, changes only reasoning to `high` for the second question, then changes only the model to `gpt-6-sol` for the third question. Both follow-ups check remembered context; reload checks model/reasoning selection and native tool/history persistence. Routine runs use strict provider replay and the synthetic grant described below, without provider credentials. The filtered command below excludes the existing whitespace special-case scenario; do not run that scenario without explicit permission.

Run the relevant live journey, then stop the owned stack when it is no longer needed:

```sh
vendor/bin/castor browser:live:test -- --filter=testStreamedReplyAndFollowUp tests/Live/ChatTest.php
# Separately, when validating imports:
vendor/bin/castor browser:live:test -- tests/Live/StatementImportTest.php
vendor/bin/castor browser:stack:stop
```

`browser:stack:stop` runs Compose `down` without `--volumes`, removing this stack's containers and private networks and unregistering its route. Named volumes and bind-mounted storage (including `var/browser-live`) remain intact, as do the shared proxy, its external network, and other stacks. It does not run a global prune or reset test data.

### Inline chart validation

`tests/Live/ChartTest.php` is the single chart happy-path journey: ask for September 2026 Rewards Visa spending by category in CAD as a horizontal bar chart, excluding repayments, without prescribing SQL or tool arguments. Expected category totals come from the existing canonical statement manifest and category definitions. The browser checks all chart categories/amounts, its private sandboxed document, a real hover tooltip, and the same document and tooltip after reload. Recording requires explicit `PROVIDER_CASSETTE_RECORD=1`, initialized task-owned fixture storage and the owner's ChatGPT connection; replay requires reviewed current provider cassettes and the synthetic native grant described below. The chart container starts with the normal stack; it has no host port. Local chart files live at `var/browser-live/browser/charts` for this stack and `var/charts` for development. New chat opens an unstored draft without deleting existing history or files. Only explicit confirmed Delete removes that conversation and its private charts; do not delete chats or reset owner history merely to prepare tests.

Build the pinned fork without affecting running services with `vendor/bin/castor charts:build --browser`. Once the operator confirms there is no active generation, `vendor/bin/castor browser:stack:up` deploys the changed chart service and PHP/worker mounts; no reset or fixture reload is needed for an existing isolated stack. The Compose-project-scoped `chart_output` volume is staging only, not retained conversation storage. Node creates ordinary readable documents there and PHP/worker can remove them through the writable directory. Verify actual cross-container permissions during the live journey, not just source inspection. Never remove this or other volumes to update the service.

```sh
vendor/bin/castor browser:stack:restart-worker
vendor/bin/castor browser:live:test -- tests/Live/ChartTest.php
# Task 13 explicitly requests visible proof; requires working display access:
vendor/bin/castor browser:live:test --headed -- tests/Live/ChartTest.php
```

The generic live runner defaults to headless; `--headed` supplies the existing display-enabled QA runtime and `PLAYWRIGHT_HEADLESS=false`. The chart scenario honors it. No chart-specific runner, dataset or test endpoint is introduced. The separate explicit-MCP-error regression covers tool validation feedback and model continuation; do not infer those checks from the chart happy path or source inspection.

Current recordings live under `tests/Fixtures/providers/`; the obsolete finance-only cassette is removed. Live provider proof must still be distinguished from provider replay; local chart requests and HTML generation are not wrapped by the inference cassette.

### Provider cassette recording and offline replay (task 33)

`PROVIDER_CASSETTE_RECORD` is the sole mode flag in the isolated browser stack,
forwarded to both PHP and worker. Both Castor test entry points read and validate this single variable from the running PHP and worker containers, reject missing, invalid or inconsistent modes, and pass the confirmed mode to tooling PHPUnit. The replay-only failure scenario rejects any mode other than confirmed replay before withholding bytes or submitting. Its default is `0`: strict offline replay.
`1` explicitly enables real ChatGPT inference and Jev HTTP recording. OAuth
uses the ordinary live client **only** in recording mode, and is never recorded.
In replay it receives a native `CassetteHttpClient(record: false)` with no real
client and a deliberately absent `tests/Fixtures/providers/oauth-unavailable.json`.
Never create that file: unexpected authorization, discovery or refresh must fail.
Local chart/MCP, broker, worker and Mercure remain real. The browser worker consumes only `statement_import` and `chat`, not `scheduler_default`. Browser finances use canonical seeded currency rates; scheduled ECB currency refresh is not covered by these journeys. Development and production worker commands are unchanged.
Jev receives a clearly synthetic key in replay; recording uses the configured key.

`ProviderCassetteHttpClient` selects a SHA-256 filename from method, URL, query and
body, then delegates final signature verification, recording and FIFO replay to
installed Symfony AI `HttpCassette`/`CassetteHttpClient`. Independent requests/jobs
can select their own recording; repeated identical requests within a job still
consume FIFO and fail when exhausted. Explicit `kernel.reset` tags clear clients
between Messenger jobs. Installed TypeSafe `ModelClient` retains the injected
client and calls `request()` directly, without cloning it. The adapter implements
native `withOptions()` merging against the complete supported option set, passing
only explicit options to avoid overriding the real client's defaults. Clones copy
existing native clients (and their cursor objects); the configured provider
services themselves are reset after each job.

Private captures are `var/browser-live/private/provider-cassettes/{chat,jev}`
on the host (`var/private/provider-cassettes/` in the container). Recording refuses
an existing capture for a newly selected key, rather than overwriting it. Capture
directories/files are created with modes 0700/0600. Reviewed replay locations are
`tests/Fixtures/providers/{chat,jev}`. Missing, exhausted or mismatched cassettes
fail without network fallback. Reviewed genuine recordings cover chat, successful
and explicitly failed MCP charts and import requests.

Only two nonsemantic replacements are permitted: the UUID-shaped conversation
`prompt_cache_key`, and the complete `Current date (UTC): YYYY-MM-DD.` header prefix
at the start of a line in top-level model `instructions`. The reporting currency
and remaining instructions on that line are preserved. The same native replacements select and verify
the cassette; live requests remain unchanged. No normalization of bare dates,
SQL, model/effort, prompts otherwise, schema, tool IDs/results, or finance values.
Compressed and stream/callback request bodies are rejected before networking.
No compression configuration has been changed. Native cassettes redact credentials
and personal request data; this is not proof that response bodies are publishable.
Main must privately inspect captures for account identity, tokens, private payloads
and unexpected data before promoting genuine bytes to the reviewed directories.
Promotion also removes opaque `x-codex-turn-state` and `x-oai-request-id` response
headers; request signatures and provider response bodies remain unchanged.
No semantic response editing or fabricated provider fixtures is permitted. Native
record/replay buffers provider responses: it does not reproduce live token timing.

#### Explicit recording (main only)

Use the already-initialized task-owned browser stack and owner-managed real sign-in.
Do not reseed it, inspect/copy grants, clear captures or remove storage. Model defaults
must match the selected saved journey (Luna/low → Luna/high → Sol/high).
Only run specifically authorized saved journeys with wholly synthetic input:

```sh
PROVIDER_CASSETTE_RECORD=1 vendor/bin/castor browser:stack:up
vendor/bin/castor browser:live:test -- --filter=testStreamedReplyAndFollowUp tests/Live/ChatTest.php
vendor/bin/castor browser:live:test -- tests/Live/ChartTest.php
vendor/bin/castor browser:live:test -- tests/Live/StatementImportTest.php
# Also restore strict replay after a failed recording attempt:
PROVIDER_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
```

These are activation/capture commands, not a substitute for checking run reports. ChatTest, ChartTest
and StatementImportTest now share FinanceNetworkBrowserTest: real login/HTTP,
headless by default, 2560×1440 headed window and 1000ms native action pacing.
Chat and chart journeys start at the empty `/home/chat` draft and confirm deletion
of only their newly created conversation after all existing assertions succeed.
The whitespace/delete-cancel case only reads an existing history entry, rejects
empty input and cancels deletion; it does not modify or delete that conversation.
Import retains its upload/edit/delete-draft/approve/download/delete workflow.
Failure cleanup closes browsers but conservatively retains newly created records:
the runner reports the captured conversation/processing URL for inspection, without
racing possibly active workers or retrying providers. Default routing selects all native-network journeys. Main coordinates execution
and private review/promotion; there is no automatic
promotion, regeneration, fallback or per-scenario runner. Scheduled currency refresh is excluded from this browser stack; no ECB provider recording is required.

#### Synthetic replay grant preparation

Replay uses the package's **real `AuthFileStore`**, not substituted AuthStorage.
The owner-approved fixture helper writes only the canonical demo user's configured
registration in `var/private/chatgpt-replay-auth`; normal recording keeps the
existing `var/private/chatgpt-auth` untouched. The helper boots the actual test
Kernel solely to look up the canonical demo fixture with Doctrine, requires its
existing ChatGPT registration identity, and writes synthetic access/refresh/JWT
values with configured client ID, native issuer/direct scope, synthetic subject
and nonce, and a fresh one-day expiry. The JWT payload is empty JSON, suitable for
the ordinary identity display path. No real grant is read/copied and no database
record is changed. Other users are not implicitly connected. This setup proves
neither OAuth sign-in nor credential refresh; unexpected OAuth HTTP fails offline.

```sh
PROVIDER_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
vendor/bin/castor browser:replay:prepare
# After main has reviewed/promoted current genuine provider recordings:
vendor/bin/castor browser:live:test -- --filter=testStreamedReplyAndFollowUp tests/Live/ChatTest.php
vendor/bin/castor browser:live:test -- tests/Live/ChartTest.php
vendor/bin/castor browser:live:test -- tests/Live/StatementImportTest.php
```

`browser:replay:prepare` executes `tests/Support/prepare_replay_grant.php` in this
checkout's browser PHP container via the existing Castor lifecycle helpers. It
refuses recording mode or a non-test environment. Repeat it when the synthetic
grant expires; it never contacts a provider. Consult task-board validation entries
for actual recording/replay outcomes and owner walkthrough acceptance.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. The browser test output is under `var/qa/test-browser/`, with JUnit at `var/qa/test/junit.xml`; live output is under `var/qa/browser-live-test/`. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures. See [ADR 0009](docs/adr/0009-castor-and-static-checks.md) and [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for tooling and bounded diagnostics. Do not suppress rules to make the gate pass.

Exploratory browser work uses the configured browser agent and system `playwright-cli`, with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, and provider data private and out of Git. Use synthetic financial inputs only. Static checks do not establish runtime behavior, and intercepted tests do not establish cross-process integration. Report only what was actually verified.
