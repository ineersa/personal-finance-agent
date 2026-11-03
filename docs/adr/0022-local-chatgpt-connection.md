# ADR 0022: Connect ChatGPT locally before chat generation

- Status: Accepted
- Date: 2026-11-02
- Amended: 2026-11-03 after owner review; package-managed files replace application encryption.
- Supersedes: ADR 0012's optional-later scheduling for ChatGPT sign-in only.

## Context

The owner requested ChatGPT connection before task 11. OpenAI rejected the tested public HTTPS redirect URI with `invalid_authorize_request`, parameter `redirect_uri`; a loopback callback-only probe succeeded. OpenAI's [registration documentation](https://developers.openai.com/siwc/token-sharing-open-source/sign-in) requires an HTTP `127.0.0.1` callback.

The Finance hostname's session cookie is not sent to `127.0.0.1`. The owner chose to retain that hostname and approved a short-lived server-side handoff to a callback completion page. Subsequent review requested package-managed storage and less application infrastructure, accepting OS-permission-protected plaintext files for this local demo.

## Decision

Use published `ineersa/symfony-ai-openai-chatgpt-platform` v0.3.0 without local patches. The package owns authorization construction, PKCE, state/nonce/signature/account verification, registration, refresh and pending-refresh recovery, and remote revocation. Its `AuthFileStore` owns credential persistence, file locking, atomic mode-0600 replacement, and no-op update detection. Do not duplicate those facilities.

Inject the native untraced HTTP client, verifier, OAuth configuration, filesystem, lock factory and clock through Symfony. A small per-account factory supplies the package store and OAuth service. Normal application paths catch expected authentication errors, not arbitrary programming or database failures. Use an enum for connection statuses and constants for shared identifiers.

Credentials live under `var/private/chatgpt-auth` in private mode-0700 directories. They are plaintext protected by OS permissions, not encrypted at rest. The application cannot protect them from another process running as the same OS user. No encryption keys or key mounts are required. Keep credentials out of the repository, browser and diagnostic output.

Only the cross-host handoff remains application-owned. A CSRF-protected connect POST saves the initiating user ID, account-instance namespace, package request fields and five-minute expiry in a private server-side file. The callback consumes it once under a Symfony lock before token exchange, checks expiry and current user/namespace/admin eligibility, then delegates verification and exchange to the package. It accepts no caller-supplied owner or return destination. Expired abandoned attempts remain on disk but cannot authorize a callback; no cleanup subsystem is introduced.

Use the nullable `User.chatGptIdentity` UUIDv4 as the account namespace, not reusable integer IDs or email addresses. Initialize and flush it before authorization starts. The existing generated migration adds only this nullable column. Fixture reloads create null namespaces; never export a chosen namespace into canonical fixtures. Deleted or recreated users cannot inherit old files, and their outstanding attempts fail the namespace check. Old files are not automatically erased or remotely revoked.

Support one active ChatGPT account per Finance user. After successful package revocation, rotate the account namespace. The next connect starts with a fresh package store and installation registration, allowing another account; failed revocation leaves the namespace and grant intact. Do not edit package record internals to reset registration. Settings displays email/name only from the exact saved active ID token already verified by the package, never incoming callback or pending-refresh tokens. These escaped display claims do not authorize access; missing claims use a generic fallback.

Castor remembers a high callback port per checkout stack mode. Compose publishes it only on `127.0.0.1` to Caddy's callback-only listener. Finance keeps its existing hostname; no ngrok is used. Port collisions fail startup rather than stopping another process. Browser and server must share host loopback.

Keep the normal Symfony runtime and the early `kernel.request` subscriber for `/auth/callback`. It responds before RouterListener can log the query. Profiler exclusions alone do not prevent that routing log. Leave Monolog unchanged, exclude callback and connect/disconnect requests from the development profiler, and use no Caddy access logger. Keep a sanitized outer callback response boundary; never log callback values or provider exception details.

The completion page contains no identity, scripts or remote assets, uses no-store/no-referrer, and links only to the configured Finance origin plus the generated Settings route and fragment. Chat shows connected status only after the package returns a usable access token with direct plan permission. Settings manages usage and disconnect; Chat keeps a compact status and Manage link. Generation remains unavailable until task 11.

## Alternatives considered

- Public HTTPS callback: rejected in the observed flow and contrary to the documented loopback requirement.
- Move all Finance navigation to loopback: simpler session handling, but the owner retained the hostname.
- Ordinary callback controller plus profiler exclusion: the routing listener logs the query before the controller.
- Application encryption and custom auth storage: removed after review in favor of the package store. OS permissions do not provide encryption at rest; that trade-off is accepted for this local demo.
- Copy the Electron/React devkit: unnecessary for Twig. Its license excludes branded asset rights; use an independently authored dark-theme text button, not copied code or assets.

## Consequences and verification

Existing encrypted records and keys are left untouched and are not read, migrated, revoked or deleted. The new store requires fresh sign-in. Isolated browser-stack files live under `var/browser-live/private`; ordinary development files live under `var/private`. Do not inspect or publish them. Production activation is outside this slice.

Use Castor's printed Finance URL for owner-coordinated consent, not the callback URL. A callback-only probe does not prove token exchange, refresh, disconnect or inference. Source inspection and static checks do not prove those integrations either. The owner excluded test changes and execution from this rewrite. No finance generation, Mercure, history, provider selector or quota subsystem is added; ADR 0012's other commitments remain unchanged.
