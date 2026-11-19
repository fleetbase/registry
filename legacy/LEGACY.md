# Legacy Verdaccio registry

This directory holds the Verdaccio-based registry (v1) exactly as it ran before the
Registry Service rewrite. It stays deployable until the Phase 2 publishing cutover.

Run it locally from the repository root:

    docker compose -f legacy/docker-compose.yml up --build

Build and deploy use `legacy/docker-bake.hcl`; the `deploy/*` workflow sets
`workdir: ./legacy` so every path in the bake file and Dockerfiles stays relative
to this directory. The four Verdaccio plugins remain git submodules under
`legacy/plugins/`.
