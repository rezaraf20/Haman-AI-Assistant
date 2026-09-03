#!/usr/bin/env bash
# Fast, local, no-external-dependency gate to run before every build/deploy —
# not a substitute for the CI Laravel test suite (needs a live Postgres+
# pgvector database, run separately via `cd laravel-backend && vendor/bin/phpunit`
# or through .github/workflows/ci.yml), just what's cheap enough to run every
# single time without waiting. Exits non-zero on the first failing check —
# treat that as "do not deploy" per the project's standing rule.
set -euo pipefail
cd "$(dirname "$0")/.."

PYTHON_BIN=""
for candidate in python3 python py; do
    # Windows ships a "python"/"python3" PATH shim that only prints a
    # Microsoft Store prompt and exits non-zero — command -v finds it fine,
    # so actually run --version and check it succeeds, not just presence.
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" --version >/dev/null 2>&1; then
        PYTHON_BIN="$candidate"
        break
    fi
done
if [ -z "$PYTHON_BIN" ]; then
    echo "FAIL: no working Python interpreter found on PATH (tried python3, python, py)."
    exit 1
fi

echo "== Preflight: checking for merge conflict markers =="
if grep -rlE '^<<<<<<< ' --include='*.php' --include='*.py' . ; then
    echo "FAIL: unresolved merge conflict markers found in the file(s) above."
    exit 1
fi
echo "OK — no conflict markers."

echo "== Preflight: PHP lint (php -l on every file outside vendor) =="
status=0
while IFS= read -r -d '' f; do
    php -l "$f" || status=1
done < <(find laravel-backend -name '*.php' -not -path '*/vendor/*' -print0)
if [ "$status" -ne 0 ]; then
    echo "FAIL: php -l errors found above."
    exit 1
fi
echo "OK — PHP lint clean."

echo "== Preflight: Python syntax check =="
if ! "$PYTHON_BIN" -m compileall -q python-ai-service; then
    echo "FAIL: Python syntax errors found above."
    exit 1
fi
echo "OK — Python syntax clean."

echo "== Preflight: hardcoded Persian string scan (outside lang/) =="
if ! php laravel-backend/scripts/scan-persian-strings.php; then
    echo "FAIL: hardcoded Persian strings found outside lang/ — see above."
    exit 1
fi

echo ""
echo "All preflight checks passed."
