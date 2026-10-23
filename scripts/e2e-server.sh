#!/usr/bin/env bash
# Boots the Registry Service for the Playwright smoke test: a fresh SQLite database seeded with
# the website fixtures (registry:seed-website), synchronous queue (release checks run inline),
# local package storage and mail to the log. Serves the Vite build in public/build (and runs
# the SSR bundle in bootstrap/ssr when present), so run `npm run build` first.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${E2E_PORT:-8123}"
STATE="$ROOT/storage/e2e"
mkdir -p "$STATE"
rm -f "$STATE/registry.sqlite" && touch "$STATE/registry.sqlite"

if [ ! -f "$ROOT/public/build/manifest.json" ]; then
    echo "public/build/manifest.json is missing: run npm run build first." >&2
    exit 1
fi
# A leftover public/hot would point @vite at a dev server that is not running.
rm -f "$ROOT/public/hot"

export APP_ENV=local
export APP_DEBUG=true
export APP_URL="http://127.0.0.1:$PORT"
export APP_KEY="${APP_KEY:-base64:$(php -r 'echo base64_encode(random_bytes(32));')}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$STATE/registry.sqlite"
export QUEUE_CONNECTION=sync
export CACHE_STORE=array
export SESSION_DRIVER=file
export SESSION_SECURE_COOKIE=false
export MAIL_MAILER=log
export REGISTRY_STORAGE_DISK=local
export REGISTRY_PUBLIC_URL="http://127.0.0.1:$PORT"
export REGISTRY_SECRETS_SCANNER=builtin

cd "$ROOT"
php artisan config:clear >/dev/null
php artisan migrate:fresh --force --seed >/dev/null
php artisan registry:seed-website --json > "$STATE/seed.json"
# With the SSR bundle built (npm run build), run Inertia's renderer too, and make SSR errors
# fail the request instead of quietly rendering in the browser.
SSR_PID=""
if [ -f "$ROOT/bootstrap/ssr/ssr.js" ]; then
    export INERTIA_SSR_ENABLED=true
    export INERTIA_SSR_PORT="${E2E_SSR_PORT:-13715}"
    export INERTIA_SSR_URL="http://127.0.0.1:$INERTIA_SSR_PORT"
    export INERTIA_SSR_THROW_ON_ERROR=true
    node "$ROOT/bootstrap/ssr/ssr.js" &
    SSR_PID=$!
else
    export INERTIA_SSR_ENABLED=false
fi

# `artisan serve` passes only a few variables to its server process and reads .env, so run PHP's
# server directly with Laravel's router script; it inherits this environment.
export PHP_CLI_SERVER_WORKERS=4
cd "$ROOT/public"
php -S "127.0.0.1:$PORT" "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" &
PHP_PID=$!
trap 'kill $PHP_PID $SSR_PID 2>/dev/null' EXIT INT TERM
wait $PHP_PID
