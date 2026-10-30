# Development routing regressions

Use these focused checks after changing Castor lifecycle or cancellation handling. Run them in an owned development checkout with its browser stack prepared. Keep another checkout's services untouched. Save each report before running the same task again.

Set `DEV_URL` and `BROWSER_URL` to this checkout's printed URLs. Set `PROXY` to its verified proxy container name. Confirm the proxy's `finance.proxy.owner` label matches `id -u` before stopping it. These diagnostic Docker commands intentionally simulate an unavailable proxy; ordinary lifecycle commands remain Castor tasks.

## Stop a stack while the proxy is stopped

1. Start the browser stack and record its URL with `vendor/bin/castor browser:stack:up`.
2. Verify the proxy ownership label, then run `docker stop "$PROXY"`.
3. Run `vendor/bin/castor browser:stack:stop`. Expect exit 0, even though the existing proxy container is stopped.
4. Read `finance-development/routes.json` and `Caddyfile` under `git rev-parse --path-format=absolute --git-common-dir`. Confirm the browser hostname is absent. Do not edit the registry to make the assertion pass.
5. Restart only the proxy with `docker start "$PROXY"`. Run the saved browser check before re-registering the application:

```sh
	vendor/bin/castor browser:routes:test --absent="$DEV_URL,$BROWSER_URL"
```

This assumes the current development stack is stopped too. Both URLs must return HTTP 404 with the exact proxy message, `No development route registered`. A stale upstream returning HTTP 502 is a failure.

6. Restore the browser stack, then verify its real homepage and the still-unregistered development URL:

```sh
	vendor/bin/castor browser:stack:up
	vendor/bin/castor browser:routes:test "$BROWSER_URL" --absent="$DEV_URL"
```

Restore the owned preview with `browser:stack:up` even if an assertion fails. Do not stop or delete unrelated proxy resources or volumes.

## Cancel a start during an active subprocess

The timing shim below delays the Compose build command before executing real Docker. It tests Castor-to-subprocess signal delivery and prevention of subsequent startup steps. It does not prove Docker Engine or BuildKit cancellation. The shim and its markers live only under ignored `var/` and are not application hooks.

Start with the development stack stopped and browser preview running. Create a unique disposable directory and a PHP shim using the real Docker path from this machine:

```sh
	PROBE="$PWD/var/qa/cancel-$(date +%s)-$$"
	mkdir -p "$PROBE/shim"
	export PFA_PROBE="$PROBE" PFA_REAL_DOCKER="$(command -v docker)"
	cat > "$PROBE/shim/docker" <<-'PHP'
	#!/usr/bin/env php
	<?php
	if (in_array('compose', $argv, true) && in_array('build', $argv, true)) {
	    pcntl_async_signals(true);
	    pcntl_signal(SIGTERM, static function (): void {
	        file_put_contents(getenv('PFA_PROBE').'/forwarded', "SIGTERM\n");
	        exit(143);
	    });
	    file_put_contents(getenv('PFA_PROBE').'/ready', (string) getmypid());
	    sleep(8);
	}
	pcntl_exec(getenv('PFA_REAL_DOCKER'), array_slice($argv, 1));
	exit(127);
	PHP
	chmod +x "$PROBE/shim/docker"
	PATH="$PROBE/shim:$PATH" vendor/bin/castor dev:stack:up > "$PROBE/castor.log" 2>&1 &
	CASTOR_PID=$!
	for attempt in $(seq 1 100); do
	    test -f "$PROBE/ready" && break
	    sleep 0.1
	done
	kill -TERM "$CASTOR_PID"
	wait "$CASTOR_PID"
	CODE=$?
	printf 'Castor exit: %s\n' "$CODE"
	test -f "$PROBE/ready" && test -f "$PROBE/forwarded" && test "$CODE" = 143
```

Run the block in a shell without `set -e` so that expected exit 143 reaches the assertions. Send SIGTERM only to `CASTOR_PID`, not its process group or the shim PID. If the ready marker never appears, stop the probe and diagnose the launch failure; do not interpret that as successful cancellation.

Confirm `var/qa/dev-stack-up/result.json` records failure with exit 143. Inspect the authoritative route registry and the current development container: the development hostname must remain unregistered and its container must remain stopped or absent. Confirm no shim process remains. A 143 report alone is insufficient because the original bug still started the application after receiving the signal.

Run `vendor/bin/castor dev:stack:status` to confirm the task runner responds; this command does not acquire the shared-registry lock. Run `vendor/bin/castor dev:stack:stop` with a bounded command timeout to confirm a lock-taking operation completes with exit 0. It keeps the already-stopped development stack stopped and must preserve the browser preview. Then run the saved browser check:

```sh
	vendor/bin/castor browser:routes:test "$BROWSER_URL" --absent="$DEV_URL"
```

The browser preview must still render its homepage with loaded CSS; the cancelled development route must return the exact unregistered HTTP 404. Preserve the report, markers, and browser evidence. Remove only the owned probe directory when no longer needed. Restore `browser:stack:up` if the preview was interrupted during diagnosis.
