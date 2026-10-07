#!/usr/bin/env bash
# Deploy gate: proves the LIVE containers are actually running the code
# that was just built and the commit that was supposed to go out — not a
# stale image left over from before a rebuild. This exists because of a
# real near-miss earlier in this project: `docker compose exec` ran
# against a container that predated several image rebuilds, and `migrate`
# silently reported "Nothing to migrate" because of it (caught only by
# cross-checking the plans table by hand) — and because two of three
# serving containers (horizon, scheduler) were found running stale images
# after a deploy that only explicitly rebuilt `laravel`, since this
# compose file gives each service its own `build:` block rather than one
# shared `image:`.
#
# Checks, per service: (1) the running container's image ID matches that
# service's just-built image (via `docker compose images`), and (2) the
# git_commit label baked into the image (see Dockerfile's final ARG/LABEL)
# matches the commit this deploy was supposed to ship.
#
# Usage: scripts/verify-deploy.sh <expected-commit-hash> [service ...]
# Run from the repo root on the SERVER, after build + migrate +
# force-recreate, before calling a deploy piece done. Exits non-zero and
# prints exactly what's wrong on any mismatch.
set -euo pipefail
cd "$(dirname "$0")/.."

EXPECTED_COMMIT="${1:-}"
if [ -z "$EXPECTED_COMMIT" ]; then
    echo "Usage: $0 <expected-commit-hash> [service ...]" >&2
    exit 2
fi
shift
SERVICES=("$@")
if [ "${#SERVICES[@]}" -eq 0 ]; then
    SERVICES=(laravel horizon scheduler)
fi

status=0
for service in "${SERVICES[@]}"; do
    container="haman_${service}"

    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "FAIL: container $container not found — is it running?"
        status=1
        continue
    fi

    built_image=$(docker compose images -q "$service" 2>/dev/null || echo "")
    running_image=$(docker inspect "$container" --format '{{.Image}}' | sed 's/^sha256://')

    if [ -z "$built_image" ]; then
        echo "FAIL: could not resolve the built image id for service '$service' (docker compose images)."
        status=1
    elif [ "$built_image" != "$running_image" ]; then
        echo "FAIL: $container is running image $running_image, but the current build for '$service' is $built_image."
        echo "      It predates the last 'docker compose build $service' — rebuild and force-recreate it."
        status=1
    fi

    baked_commit=$(docker inspect "$container" --format '{{index .Config.Labels "git_commit"}}' 2>/dev/null || echo "")
    if [ -z "$baked_commit" ] || [ "$baked_commit" = "<no value>" ]; then
        echo "FAIL: $container has no git_commit label at all — built from a Dockerfile without the label, or without passing --build-arg GIT_COMMIT."
        status=1
    elif [ "$baked_commit" != "$EXPECTED_COMMIT" ]; then
        echo "FAIL: $container reports git_commit=$baked_commit, expected $EXPECTED_COMMIT."
        status=1
    fi

    if [ "$status" -eq 0 ]; then
        echo "OK: $container -> image ${running_image:0:12}, commit ${baked_commit:0:12}"
    fi
done

echo ""
if [ "$status" -ne 0 ]; then
    echo "DEPLOY VERIFICATION FAILED — the live code does not match what this deploy was supposed to ship. Do not consider this piece done."
    exit 1
fi

echo "Deploy verified: every service above (${SERVICES[*]}) is running a freshly built image at commit $EXPECTED_COMMIT."
