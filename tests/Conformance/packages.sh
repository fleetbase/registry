#!/usr/bin/env bash
#
# Package protocol conformance: drive real npm and Composer clients against a running
# Registry Service seeded with `php artisan registry:seed --seed-release`.
#
#   php artisan serve --port=8000 &
#   tests/Conformance/packages.sh http://127.0.0.1:8000
#
# Checks the free packages install with no credentials, the paid ones answer 401 without a
# token and 403 with an unlicensed one, and install with a licensed token. Nothing touches
# npmjs.org or packagist.org.
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

AUTHORITY="${BASE_URL#*://}"            # host[:port]
export npm_config_cache="$WORK/npm-cache" npm_config_audit=false npm_config_fund=false npm_config_update_notifier=false
export COMPOSER_HOME="$WORK/composer-home" COMPOSER_CACHE_DIR="$WORK/composer-cache" COMPOSER_NO_INTERACTION=1

pass() { printf '  \033[32mok\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; exit 1; }
expect_fail() { # description, expected output fragment, command...
    local description="$1" fragment="$2"; shift 2
    local output
    if output="$("$@" 2>&1)"; then fail "$description (command succeeded)"; fi
    grep -q -- "$fragment" <<<"$output" || { echo "$output"; fail "$description (expected '$fragment')"; }
    pass "$description"
}

echo "Seeding demo releases"
SEED="$(cd "$ROOT" && php artisan registry:seed --seed-release --json)"
LICENSED="$(php -r 'echo json_decode($argv[1], true)["tokens"]["licensed"];' "$SEED")"
UNLICENSED="$(php -r 'echo json_decode($argv[1], true)["tokens"]["unlicensed"];' "$SEED")"

echo "npm ($(npm --version))"
mkdir -p "$WORK/npm" && cd "$WORK/npm"
npm init -y >/dev/null
echo "@registry-demo:registry=$BASE_URL/npm/" > .npmrc

[ "$(npm view @registry-demo/hello-engine version)" = "1.0.0" ] && pass "npm view resolves the free packument" || fail "npm view"
[ "$(npm view @registry-demo/hello-engine dist-tags.latest)" = "1.0.0" ] && pass "dist-tags.latest" || fail "dist-tags.latest"
npm pack @registry-demo/hello-engine >/dev/null 2>&1 && [ -f registry-demo-hello-engine-1.0.0.tgz ] && pass "npm pack downloads and verifies the tarball" || fail "npm pack"
npm install @registry-demo/hello-engine >/dev/null && [ -f node_modules/@registry-demo/hello-engine/index.js ] && pass "npm install (free)" || fail "npm install (free)"
grep -q '"integrity": "sha512-' package-lock.json && pass "lockfile records sha512 integrity" || fail "lockfile integrity"

expect_fail "paid packument without a token is 401" "401" npm view @registry-demo/pro-engine version
echo "//$AUTHORITY/npm/:_authToken=$UNLICENSED" >> .npmrc
expect_fail "paid packument with an unlicensed token is 403" "403" npm view @registry-demo/pro-engine version
expect_fail "npm publish is refused with a pointer to flb publish" "flb publish" npm publish "$ROOT/database/fixtures/packages/hello-engine-1.0.0.tgz" --registry "$BASE_URL/npm/"
sed -i.bak "s#_authToken=.*#_authToken=$LICENSED#" .npmrc
npm install @registry-demo/pro-engine >/dev/null && [ -f node_modules/@registry-demo/pro-engine/index.js ] && pass "npm install (paid, licensed token)" || fail "npm install (paid)"

echo "Composer ($(composer --version 2>/dev/null | head -n 1))"
mkdir -p "$WORK/composer" && cd "$WORK/composer"
cat > composer.json <<JSON
{
    "name": "conformance/app",
    "repositories": [
        {"type": "composer", "url": "$BASE_URL/composer"},
        {"packagist.org": false}
    ],
    "config": {"secure-http": false}
}
JSON

composer require registry-demo/hello-api:^1.0 --quiet && [ -f vendor/registry-demo/hello-api/src/Hello.php ] && pass "composer require (free)" || fail "composer require (free)"
[ "$(php -r 'require "vendor/autoload.php"; echo RegistryDemo\Hello\Hello::name();')" = "registry-demo/hello-api" ] && pass "autoloads the installed package" || fail "autoload"
grep -q '"shasum": "' composer.lock && pass "lockfile records dist shasum" || fail "lockfile shasum"

expect_fail "paid metadata without a token is 401" "401" composer require registry-demo/pro-api:^1.0
export COMPOSER_AUTH="{\"bearer\": {\"${AUTHORITY}\": \"$UNLICENSED\"}}"
expect_fail "paid metadata with an unlicensed token is 403" "403" composer require registry-demo/pro-api:^1.0
export COMPOSER_AUTH="{\"bearer\": {\"${AUTHORITY}\": \"$LICENSED\"}}"
composer require registry-demo/pro-api:^1.0 --quiet && [ -f vendor/registry-demo/pro-api/src/Pro.php ] && pass "composer require (paid, licensed token)" || fail "composer require (paid)"

echo "All package conformance checks passed."
