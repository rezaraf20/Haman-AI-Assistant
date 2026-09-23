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

# The PHP that parses this code has to be the PHP the code runs on.
#
# On the production host, "php" on PATH is 7.4.33 while the application runs
# on 8.3 inside its container. Linting with 7.4 reported parse errors in 90 of
# 392 perfectly valid files -- named arguments and enums are syntax errors to
# it -- so preflight failed on the server for a reason that had nothing to do
# with the code. Worse, it would have hidden a real error in the noise.
#
# So: find an interpreter new enough to understand the codebase, and refuse to
# guess if there is not one. DirectAdmin hosts keep versioned builds under
# /usr/local/phpXX/bin, which is why those are searched too.
PHP_BIN=""
for candidate in php php8.3 php8.2 /usr/local/php83/bin/php /usr/local/php82/bin/php; do
    command -v "$candidate" >/dev/null 2>&1 || continue
    version=$("$candidate" -r 'echo PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION;' 2>/dev/null)
    case "$version" in ''|*[!0-9]*) continue ;; esac
    if [ "$version" -ge 802 ]; then
        PHP_BIN="$candidate"
        break
    fi
done
if [ -z "$PHP_BIN" ]; then
    echo "FAIL: no PHP 8.2+ found on PATH (tried php, php8.3, php8.2, /usr/local/php8{3,2}/bin/php)."
    echo "      The application runs on 8.3; linting with an older build reports"
    echo "      syntax errors for valid code and hides real ones."
    exit 1
fi
echo "Using PHP: $PHP_BIN ($("$PHP_BIN" -v 2>/dev/null | head -1 | cut -d' ' -f2))"

echo "== Preflight: checking for merge conflict markers =="
if grep -rlE '^<<<<<<< ' --include='*.php' --include='*.py' . ; then
    echo "FAIL: unresolved merge conflict markers found in the file(s) above."
    exit 1
fi
echo "OK — no conflict markers."

echo "== Preflight: PHP lint (php -l on every file outside vendor) =="
status=0
while IFS= read -r -d '' f; do
    "$PHP_BIN" -l "$f" || status=1
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
if ! "$PHP_BIN" laravel-backend/scripts/scan-persian-strings.php; then
    echo "FAIL: hardcoded Persian strings found outside lang/ — see above."
    exit 1
fi

echo "== Preflight: settings defaults agree across PHP and Python =="
if ! "$PHP_BIN" laravel-backend/scripts/check-settings-defaults.php; then
    echo "FAIL: a shared setting default differs between SettingsRegistry.php and platform_settings_service.py."
    exit 1
fi

echo "== Preflight: chatbot tool catalogue matches the Python registry =="
if ! "$PHP_BIN" laravel-backend/scripts/check-tool-catalogue.php; then
    echo "FAIL: a tool is missing from either ChatbotTools::CATALOGUE or the Python registry."
    exit 1
fi

echo "== Preflight: the plugin download matches the built archive =="
if ! "$PHP_BIN" laravel-backend/scripts/check-plugin-download.php; then
    echo "FAIL: the zip offered in the setup guide differs from the one we build."
    exit 1
fi

echo "== Preflight: no hardcoded credentials in tracked files =="
if ! bash scripts/check-secrets.sh; then
    echo "FAIL: a credential is committed. Rotate it, then move it to an env var."
    exit 1
fi

echo "== Preflight: the advertised plugin version matches the shipped one =="
if ! "$PHP_BIN" laravel-backend/scripts/check-plugin-version.php; then
    echo "FAIL: customers would not be told about the plugin version we ship."
    exit 1
fi

echo "== Preflight: the widget's brand defaults match config('haman.brand.*') =="
if ! "$PHP_BIN" laravel-backend/scripts/check-widget-brand-defaults.php; then
    echo "FAIL: the widget's first-paint color/name/URL fallback drifted from the brand config."
    exit 1
fi

echo ""
echo "All preflight checks passed."
