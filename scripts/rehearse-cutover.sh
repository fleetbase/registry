#!/usr/bin/env bash
# Rehearses the import half of the cutover (docs/cutover.md) against a seeded fake cloud dump and
# times each step: two dry runs, the import, --verify, a second import (must change nothing),
# --verify again, then the dump path the runbook uses (cloud-registry-dump.sh, then a dry run that
# reads the dump). Everything runs locally against MySQL and an S3-compatible store (MinIO); it never
# touches AWS.
#
#   docker run -d --name rehearsal-mysql -e MYSQL_ROOT_PASSWORD=root -p 33307:3306 mysql:8.4
#   docker run -d --name rehearsal-minio -e MINIO_ROOT_USER=minio -e MINIO_ROOT_PASSWORD=miniosecret -p 19000:9000 minio/minio server /data
#   SCALE=1 scripts/rehearse-cutover.sh
#
# Settings (environment): SCALE (dump size, default 1), SEED (42), MYSQL_HOST (127.0.0.1),
# MYSQL_PORT (33307), MYSQL_SOCKET (empty; a Unix socket is used when set, which is closer to an
# in-VPC round trip than macOS loopback TCP), MYSQL_ROOT_PASSWORD (root), S3_ENDPOINT (http://127.0.0.1:19000; empty for
# local folders as both the cloud bucket and service storage), S3_KEY (minio), S3_SECRET
# (miniosecret). Results: storage/rehearsal/timings-<scale>.json.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCALE="${SCALE:-1}"
SEED="${SEED:-42}"
STATE="$ROOT/storage/rehearsal/scale-$SCALE"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-33307}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
MYSQL_SOCKET="${MYSQL_SOCKET:-}"
SOURCE_DB="cloud_rehearsal"
TARGET_DB="registry_rehearsal"
S3_ENDPOINT="${S3_ENDPOINT-http://127.0.0.1:19000}"

# Production images have no Xdebug; a loaded one slows every call
export APP_ENV=production APP_DEBUG=false XDEBUG_MODE=off
export APP_KEY="${APP_KEY:-base64:$(php -r 'echo base64_encode(random_bytes(32));')}"
export DB_CONNECTION=mysql DB_HOST="$MYSQL_HOST" DB_PORT="$MYSQL_PORT" DB_SOCKET="$MYSQL_SOCKET" DB_DATABASE="$TARGET_DB" DB_USERNAME=root DB_PASSWORD="$MYSQL_ROOT_PASSWORD"
export CACHE_STORE=database QUEUE_CONNECTION=sync MAIL_MAILER=log LOG_LEVEL=warning
if [ -n "$S3_ENDPOINT" ]; then
    export REGISTRY_STORAGE_DISK=s3 AWS_ACCESS_KEY_ID="${S3_KEY:-minio}" AWS_SECRET_ACCESS_KEY="${S3_SECRET:-miniosecret}" AWS_DEFAULT_REGION=us-east-1
    export AWS_BUCKET=registry-rehearsal AWS_ENDPOINT="$S3_ENDPOINT" AWS_USE_PATH_STYLE_ENDPOINT=true
    export IMPORT_SOURCE_AWS_BUCKET=cloud-rehearsal IMPORT_SOURCE_AWS_ENDPOINT="$S3_ENDPOINT" IMPORT_SOURCE_AWS_USE_PATH_STYLE_ENDPOINT=true
else
    export REGISTRY_STORAGE_DISK=local IMPORT_SOURCE_DRIVER=local IMPORT_SOURCE_ROOT="$STATE/files"
fi
export REGISTRY_CLOUD_HOST="https://api.fleetbase.test"

