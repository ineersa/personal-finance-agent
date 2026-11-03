# ADR 0022: Connect ChatGPT locally before chat generation

- Status: Accepted
- Date: 2026-11-02
- Supersedes: ADR 0012's optional-later scheduling for ChatGPT sign-in only.

## Context

The owner requested a ChatGPT connection before task 11 and authorized a public HTTPS callback experiment. OpenAI rejected the HTTPS redirect URI with `invalid_authorize_request`, parameter `redirect_uri`; the subsequent loopback callback-only probe succeeded. These observations do not prove token exchange or inference. OpenAI's [registration documentation](https://developers.openai.com/siwc/token-sharing-open-source/sign-in) requires an HTTP `127.0.0.1` callback, with the same URI in authorization and code exchange.

The application uses a checkout-specific hostname. Its session cookie is not sent to `127.0.0.1`. The owner approved a short-lived server-side attempt bound to the initiating Finance user and a local completion page linking back to Finance, rather than changing the application's hostname.

## Decision

Use published `ineersa/symfony-ai-openai-chatgpt-platform` v0.3.0 with the existing Symfony AI beta packages. Do not depend on a local checkout or patch its loopback redirect. Keep application authentication separate from ChatGPT authorization. Ordinary users connect their own grants; administrators cannot enter this workflow.

Use the package's authorization request, PKCE, state, nonce, signature, account verification, refresh, pending-refresh recovery and disconnect facilities. Store the complete auth record through its `AuthStorageInterface`, with a per-user Symfony Flock lock held over reread, update and persistence. These file locks are not database transactions; no database write transaction spans provider communication.

Encrypt auth records and pending attempts with OpenSSL AES-256-GCM, random 12-byte nonces and 16-byte tags, using the record name as authenticated associated data. Persist ciphertext atomically under private mode-0700 storage with mode-0600 files. Keep the random 32-byte encryption key separately from ciphertext, not in the database, repository or browser. Preserve every package auth-record field, including pending refresh. Losing the key makes existing ciphertext unreadable; do not silently replace a present key or pretend the connection is usable.

A connect POST requires the application session, ordinary-user authorization and CSRF validation. The encrypted pending attempt contains the initiating user ID, account-instance namespace, state, nonce, PKCE verifier, registration ID and a five-minute expiry. Consume it once under a lock before token exchange. The callback accepts no caller-supplied owner or return URL and rechecks that the initiating user still exists, retains the captured account-instance namespace and is not an administrator. The package validates the returned state and issued registration before exchanging the code. Expired abandoned attempt files remain encrypted and cannot authorize a callback; no recovery ledger or background cleanup service is introduced.

Use a server-generated UUIDv4 account-instance namespace, not the reusable integer user ID or email, for credential filenames and refresh locks. Store it in a nullable `User.chatGptIdentity` column; existing users and new fixture accounts start with null. Initialize and flush it when connection first starts, before provider communication. There is no setter for importing a chosen namespace, and no database uniqueness constraint: UUIDv4 randomness supplies namespace separation without an index-triggered SQLite table rebuild. The generated forward migration only adds the nullable column. Status and disconnect use this namespace; a null namespace never looks up a prior grant.

Support one active ChatGPT account per Finance user. Settings owns account display, usage management and disconnect; connected Chat shows a compact link there instead of a permanent account card. Display email/name only from the exact active encrypted AuthRecord ID token that OAuthService already verified, never from callback input or pending-refresh tokens. These display-only claims do not authorize anything and are escaped by Twig; missing claims use a generic fallback. On an explicit disconnect POST, complete package revocation first. Only after success, rotate the account-instance namespace and create a fresh registration context, retaining the installation host ID. This invalidates old pending attempts and allows the next connect to choose another account despite the package retaining the previous registration/identity. Failed revocation leaves the grant and namespace intact. Old disconnected ciphertext remains encrypted and unreachable through the current namespace; there is no account list or account-switching manager.

