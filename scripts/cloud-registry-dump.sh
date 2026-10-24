#!/usr/bin/env bash
# Dumps exactly the cloud rows `registry:import` reads (docs/cutover.md, steps 2 and 4), consistently
# (one transaction, no table locks), gzipped:
#
#   CLOUD_DB_HOST=… CLOUD_DB_USER=… MYSQL_PWD=… CLOUD_DB_NAME=fleetbase scripts/cloud-registry-dump.sh cloud-registry.sql.gz
#
# Run it where the cloud database is reachable (a cloud API task or the bastion), then copy the file
# to the cloud files bucket the registry reads (`aws s3 cp … s3://<bucket>/registry-cutover/`) and
# import it with `--from=import_source:registry-cutover/<file>`. The dump holds password hashes of
# the registry users: keep it in that private bucket and delete it after the cutover.
#
# Optional: CLOUD_DB_PORT (3306), CLOUD_DB_SSL_CA (a CA bundle when the server requires TLS).
set -euo pipefail

OUT="${1:?usage: cloud-registry-dump.sh <out.sql.gz>}"
DB="${CLOUD_DB_NAME:-fleetbase}"
ARGS=(-h "${CLOUD_DB_HOST:?set CLOUD_DB_HOST}" -P "${CLOUD_DB_PORT:-3306}" -u "${CLOUD_DB_USER:?set CLOUD_DB_USER}"
      --single-transaction --skip-lock-tables --no-tablespaces --protocol=tcp)
[ -n "${CLOUD_DB_SSL_CA:-}" ] && ARGS+=(--ssl-ca="$CLOUD_DB_SSL_CA")
# mysqldump 8 adds GTID statements unless told not to; MariaDB's dump does not know the option.
mysqldump --help 2>/dev/null | grep -q 'set-gtid-purged' && ARGS+=(--set-gtid-purged=OFF)

{
    # The registry tables and the small lookup tables, whole.
    mysqldump "${ARGS[@]}" "$DB" registry_extensions registry_extension_bundles registry_extension_purchases \
        registry_extension_installs registry_users registry_developer_accounts companies categories
    # Only the users and files the registry tables reference (the importer reads nothing else).
    mysqldump "${ARGS[@]}" "$DB" users --where="uuid IN (SELECT user_uuid FROM registry_users) OR uuid IN (SELECT created_by_uuid FROM registry_extensions) OR uuid IN (SELECT created_by_uuid FROM registry_extension_bundles)"
    mysqldump "${ARGS[@]}" "$DB" files --where="uuid IN (SELECT icon_uuid FROM registry_extensions) OR uuid IN (SELECT bundle_uuid FROM registry_extension_bundles) OR subject_uuid IN (SELECT uuid FROM registry_extensions)"
} | gzip -9 > "$OUT"

echo "$OUT: $(du -h "$OUT" | cut -f1)"
