# ADR 0020: Store statement files privately through Flysystem

- Status: Accepted
- Date: 2026-10-31

## Context

Statement bytes need persistent local storage outside the web root. The owner selected Flysystem with Symfony integration. Application access must verify the statement and source owner, without an administrator bypass.

## Decision

Use `league/flysystem-bundle` and its local adapter. Configure `statements.storage` with private file and directory visibility. Store bytes outside `public/` and metadata in the shared SQLite database. Use a random 128-bit hexadecimal key, independent of the original filename. Do not provide public storage URLs or symlinks.

`StatementStorage` accepts a trusted user and source when writing, and a trusted user and statement when reading or deleting. It checks both ownership relationships, denies administrative users, and rejects stored keys outside the fixed hexadecimal format. Callers must resolve user and statement identifiers from trusted application context. Future workers must resolve records again, not accept a model-supplied key, path or URL.

The finance-workspace voter gates the authenticated download route; the owner-scoped repository resolves both statement and source ownership before reading bytes. The statement list and detail page link to this route. Strip path components from the original filename and let Symfony's `HeaderUtils::makeDisposition()` quote and encode it, using `statement` as the ASCII fallback. Return `application/octet-stream`, `nosniff`, and private non-storable caching. Ignore filename, path and URL query parameters. Missing files and invalid stored keys return 404, as do foreign or unknown statement identifiers. Administrative users receive 403 before record lookup.

### Storage and container isolation

Production stores bytes in `/app/var/data/statements`, alongside SQLite metadata in the existing project-owned `app_data` volume mounted at `/app/var/data`. Never persist all of `/app/var`: a reused volume would obscure the rebuilt image's compiled Symfony cache with stale route and service definitions. Cache and compiled assets stay in the image, outside the data mount. Future processing workers must mount the same data volume, use the same storage configuration and run with compatible filesystem permissions. Do not expose this volume to a converter; converter staging remains task-specific work under ADR 0016.

Owner review on 2026-11-01 corrected the initial whole-`var` mount before merge. This correction does not migrate or delete any existing volume. Preserve any previously created experimental volumes; moving owner data requires a separate verified migration.

`STATEMENT_STORAGE_DIR` configures the private root, defaulting to `%kernel.project_dir%/var/statements` in `.env` and `%kernel.project_dir%/var/data/statements` in `.env.prod`. Development overrides the volume list with the checkout mount. The test environment fixes storage at `var/browser/statements`, regardless of that environment variable. The live browser stack replaces the entire `var` directory with `var/browser-live`, so its files and database remain separate from development and intercepted tests. Cache clears do not remove statement storage.

### Lifecycle contract for the upload workflow

Task 07 owns upload validation, size limits, quota admission and metadata persistence. `store()` accepts only a server-side Symfony `File` after that admission and returns an unpersisted `Statement` with detected MIME type, byte count and SHA-256. The original filename is display metadata, not an authorization or duplicate-import key.

Run storage operations outside database write transactions. A failed storage write attempts to remove its possible partial file and closes the input stream. If cleanup fails, propagate that failure rather than report success. After a successful write, the upload caller must commit metadata in a short transaction. If that commit fails, call `delete()` with the returned statement after rolling back. Coordinate quota reservation settlement in task 07; storage does not implement another quota counter.

Do not delete bytes before the metadata transaction commits a separately authorized removal. If later file deletion fails, retain enough information for an explicit retry. No delete UI, automatic retention, entity callback or background recovery framework is introduced here. Process termination between filesystem and database steps can leave orphaned bytes; this bounded compensation is not an atomic filesystem/database transaction or crash-recovery guarantee.

### Synthetic fixtures

The native Alice `StatementFileProcessor` creates canonical synthetic CSV bytes for the statement definitions in `fixtures/statement.yaml`, in both development and tests. It fills the corresponding size, hash and storage key before persistence. Stable fixture-specific keys avoid accumulating files on repeated isolated fixture loads. Demo filenames are ordinary synthetic statement names. No personal bank file is committed.

Existing metadata-only fixture rows are not converted into real uploads by a database migration. Their old keys have no bytes and download as unavailable. Do not purge development data to replace them.

## Alternatives considered

- Public files or direct storage URLs would bypass application ownership checks.
- Original filenames as keys would mix untrusted display metadata with filesystem addressing.
- A general storage or recovery framework is unnecessary for the selected local MVP.
- Implementing uploads here would duplicate task 07. The owner explicitly kept storage and authorized download in task 06 and deferred upload and failed-write browser proof to task 07.

## Consequences

Downloads can be exercised through the browser before the upload workflow exists. Browser regressions cover canonical bytes, ownership denial, path-like request inputs and private-root separation. The owner deferred hostile uploaded-filename coverage to task 07 so the shared demo fixtures do not contain attack strings. These regressions do not establish upload admission, hostile uploaded-filename handling, failed-write compensation, worker sharing or live-server file privacy. Verify those boundaries through their actual workflows rather than a test-only endpoint.

The local adapter assumes the configured private root and its contents are controlled by the application, not an attacker with filesystem write access. Mounting storage into future workers and converters requires separate verification.

## References

- [Flysystem bundle configuration and injection](https://github.com/thephpleague/flysystem-bundle/blob/3.x/docs/1-getting-started.md).
- [Symfony streamed responses](https://symfony.com/doc/current/components/http_foundation.html#streaming-a-response).
- [Symfony file upload trust boundaries](https://symfony.com/doc/current/controller/upload_file.html).
- [Symfony deployment and cache warmup](https://symfony.com/doc/current/deployment.html).
- [Docker volume persistence and mount masking](https://docs.docker.com/engine/storage/volumes/).
