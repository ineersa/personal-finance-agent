# October walkthrough

## Scope

Owner-approved rehearsal: show sources/categories, upload the canonical October CSV, review and approve its drafts, ask for a category chart, then reopen that conversation through history and ask a short follow-up. Use the isolated browser stack, synthetic data, and real application UI. No special-case test matrix, resets, credential copying, or fabricated responses.

This is a reproducible shot list, not a verified browser script. Selectors and actual results must be established through the installed Playwright CLI. Do not treat proposed prompts as guaranteed model output.

## Preconditions

Read TESTING.md's isolated-live-stack procedure from the project root. Start the stack through `vendor/bin/castor browser:stack:up` and use its printed URL. Inspect existing storage before applying migrations or appending fixtures. Never reload fixture identities into populated storage or reset for a retake.

Use `demo@test.com / demo`. Verify the owner's ChatGPT connection through Settings; if absent, ask the owner to complete Continue with ChatGPT in this isolated stack. Do not copy authentication from the development instance or inspect tokens. Import also needs its configured provider and running worker/broker. Report missing access as a blocker.

Check whether the October statement is already present before uploading. A rehearsal changes data. If already approved, reuse it for the chat portion and report that upload footage requires a separately prepared authorized starting state; do not duplicate financial records just to record another take.

## Input

Relative to the project root:

`resources/demo-statements/credit/2026-10.csv`

Source: **Maple Harbour Rewards Visa · 4826**, CAD.

The canonical generator designates October 2026 as an upload holdout, with earlier months provided as history. Verify actual UI coverage for this stack. This CSV contains groceries, cafés, subscription, transport, shopping, other expenses, and a card repayment. Do not present this one account as complete household October coverage.

## Five scenes

1. **Overview:** briefly show populated Sources and Categories. No CRUD detour. Aim for about 7 seconds total.
2. **Import:** Statements → upload the CSV against the correct Visa source → wait for actual processing → inspect draft classifications → approve through the normal UI. Only correct a classification if actually needed; do not manufacture an error. Target about 21 seconds after editing.
3. **Connect ChatGPT:** show Settings and Continue with ChatGPT, cut across private authentication, then show the genuine connected state. Target about 6 seconds including the transition card.
4. **New chat:** submit the main question below, wait for the genuine completed answer/chart, and inspect readability and values. Target about 24 seconds after editing.
5. **History:** leave the conversation, open history, select that same conversation, verify its answer/chart persist, then submit the follow-up. Target about 19 seconds after editing.

Reserve about 6 seconds for an opening chart excerpt and 7 seconds for a closing hold. These are editorial targets, not measured runtimes. Cut long provider waits transparently; never imply unedited real-time performance when time was removed.

### Private authentication capture

The owner approved a dedicated visible browser with an isolated profile for this connection scene. This is not permission to use the personal browser profile or record credentials. Keep other scenes headless unless requested otherwise.

- Record Settings in the genuinely disconnected state. Stop recording before the OAuth navigation can expose account information. A pointer hovering over the button followed by a cut is sufficient; do not risk recording the provider page merely to capture a click.
- With recording stopped, let the owner complete Continue with ChatGPT and consent themselves. Do not inspect, snapshot, trace, or screenshot private authentication pages. Do not capture passwords, account details, authorization codes, or callback URLs.
- After the owner confirms return to the application, verify it is the ordinary Settings URL before resuming capture of its actual connected state. Keep any displayed account identity out of the final frame.
- Insert a short “Authorize securely in your browser” card between these genuine before/after clips. Do not fabricate success or imply the private step was omitted from the real workflow.
- If already connected, do not disconnect just for a retake. Reuse an existing safe before-state clip or show only the genuine connected state and adapt narration.
- Capture this connection scene before the first model rehearsal if the stack starts disconnected, even though it appears after import in the final edit. Do not copy credentials between stacks.
- Verify display access before asking the owner to authenticate. Close only owned sessions after the recording work; never interrupt an owner authentication step with cleanup.

### Main question (exact candidate)

> Where did my money go on my Rewards Visa in October 2026? Show spending by category as a horizontal bar chart, exclude card repayments, and briefly summarize the biggest expenses.

### Follow-up (same conversation, reopened through history)

