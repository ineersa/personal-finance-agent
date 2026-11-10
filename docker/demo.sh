#!/usr/bin/env bash
# Internal Makefile helper for the local demo. Development and QA use Castor.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
umask 077

heading='' accent='' reset=''
if [[ -t 1 && "${TERM:-dumb}" != dumb && -z "${NO_COLOR+x}" ]]; then
    heading=$'\033[1;32m' accent=$'\033[36m' reset=$'\033[0m'
fi

print_help() {
    printf '%sPersonal finance demo%s\n\n' "$heading" "$reset"
    printf '  %s%-14s%s %s\n' "$accent" 'make' "$reset" 'Set up and start the demo; keep existing data.'
    printf '  %s%-14s%s %s\n' "$accent" 'make start' "$reset" 'Start an existing demo.'
    printf '  %s%-14s%s %s\n' "$accent" 'make stop' "$reset" 'Stop the demo without deleting data.'
    printf '  %s%-14s%s %s\n' "$accent" 'make restart' "$reset" 'Restart existing demo services.'
    printf '  %s%-14s%s %s\n' "$accent" 'make help' "$reset" 'Show this help.'
}

action="${1:-help}"
case "$action" in
    help) print_help; exit 0 ;;
    setup|start|stop|restart) ;;
    *) printf 'Use make help for available commands.\n' >&2; exit 1 ;;
esac

command -v docker >/dev/null || { printf 'Docker is required.\n' >&2; exit 1; }
docker compose version >/dev/null
docker info >/dev/null
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-personal-finance-demo}"
export DEMO_ENV_FILE="${DEMO_ENV_FILE:-.env.prod.local}"
export HTTP_PORT="${HTTP_PORT:-8080}"
export CHATGPT_CALLBACK_PORT="${CHATGPT_CALLBACK_PORT:-1455}"
readonly DEMO_READY=/app/var/data/.demo-ready
readonly FRESH_STORAGE_EXIT=10
readonly -a services=(php broker worker mercure charts)
for port in "$HTTP_PORT" "$CHATGPT_CALLBACK_PORT"; do
    [[ "$port" =~ ^[0-9]+$ ]] && ((10#$port > 0 && 10#$port < 65536)) || {
        printf 'Use a numeric loopback port between 1 and 65535.\n' >&2; exit 1;
    }
done
[[ ! -L "$DEMO_ENV_FILE" ]] || { printf 'Refusing a symlinked environment file.\n' >&2; exit 1; }
compose=(docker compose --project-name "$COMPOSE_PROJECT_NAME" --env-file /dev/null -f compose.yaml -f compose.prod.yaml)

print_ready() {
    printf '\n%s%s%s\n' "$heading" "${1:-Demo ready}" "$reset"
    printf 'Open: %shttp://127.0.0.1:%s/%s\n' "$accent" "$HTTP_PORT" "$reset"
    printf 'Sign in: demo@test.com / demo\n'
    printf 'Chat: connect your ChatGPT account in Settings.\n'
    printf 'Statements: get a Jev API key at https://console.typesafe.ai/keys\n'
    printf 'Decision API: JEV_API_KEY, JEV_BASE_URL and JEV_MODEL in %s; apply changes with make start.\n' "$DEMO_ENV_FILE"
}

storage_status() {
    "${compose[@]}" run --rm --no-deps -T php php -r '
if (is_file($argv[1])) { exit(0); }
if ([] === array_values(array_diff(scandir(dirname($argv[1])), [".", ".."]))) { exit((int) $argv[2]); }
fwrite(STDERR, "Existing unrecognized or incomplete application storage: refusing to seed. No data was removed. Inspect it or choose a different COMPOSE_PROJECT_NAME.\n");
exit(1);
' "$DEMO_READY" "$FRESH_STORAGE_EXIT"
}

if [[ "$action" != setup ]]; then
    [[ -f "$DEMO_ENV_FILE" && -s "$DEMO_ENV_FILE" ]] || {
        printf 'Run make first to set up the demo.\n' >&2; exit 1;
    }
    compose=(docker compose --project-name "$COMPOSE_PROJECT_NAME" --env-file "$DEMO_ENV_FILE" -f compose.yaml -f compose.prod.yaml)
    case "$action" in
        start)
            if storage_status; then
                "${compose[@]}" up -d --wait --no-build "${services[@]}"
                print_ready
            else
                printf 'Run make to finish setting up the demo.\n' >&2; exit 1;
            fi
            ;;
        stop)
            "${compose[@]}" stop "${services[@]}"
            printf '\n%sDemo stopped%s. Your data is kept. Start again with make start.\n' "$heading" "$reset"
            ;;
        restart)
            "${compose[@]}" restart "${services[@]}"
            print_ready 'Demo restarted'
            ;;
    esac
    exit 0
