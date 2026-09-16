#!/usr/bin/env bash
#
# Render the link-preview card from SVG to PNG.
#
# Runs in a throwaway container rather than on the host, because it needs two
# things production must not carry: a real SVG renderer (librsvg) and a font
# with Persian shaping. The PNG it writes is committed; this script is how it
# is reproduced, so nobody has to guess what made it.
#
# The host's ImageMagick cannot do this job. Its svg delegate is rsvg-convert,
# which is not installed, so it falls back to an internal renderer with no
# complex-text shaping -- Persian comes out as disconnected letters in the
# wrong order, which is worse than shipping no image at all.
#
# Usage:  laravel-backend/scripts/render-og-image.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/.." && pwd)"

src="${root}/resources/og/og-image.svg"
out="${root}/public/og/og-image.png"

[ -f "$src" ] || { echo "missing source: $src" >&2; exit 1; }
mkdir -p "$(dirname "$out")"

# Vazirmatn is the font the site itself asks for, so the card matches the page.
font_url='https://github.com/rastikerdar/vazirmatn/releases/download/v33.003/vazirmatn-v33.003.zip'

docker run --rm -i \
  -v "${src}:/work/og.svg:ro" \
  -v "$(dirname "$out"):/out" \
  alpine:3.20 sh -eu -c "
    # rsvg-convert is its own package on Alpine; librsvg alone is the
    # library without the command.
    apk add --no-cache rsvg-convert font-dejavu curl unzip >/dev/null

    mkdir -p /usr/share/fonts/vazirmatn
    curl -fsSL '${font_url}' -o /tmp/v.zip
    unzip -qo /tmp/v.zip -d /tmp/v
    find /tmp/v -name '*.woff2' -delete
    find /tmp/v -name '*.ttf' -exec cp {} /usr/share/fonts/vazirmatn/ \; 2>/dev/null || true
    find /tmp/v -name '*.woff' -exec cp {} /usr/share/fonts/vazirmatn/ \; 2>/dev/null || true
    fc-cache -f >/dev/null

    rsvg-convert --width=1200 --height=630 --format=png \
      --output=/out/og-image.png /work/og.svg
  "

echo "wrote $out"