> How does that compare with September on the same card? Just give me the biggest change.

Keeping this in the same conversation makes history retrieval and retained context visible. The follow-up should remain short and use the same account/scope. If ambiguous or too verbose in practice, report the observed result before revising the saved prompt.

### Narration candidates

Connection: “Connect your ChatGPT account, then start asking questions about your finances.”

New chat: “Once approved, those transactions are ready to explore. Ask where your money went, and get a breakdown with an interactive chart.”

History: “Come back to the conversation later and keep going—without explaining everything again.”

## Rehearsal evidence

Report whether import finished, review/approval worked, the chart was genuinely produced and readable, and history/follow-up retained the right context. Record approximate processing and response durations to inform editing. Inspect the actual answer for scope, currency, repayment exclusion, and agreement between chart and displayed figures; do not launch an extra validation matrix.

Keep screenshots/recordings and any private output outside Git. Close owned browser sessions on success and failure. Do not claim a full rehearsal passed if authentication, providers, or any scene is blocked.

## Current verification status

- CSV path, canonical source label, and October holdout designation checked in repository files.
- Full happy-path rehearsal succeeded on 2026-11-06 in the isolated browser stack: upload, real processing, review, approval, main question/chart, history reopen, and follow-up. Browser closed afterward.
- October statement 70 is now approved; do NOT upload it again for filming. Chat footage can reuse the retained results. A fresh import take requires an explicitly authorized isolated starting state, not duplicate ingestion or an unapproved reset.
- Exact main prompt produced CAD 1,087.85 across 16 expenses, excluding the CAD 1,130.94 repayment among the 17 imported rows. Chart rendered ten horizontal category bars; groceries CAD 421.57 and shopping CAD 177.66 were largest.
- Same-chat follow-up returned shopping as the largest category increase, CAD 74.62 (September CAD 103.04 → October CAD 177.66); overall spending increased CAD 46.01.
- Observed waiting: import completion within approximately 17 seconds of submission (UI processed timestamp was earlier); main answer about 83 seconds; follow-up about 43 seconds. Edit waits out transparently rather than budgeting real-time playback into 90 seconds.
- Main visually inspected chart and follow-up screenshots. Chart labels and bars are legible in the crop; Education/Transportation are not strictly value-sorted, and the longest category wraps. Full-frame final-video readability still needs verification.
- Explicit recording dimensions verified by main ffprobe: VP8, 1920×1080, 7.36 seconds. Artifact: `/tmp/pfa-video-14-media/rehearsal-1080.webm`. Default `video-start` had produced only 800×450 despite the larger viewport.
- Docker network exhaustion was resolved through owner-authorized removal of unused finance networks; isolated stack startup passed. This is not blanket permission for future pruning.

### Verified reproduction details

The CLI session's allowed upload root was its working directory `/tmp/pfa-video-14-media`. The owner explicitly authorized staging the canonical CSV there as `2026-10.csv`; main copied it with mode 0600 and verified identical bytes. Do not bypass an allowed-root denial; obtain authorization for staging or use properly configured access.

Headless normal clicks worked in the successful rehearsal (earlier headed clicks had stability timeouts):
1. Statements Search → `2026-10` → Enter; establish absence before upload.
2. Upload CSV → Source select → CSV statement chooser → staged file → provider-consent checkbox → Upload and prepare drafts.
3. Wait for Import complete → review all rows → Approve draft.
4. New chat → Your message → Send message → wait for Reply complete.
5. New chat → Search chats → question prefix → Enter → select result → follow-up.

Explicit-size recording uses the installed API through CLI `run-code`, not a separate browser runner (replace session/output path as needed):

```bash
playwright-cli -s=<owned-session> run-code 'async page => { await page.video().start({size:{width:1920,height:1080}}); }'
# Perform safe scene; no private authentication capture.
playwright-cli -s=<owned-session> run-code 'async page => { await page.video().stop(); await page.video().saveAs("/absolute/media/scene.webm"); }'
```

Refresh snapshots and confirm current controls before interactions; saved refs are not stable selectors. These are verified steps, not an unattended executable journey. Settings after connection can expose account identity: connected-state footage was skipped and needs safe framing/masking before capture.
