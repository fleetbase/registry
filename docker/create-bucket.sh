#!/bin/sh
# Creates the registry bucket in the local MinIO once it is reachable.
set -e

until mc alias set local http://minio:9000 minio miniosecret >/dev/null 2>&1; do
  echo "waiting for minio..."
  sleep 2
done

mc mb --ignore-existing local/fleetbase-registry
# Icons and screenshots are served straight from the bucket; tarballs stay private.
mc anonymous set download local/fleetbase-registry/icons
mc anonymous set download local/fleetbase-registry/screenshots
echo "bucket fleetbase-registry ready"
