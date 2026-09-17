#!/usr/bin/env bash
#
# Fail the build when a credential is hardcoded in a tracked file.
#
# GitGuardian found the Redis password sitting in docker-compose.yml as a
# literal. It had been there through every review and every deploy, and the
# Postgres password was on the line above it. Both services also published
# their ports on every interface, so those two literals were remote access to
# the production database and cache for anyone who read the repository.
#
# Scans tracked files only -- a real .env is gitignored and is supposed to
# hold secrets, so scanning the working tree would report it forever.
#
# The allowlist below is deliberately per-file and per-key, not a pattern.
# A rule like "ignore anything matching test" would hide a real credential
# that happened to sit in a file with "test" in its name.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail=0

report() {
    if [ "$fail" -eq 0 ]; then
        echo "Hardcoded credentials found in tracked files:" >&2
        echo >&2
    fi
    fail=1
    echo "  $1" >&2
}

# ── known-safe, by exact file and exact value ────────────────────────
#
# These are CI fixtures. The database they name is created empty at the
# start of every CI run and destroyed with it, so there is nothing to
# protect; the app refuses to run against a database whose name does not
# end in _test (see tests/TestCase.php). Each entry is "path::value", so
# the same string somewhere else is still a failure.
allowed=(
    "laravel-backend/phpunit.xml::haman_test"
    "laravel-backend/phpunit.xml::test-secret"
    "laravel-backend/phpunit.xml::base64:aGFtbWFuLWNpLXRlc3Qta2V5LTMyLWJ5dGVzLWxvbmc="
    ".github/workflows/ci.yml::haman_test"
    ".github/workflows/ci.yml::test-secret"
    ".github/workflows/ci.yml::base64:aGFtbWFuLWNpLXRlc3Qta2V5LTMyLWJ5dGVzLWxvbmc="
    # The same CI fixture, written out in the runbook so the command can be
    # copied and pasted.
    "DEPLOY.md::haman_test"
)

is_allowed() {
    local file="$1" value="$2" entry
    for entry in "${allowed[@]}"; do
        [ "${entry}" = "${file}::${value}" ] && return 0
    done
    return 1
}

# ── what a hardcoded credential looks like ───────────────────────────
#
# A credential-ish name, then an assignment, then a literal -- and not a
# reference to a variable, an env() call, or a property read, which is what
# the fixed version looks like.
# Two shapes, because the leak that started this used the second one and a
# detector that only knew the first reported the repository clean.
#
#   assignment:  POSTGRES_PASSWORD: value    DB_PASSWORD=value
#   cli flag:    --requirepass value         redis-cli -a value
#
# The flag form is listed by flag rather than by "any word then a space",
# which would match ordinary prose like "PASSWORD is required".
assign_pattern='(PASSWORD|PASSWD|SECRET|API_?KEY|TOKEN|MERCHANT_ID|ENCRYPTION_KEY)[A-Za-z_]*["'"'"']?[[:space:]]*[:=][[:space:]]*["'"'"']?[A-Za-z0-9+/_.=-]{6,}'
# "-a" alone is far too generic -- it is also cp's archive flag -- so the
# redis form is anchored on the command that uses it.
flag_pattern='((--requirepass|--password|--pass)|redis-cli[^|;]*-a)[[:space:]]+["'"'"']?[A-Za-z0-9+/_.=-]{6,}'
pattern="(${assign_pattern})|(${flag_pattern})"

while IFS= read -r line; do
    file="${line%%:*}"
    rest="${line#*:}"
    lineno="${rest%%:*}"
    text="${rest#*:}"

    # The value is the last thing on the assignment.
    match="$(printf '%s' "$text" | grep -oE "$pattern" | head -1)"
    if printf '%s' "$match" | grep -qE "$flag_pattern"; then
        # Flag form: the credential is the last token of the match.
        value="$(printf '%s' "$match" | sed -E 's/.*[[:space:]]["'"'"']?//')"
    else
        value="$(printf '%s' "$match" | sed -E 's/.*[:=][[:space:]]*["'"'"']?//')"
    fi
    [ -n "$value" ] || continue

    is_allowed "$file" "$value" && continue

    # A value that is itself an ALL_CAPS identifier is a reference to another
    # constant, not a literal: "const TTL = HOUR_IN_SECONDS" is not a secret.
    printf '%s' "$value" | grep -qE '^[A-Z][A-Z0-9_]*$' && continue

    masked="${value:0:3}***${value: -2}"
    report "${file}:${lineno}  ${masked}  (${#value} chars)"
done < <(
    git grep -nIE "$pattern" -- . 2>/dev/null \
        | grep -vE '\$\{|\$[A-Za-z_]|env\(|getenv|process\.env|config\(|->|::|get_option|self::|@param|@var' \
        | grep -vE '^\.env\.example:' \
        | grep -vE '^scripts/check-secrets\.sh:'
)

if [ "$fail" -ne 0 ]; then
    echo >&2
    echo "  Move each one into an environment variable and read it with" >&2
    echo "  \${VAR:?message} in docker-compose.yml, so a missing value stops" >&2
    echo "  the stack instead of silently becoming an empty password." >&2
    echo "  See .env.example. If a value is genuinely a CI fixture, add it to" >&2
    echo "  the allowlist in this script by exact file and exact value." >&2
    exit 1
fi

echo "OK — no hardcoded credentials in tracked files."
