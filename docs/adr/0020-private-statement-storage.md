# ADR 0020: Store statement files privately through Flysystem

- Status: Accepted
- Date: 2026-10-31

## Context

Statement bytes need persistent local storage outside the web root. The owner selected Flysystem with Symfony integration. Application access must verify the statement and source owner, without an administrator bypass.

## Decision

Use `league/flysystem-bundle` and its local adapter. Configure `statements.storage` with private file and directory visibility. Store bytes outside `public/` and metadata in the shared SQLite database. Generate stored filenames with Symfony UID's UUID v4, independent of the original upload filename. Do not provide public storage URLs or symlinks.

`StatementStorage` handles bytes and internal filenames, not authorization or statement metadata. `store(File $file): string` writes a server-side Symfony file under a generated filename. `read(string $filename)` returns a stream; `delete(string $filename)` removes the file. Both require a flat filename of at most 255 ASCII letters, digits, dots, underscores or hyphens, starting with a letter or digit. This excludes paths and URLs without requiring existing stored filenames to be UUIDs. Callers must authorize access before invoking storage. Future workers must resolve and authorize records again, not accept a model-supplied filename, path or URL.

The finance-workspace voter gates the authenticated download route; the owner-scoped repository resolves both statement and source ownership before reading bytes. The statement list and detail page link to this route. Strip path components from the original filename and let Symfony's `HeaderUtils::makeDisposition()` quote and encode it, using `statement` as the ASCII fallback. Return `application/octet-stream`, `nosniff`, and private non-storable caching. Ignore filename, path and URL query parameters. Missing files and invalid stored filenames return 404, as do foreign or unknown statement identifiers. Administrative users receive 403 before record lookup.

### Storage and container isolation

Production stores bytes in `/app/var/data/statements`, alongside SQLite metadata in the existing project-owned `app_data` volume mounted at `/app/var/data`. Never persist all of `/app/var`: a reused volume would obscure the rebuilt image's compiled Symfony cache with stale route and service definitions. Cache and compiled assets stay in the image, outside the data mount. Future processing workers must mount the same data volume, use the same storage configuration and run with compatible filesystem permissions. Do not expose this volume to a converter; converter staging remains task-specific work under ADR 0016.

Owner review on 2026-11-01 corrected the initial whole-`var` mount before merge. This correction does not migrate or delete any existing volume. Preserve any previously created experimental volumes; moving owner data requires a separate verified migration.

`STATEMENT_STORAGE_DIR` configures the private root, defaulting to `%kernel.project_dir%/var/statements` in `.env` and `%kernel.project_dir%/var/data/statements` in `.env.prod`. Development overrides the volume list with the checkout mount. The test environment fixes storage at `var/browser/statements`, regardless of that environment variable. The live browser stack replaces the entire `var` directory with `var/browser-live`, so its files and database remain separate from development and intercepted tests. Cache clears do not remove statement storage.

### Lifecycle contract for the upload workflow

Task 07 owns authorization, upload validation, size limits, quota admission, metadata extraction, `Statement` construction and persistence. After admission, pass the server-side Symfony `File` to `store()` and use its returned internal filename as `Statement.storedFilename`. The upload workflow supplies the owner, source, original filename, detected MIME type, byte count and SHA-256; storage does not inspect or construct this metadata. The original filename is display metadata, not an authorization or duplicate-import identifier.

Run storage operations outside database write transactions. Storage opens and closes the write input stream; the caller closes a stream returned by `read()`. A failed storage write attempts to remove its possible partial file. If cleanup fails, propagate that failure rather than report success. After a successful write, the upload caller must commit metadata in a short transaction. If metadata construction or persistence fails, call `delete()` with the returned internal filename after rolling back any transaction. Coordinate quota reservation settlement in task 07; storage does not implement another quota counter.

Do not delete bytes before the metadata transaction commits a separately authorized removal. If later file deletion fails, retain enough information for an explicit retry. No delete UI, automatic retention, entity callback or background recovery framework is introduced here. Process termination between filesystem and database steps can leave orphaned bytes; this bounded compensation is not an atomic filesystem/database transaction or crash-recovery guarantee.

### Synthetic fixtures

The native Alice `StatementFileProcessor` creates canonical synthetic CSV bytes for the statement definitions in `fixtures/statement.yaml`, in both development and tests. It fills the corresponding size and hash before persistence. Fixed UUID stored filenames in the canonical definitions avoid accumulating files on repeated isolated fixture loads. Display filenames are ordinary synthetic statement names. No personal bank file is committed.

The forward migration renames `storage_key` to `stored_filename` and its unique index without changing values or filesystem contents. Existing hexadecimal and synthetic filenames remain valid; existing bytes stay readable. Metadata-only fixture rows still have no bytes and download as unavailable. Newly loaded fixtures use their fixed UUID filenames; old files are not removed or rewritten. Do not purge development data to replace them.

## Alternatives considered

- Public files or direct storage URLs would bypass application ownership checks.
- Original upload filenames as stored filenames would mix untrusted display metadata with filesystem addressing.
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
