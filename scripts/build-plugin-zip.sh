#!/usr/bin/env bash
#
# Builds wordpress-plugin/haman-ai-chatbot.zip from an explicit file list --
# never `zip -r` over the plugin directory, which is what broke this before.
#
# Found 2026-10-20: the zip in the working tree had grown from 93KB to
# 3.7MB, dominated entirely by node_modules/ (jsdom's dependencies, for the
# JS widget tests), plus tests/, package.json and package-lock.json -- none
# of which belong on a WordPress site. DEPLOY.md's own packaging
# instructions were `zip -r haman-ai-chatbot.zip haman-ai-chatbot/`, run
# from a dev checkout -- that sweeps up anything physically present on
# disk, gitignored or not, the moment `npm install` has been run for the
# test suite. An explicit include list can't make that mistake again: a
# new top-level dev directory someone adds later simply isn't in it.
#
# Usage: scripts/build-plugin-zip.sh   (run from the repo root)
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIR="wordpress-plugin/haman-ai-chatbot"
OUT="wordpress-plugin/haman-ai-chatbot.zip"
MAIN_FILE="$PLUGIN_DIR/haman-ai-chatbot.php"

# Exactly what ships. Adding a new top-level runtime directory to the
# plugin means adding it here too, deliberately -- that friction is the
# point, not an oversight.
INCLUDE=(
    "haman-ai-chatbot/admin"
    "haman-ai-chatbot/includes"
    "haman-ai-chatbot/public"
    "haman-ai-chatbot/haman-ai-chatbot.php"
    "haman-ai-chatbot/uninstall.php"
)

if ! command -v zip >/dev/null 2>&1; then
    echo "FAIL: 'zip' is not installed." >&2
    exit 1
fi

EXPECTED_VERSION=$(grep -oP 'Version:\s*\K[0-9][0-9.]*' "$MAIN_FILE" | head -1)
if [ -z "$EXPECTED_VERSION" ]; then
    echo "FAIL: could not read a Version: header from $MAIN_FILE." >&2
    exit 1
fi

rm -f "$OUT"
( cd wordpress-plugin && zip -q -r "$(basename "$OUT")" "${INCLUDE[@]/#/}" )

echo "Built $OUT:"
unzip -l "$OUT" | tail -1

status=0

echo "--- Checking for dev/VCS artifacts that must never ship ---"
if unzip -l "$OUT" | grep -qE 'node_modules/|/tests/|vendor/|\.git/|\.env|package(-lock)?\.json'; then
    echo "FAIL: forbidden file(s) found in the archive:" >&2
    unzip -l "$OUT" | grep -E 'node_modules/|/tests/|vendor/|\.git/|\.env|package(-lock)?\.json' >&2
    status=1
else
    echo "OK: none found."
fi

echo "--- Checking every path is nested under haman-ai-chatbot/ ---"
if unzip -l "$OUT" | awk 'NR>3 && NF>=4 {print $4}' | grep -v '^$' | grep -vE '^haman-ai-chatbot/' | grep -v '^-' ; then
    echo "FAIL: path(s) found outside the plugin root above." >&2
    status=1
else
    echo "OK: everything is nested under haman-ai-chatbot/."
fi

echo "--- Checking for backslash path separators (would mean a Windows-built zip) ---"
if unzip -l "$OUT" | grep -q '\\'; then
    echo "FAIL: backslash path separator(s) found." >&2
    status=1
else
    echo "OK: forward slashes only."
fi

echo "--- Checking the plugin header round-trips with the right version ---"
ACTUAL_VERSION=$(unzip -p "$OUT" "haman-ai-chatbot/haman-ai-chatbot.php" | grep -oP 'Version:\s*\K[0-9][0-9.]*' | head -1)
if [ "$ACTUAL_VERSION" != "$EXPECTED_VERSION" ]; then
    echo "FAIL: archive's Version header ('$ACTUAL_VERSION') doesn't match the source file ('$EXPECTED_VERSION')." >&2
    status=1
else
    echo "OK: Version $ACTUAL_VERSION, matches the source file."
fi

if [ "$status" -ne 0 ]; then
    echo "" >&2
    echo "Build produced a bad archive -- not leaving it in place." >&2
    rm -f "$OUT"
    exit 1
fi

echo ""
echo "All checks passed: $OUT is ready to install."
