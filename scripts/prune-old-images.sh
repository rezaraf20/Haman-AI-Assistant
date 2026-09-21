#!/usr/bin/env bash
#
# Keeps only the currently-running image and the two versions before it, per
# hamman-platform-* repository. Run this after every deploy.
#
# Found 2026-09-21: 108 images sitting on this host (9.2GB reclaimable),
# because every deploy tagged a fresh :latest but nothing ever removed the
# tag it replaced, or the manual per-feature tags (:trends, :suggestions,
# short commit SHAs from an earlier tagging convention) a few builds had
# picked up along the way. None of that was doing anything useful -- a
# rollback only ever needs the last couple of versions, not eight weeks of
# history -- it was just sitting on disk.
set -uo pipefail

KEEP=2  # in addition to whichever image is actually running

running_ids=$(docker ps -q | xargs -r docker inspect --format '{{.Image}}' | sort -u)

for repo in $(docker images --format '{{.Repository}}' | grep '^hamman-platform-' | sort -u); do
    kept=0
    while IFS=$'\t' read -r id created tag; do
        [ -n "$id" ] || continue

        if grep -qxF "$id" <<<"$running_ids"; then
            continue  # the image actually in use -- never counted or removed
        fi

        if [ "$kept" -lt "$KEEP" ]; then
            kept=$((kept + 1))
            continue
        fi

        echo "Removing ${repo}:${tag} (built ${created})"
        docker rmi "${repo}:${tag}" >/dev/null 2>&1 || true
    done < <(docker images --no-trunc "$repo" --format $'{{.ID}}\t{{.CreatedAt}}\t{{.Tag}}' | sort -t $'\t' -k2 -r)
done

# The common case a version-count limit alone does not cover: docker compose
# build always overwrites the same :latest tag in place, so a routine deploy
# never produces a second tagged version to prune -- the image :latest used
# to point at just goes dangling (<none>:<none>) instead. `docker image
# prune -f` is the right tool for exactly that: it only ever removes
# untagged images with no container referencing them (a running container
# pins its image regardless of tag -- confirmed against this host's own
# digest-pinned, permanently-dangling pgvector/postgres image, which this
# leaves alone), so it cannot touch anything another project has tagged or
# is running.
docker image prune -f >/dev/null
