#!/bin/bash
set -e

# Wait for MinIO to start
echo "Waiting for MinIO to start..."
until curl -s 'http://minio:9000/minio/health/live'; do
  sleep 5
done

# Configure mc with MinIO server
mc alias set minio http://minio:9000 foobar 1234567e

# Create the bucket
mc mb minio/fleetbase-registry
echo "Bucket 'fleetbase-registry' created."