Deleting or purging a user therefore makes old ciphertext inaccessible to a recreated account, even if its integer ID and email match. An outstanding attempt cannot reattach because its captured namespace must match the current database account before token exchange. Demo reset creates fresh null namespaces rather than restoring old connections. `fixtures/user.yaml` contains no namespace, and the existing history exporter writes statement/transaction fixtures only. Do not add account-instance namespaces to exported canonical fixtures. Old ciphertext and expired attempts are not automatically erased or remotely revoked; they remain encrypted but are not inherited by recreated accounts. No deletion hooks or reset coordination subsystem are required for this ownership boundary.

Castor allocates and remembers a high loopback port for each checkout stack mode, and creates a separate private key per mode. Compose publishes that port only on `127.0.0.1`, targeting an internal callback-only Caddy listener. The existing Finance hostname stays unchanged. No ngrok is used. The port is reserved by Docker at stack startup; a collision fails startup rather than stopping another process. Normal stack stop closes the callback port and preserves keys and encrypted connections.

Use the normal Symfony runtime and a first-priority `kernel.request` subscriber for `/auth/callback`. Its response stops propagation before routing and security session listeners. The native RouterListener otherwise logs the full query before a controller runs; profiler exclusion alone does not prevent this. Exclude callback and connect/disconnect requests from the development profiler, leave Monolog configuration unchanged, use no Caddy access logger, and use a native untraced HttpClient for token exchanges. Do not log callback parameters or provider exceptions. There is no second front controller, request-query mutation or custom logging framework.

The completion page has no identity information, scripts or remote assets, uses no-store and no-referrer policies, and links only to the configured Finance Settings connection section. No automatic redirect carries callback values back to the hostname. Chat shows connected status only after the package returns a usable access token with direct plan permission; failure and identity-only sign-in do not unlock the preview. This slice does not generate chat responses or infer provider availability from a saved record alone.

## Alternatives considered

- Public HTTPS callback: rejected by the observed provider request and contrary to the documented loopback requirement.
- Move the whole application to a loopback origin: simpler session handling, but the owner chose to retain the existing Finance hostname.
- Ordinary callback controller plus profiler exclusion: still leaks the query through the installed routing listener's request-channel log.
- Plaintext package file store with restrictive permissions: permissions do not provide encryption at rest.
- Copy the Electron/React devkit: unnecessary for Twig. The [Paste Perfect example](https://github.com/openai/sign-in-with-chatgpt-devkit/tree/main/examples/paste-perfect) uses connection/status components and a noncommercial license that excludes branded asset rights. Use an independently authored text button and the existing dark UI; copy no devkit code or restricted assets.

## Consequences and verification

Run `vendor/bin/castor browser:stack:up` for isolated owner-consent verification. Use its printed Finance URL, log in with that stack's application account, and choose **Continue with ChatGPT**. The printed callback URL is not the start URL. Browser and server must share the host loopback; remote browsers are not supported by this local flow. The callback page links back to Settings, where verified status is rechecked. `vendor/bin/castor browser:stack:stop` stops only the owned stack.

Castor stores keys in `var/tooling/chatgpt-<mode>.key`, mounts them read-only at `/run/secrets/chatgpt.key`, and stores callback port metadata beside them. Ciphertext lives in the application's `var/private/chatgpt`, or `var/browser-live/private/chatgpt` for the isolated browser stack. Do not inspect or publish those records, back them up alongside plaintext keys in reports, or delete keys to resolve an error. Production activation is not configured by this local slice.

The saved browser scenario covers the disconnected Chat gate only. Real provider consent, verified token exchange, refresh and disconnect require owner-coordinated isolated browser verification. No such runtime success follows from source inspection, static checks, or the earlier callback-only probe. No finance generation, Mercure, history, provider selector or quota subsystem is added. ADR 0012's existing three-provider commitments otherwise remain in force.