MYSQL=(mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -uroot "-p$MYSQL_ROOT_PASSWORD" --protocol=tcp)
export CLOUD_DB_HOST="$MYSQL_HOST" CLOUD_DB_PORT="$MYSQL_PORT" CLOUD_DB_USER=root CLOUD_DB_NAME="$SOURCE_DB" MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
FROM="mysql://root:$MYSQL_ROOT_PASSWORD@$MYSQL_HOST:$MYSQL_PORT/$SOURCE_DB${MYSQL_SOCKET:+?unix_socket=$MYSQL_SOCKET}"
cd "$ROOT"
php artisan config:clear >/dev/null

now() { php -r 'echo microtime(true);'; }
TIMINGS=()
step() {
    local name="$1"; shift
    echo "==> $name"
    local started; started="$(now)"
    local code=0
    "$@" > "$STATE/$name.log" 2>&1 || code=$?
    local seconds; seconds="$(php -r "printf('%.1f', $(now) - $started);")"
    echo "    ${seconds}s (exit $code)"
    TIMINGS+=("{\"step\": \"$name\", \"seconds\": $seconds, \"exit\": $code}")
}

rm -rf "$STATE" && mkdir -p "$STATE"
[ -z "$S3_ENDPOINT" ] && rm -rf "$ROOT"/storage/app/private/{tarballs,icons,screenshots}/imported

echo "==> Fake cloud dump (scale $SCALE, seed $SEED)"
php scripts/fake-cloud-dump.php --out="$STATE/cloud.sql" --files="$STATE/files" --scale="$SCALE" --seed="$SEED" | tee "$STATE/dump.json"

echo "==> Source database and bucket"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $SOURCE_DB; CREATE DATABASE $SOURCE_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DROP DATABASE IF EXISTS $TARGET_DB; CREATE DATABASE $TARGET_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
"${MYSQL[@]}" "$SOURCE_DB" < "$STATE/cloud.sql" 2>/dev/null
if [ -z "$S3_ENDPOINT" ]; then
    echo "    local folders: $STATE/files -> storage/app/private"
else
    # shellcheck disable=SC2016 # PHP code, not shell
    php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (["registry-rehearsal" => "s3", "cloud-rehearsal" => "import_source"] as $bucket => $disk) {
    $client = Illuminate\Support\Facades\Storage::disk($disk)->getClient();
    if (! $client->doesBucketExist($bucket)) { $client->createBucket(["Bucket" => $bucket]); }
}
$dir = $argv[1];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $file) {
    $path = substr($file->getPathname(), strlen($dir) + 1);
    Illuminate\Support\Facades\Storage::disk("import_source")->writeStream($path, fopen($file->getPathname(), "rb"));
    $n++;
}
echo "    uploaded $n objects\n";
' "$STATE/files"
fi

echo "==> Fresh registry database"
php artisan migrate:fresh --seed --force > "$STATE/migrate.log" 2>&1 || { tail -20 "$STATE/migrate.log"; exit 1; }

step dry-run-1 php artisan registry:import --from="$FROM" --dry-run --report="$STATE/dry-run-1.json"
step dry-run-2 php artisan registry:import --from="$FROM" --dry-run --report="$STATE/dry-run-2.json"
step import php artisan registry:import --from="$FROM" --report="$STATE/import.json"
step verify php artisan registry:import --from="$FROM" --verify
step import-again php artisan registry:import --from="$FROM" --report="$STATE/import-again.json"
step verify-again php artisan registry:import --from="$FROM" --verify --seed=1
step cloud-dump scripts/cloud-registry-dump.sh "$STATE/cloud-registry.sql.gz"
step dry-run-from-dump php artisan registry:import --from="$STATE/cloud-registry.sql.gz" --dry-run --report="$STATE/dry-run-from-dump.json"

printf '{"scale": %s, "seed": %s, "dump": %s, "steps": [%s]}\n' "$SCALE" "$SEED" "$(cat "$STATE/dump.json")" "$(IFS=,; echo "${TIMINGS[*]}")" > "$ROOT/storage/rehearsal/timings-$SCALE.json"
echo "Timings: storage/rehearsal/timings-$SCALE.json; logs and reports: $STATE"
