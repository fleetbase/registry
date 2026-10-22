#!/usr/bin/env sh
# Rebuild the fixture tarballs from src/. Engine tarballs come from `npm pack` (the
# `package/` layout npm publishes); API tarballs are gzipped ustar archives with the same
# single top-level folder, which Composer strips on extraction.
set -eu
cd "$(dirname "$0")"
for kind in hello pro; do
    packed=$(cd "src/$kind-engine" && npm pack --silent | tail -n 1)
    mv "src/$kind-engine/$packed" "$kind-engine-1.0.0.tgz"
    COPYFILE_DISABLE=1 tar --format ustar --uid 0 --gid 0 --uname '' --gname '' \
        -s "#^src/$kind-api#package#" -czf "$kind-api-1.0.0.tar.gz" "src/$kind-api"
done
