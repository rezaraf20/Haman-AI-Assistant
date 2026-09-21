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