fi

# Dependencies and assets are built inside Docker, without host PHP/Composer/Node.
DEMO_ENV_FILE=/dev/null "${compose[@]}" build php charts demo-setup
if [[ ! -e "$DEMO_ENV_FILE" ]]; then
    printf '\n%sSet up statement processing%s\n' "$heading" "$reset"
    printf 'Get a Jev API key at https://console.typesafe.ai/keys\n'
    printf 'Defaults: JEV_BASE_URL=https://api.typesafe.ai, JEV_MODEL=jev-1.14.0\n'
    key=''
    if [[ -n "${JEV_API_KEY:-}" ]]; then
        read -r -p 'JEV_API_KEY is set in your environment. Reuse it? [y/N] ' reuse
        case "$reuse" in
            [yY]|[yY][eE][sS]) key="$JEV_API_KEY" ;;
        esac
    fi
    if [[ -z "$key" ]]; then
        read -r -s -p 'Jev API key: ' key
        printf '\n'
    fi
    # Provider keys are tokens; reject characters requiring dotenv interpolation/escaping.
    [[ "$key" =~ ^[a-zA-Z0-9_.:/+=-]+$ ]] || {
        printf 'Provide the Jev API key token, without whitespace or quotes.\n' >&2; exit 1;
    }
    app_secret=$(DEMO_ENV_FILE=/dev/null "${compose[@]}" run --rm --no-deps -T php php -r 'echo bin2hex(random_bytes(32));')
    mercure_secret=$(DEMO_ENV_FILE=/dev/null "${compose[@]}" run --rm --no-deps -T php php -r 'echo bin2hex(random_bytes(32));')
    # noclobber preserves an environment file created while the image was building.
    (set -o noclobber; printf '%s\n' \
        "APP_SECRET=$app_secret" \
        "MERCURE_JWT_SECRET=$mercure_secret" \
        "JEV_API_KEY='$key'" \
        'JEV_BASE_URL=https://api.typesafe.ai' \
        'JEV_MODEL=jev-1.14.0' > "$DEMO_ENV_FILE")
    unset key app_secret mercure_secret
else
    [[ -f "$DEMO_ENV_FILE" && -s "$DEMO_ENV_FILE" ]] || {
        printf 'Existing environment file is not a nonempty regular file; nothing was overwritten.\n' >&2; exit 1;
    }
    chmod 600 "$DEMO_ENV_FILE"
    printf 'Preserving existing environment and secrets.\n'
fi
compose=(docker compose --project-name "$COMPOSE_PROJECT_NAME" --env-file "$DEMO_ENV_FILE" -f compose.yaml -f compose.prod.yaml)
"${compose[@]}" run --rm --no-deps -T php php -r '
foreach (["APP_SECRET", "MERCURE_JWT_SECRET", "JEV_API_KEY"] as $name) {
    if (!is_string(getenv($name)) || "" === getenv($name)) {
        fwrite(STDERR, "Missing required setting: ".$name.". Edit the private environment file; existing values were not overwritten.\n");
        exit(1);
    }
}'

# A single completion file distinguishes a successful canonical initialization from
# unrelated or interrupted storage. Never guess that an existing DB is disposable.
fresh=false
if storage_status; then
    printf 'Existing demo installation: fixtures will not be loaded again.\n'
else
    status=$?
    [[ "$status" == "$FRESH_STORAGE_EXIT" ]] || exit "$status"
    fresh=true
fi
"${compose[@]}" run --rm --no-deps -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
if "$fresh"; then
    "${compose[@]}" run --rm --no-deps -T demo-setup php bin/console hautelook:fixtures:load --env=dev --append --no-interaction
fi
"${compose[@]}" run --rm --no-deps -T php php bin/console app:tenant:migrate --no-interaction
if "$fresh"; then
    "${compose[@]}" run --rm --no-deps -T php php -r 'exit(false === file_put_contents($argv[1], "Canonical demo fixtures initialized.\n") ? 1 : 0);' "$DEMO_READY"
fi
"${compose[@]}" up -d --wait "${services[@]}"
print_ready
