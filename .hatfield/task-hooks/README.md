# Finance workflow setup

Use the published `ineersa/hatfield-ext-task-workflow` package (v0.0.50 or newer)
and its installed `task-workflow` skill for workflow behavior and setup. The
extension's self-contained README and templates are installed alongside that skill.
Do not use the former external filesystem board for new work.

## Local configuration

Hatfield settings, extension manifests/locks, managed skills and runtime data remain
local and ignored. Only these project-owned hooks and the shared issue templates
are versioned. Preserve existing settings and unrelated extensions when upgrading:

```bash
vendor/bin/castor app:composer -- --working-dir=.hatfield/extensions require \
  'ineersa/hatfield-ext-task-workflow:^0.0.50' --with-dependencies --no-interaction --no-scripts
```

Merge the following into `.hatfield/settings.yaml`, retaining existing enabled
extensions and their settings. Reuse the account's complete **Hatfield Status**
field; do not create a duplicate Workflow field or change other issue fields.

```yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
  settings:
    task_workflow:
      repository: ineersa/personal-finance-agent
      remote: origin
      base_branch: main
      worktree_root: ../personal-finance-agent-worktrees
      workflow_field: Hatfield Status
      merge_method: merge
      hooks:
        setup:
          script: .hatfield/task-hooks/setup.sh
          timeout_seconds: 900
        validate:
          script: .hatfield/task-hooks/validate.sh
          timeout_seconds: 1800
        cleanup:
          script: .hatfield/task-hooks/cleanup.sh
          timeout_seconds: 300
```

Git, authenticated `gh` with issue-artifact support, the project's prepared tooling
runtime and its Composer-locked Castor are prerequisites. See `TESTING.md` for
runtime preparation. Reload via a fresh Hatfield process, then deliberately run
`/tasks-init`. Existing issues are sufficient for its read-only artifact probe;
do not create demonstration production tasks. Initialization is not a lifecycle
or browser test. Normal task state and specifications live on GitHub, not in
local history or PR metadata.

## Project-owned lifecycle adapters

These Bash files are invoked by the extension, not parallel user-facing task
runners. They delegate dependency management, validation and stack lifecycle to
existing Castor tasks; they do not introduce another Docker or QA runner.

- **Setup:** in a newly owned worktree, bootstrap `vendor/` from the primary
  checkout, then run `tooling:prepare` and the native Composer task to reconcile
  locked application/extension dependencies. Copy the primary's ignored local
  settings and extension manifests only when the destination is absent. Existing
  files and symlinks are never replaced. Do not copy `.env.local`, credentials,
  uploads, financial databases or observational-memory storage. These are real
  operational workspaces, not test-fixture configuration; do not run this hook
  against fixture directories using real project settings.
- **Validation:** the single combined gate is `vendor/bin/castor check`. Hatfield
  runs it in the committed worktree at review and in the primary after merging,
  updating the base and removing the task worktree at completion. Failure must
  remain a failure. Read `var/qa/*/result.json` and bounded diagnostics, not raw
  financial/provider payloads. Observe the existing browser-only testing policy
  and obtain permission before any additional special-case coverage.
- **Cleanup:** from the exact owned worktree, run `browser:stack:stop` and
  `dev:stack:stop`. Both use the existing checkout-scoped lifecycle. Do not stop
  the shared proxy, remove persistent data or manually delete Git worktrees.
  If the checkout or its locked Castor is missing, cleanup explicitly refuses
  and keeps recovery pending: recover the owned checkout/tooling before retrying
  instead of guessing container identities or claiming success. Never redirect
  cleanup to the primary or the preserved checkout of another issue.

The primary checkout supplies hook source even when cleanup runs there. Hatfield
passes cwd to the Bash subprocess; it does not change the agent's cwd. Setup and
cleanup do not run the combined gate. Cleanup leaves Git removal to the extension.

Owner review remains required; merge only with explicit authorization. Preserve
the project's pre-merge signing checkpoint. No background task or retained
worktree should be resumed, adopted or deleted merely to initialize this workflow.
