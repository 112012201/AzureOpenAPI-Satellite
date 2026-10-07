#!/usr/bin/env bash
# =============================================================================
#  Satellite Data API - cache warmup / refresh
#
#  Improvements over the previous version:
#    - all configuration comes from the environment (no hard coded paths),
#    - a single PHP entry point is called instead of four scripts,
#    - the run is serialised with flock so two cron slots cannot overlap,
#    - the log is rotated and the exit code reflects the upstream status,
#    - --dry-run / --target=<list> / --quiet are supported.
#
#  Suggested crontab (every 10 minutes; the API decides what actually expired):
#    */10 * * * * /www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --quiet
# =============================================================================
SITE_ROOT=/www/wwwroot/satellite.api.authskin.xyz
PHP_BIN=/www/server/php/82/bin/php
TARGETS=tle,satellites,transmitters,amsat-status
LOG_FILE=/www/wwwroot/satellite.api.authskin.xyz/logs/warmup.log
MAX_LOG_BYTES=5242880

set -u -o pipefail

SITE_ROOT="${SITE_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG_DIR="${LOG_DIR:-$SITE_ROOT/logs}"
LOG_FILE="${LOG_FILE:-$LOG_DIR/warmup.log}"
LOCK_FILE="${LOCK_FILE:-$SITE_ROOT/data/warmup.lock}"
MAX_LOG_BYTES="${MAX_LOG_BYTES:-5242880}"
TARGETS="${TARGETS:-tle,satellites,transmitters,amsat-status}"

QUIET=0
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --quiet)     QUIET=1 ;;
        --dry-run)   DRY_RUN=1 ;;
        --target=*)  TARGETS="${arg#--target=}" ;;
        --help|-h)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *)
            echo "unknown option: $arg" >&2
            exit 2
            ;;
    esac
done

mkdir -p "$LOG_DIR" "$SITE_ROOT/data"
touch "$LOG_FILE"

log() {
    local line
    line="[$(date '+%Y-%m-%d %H:%M:%S%z')] $*"
    echo "$line" >> "$LOG_FILE"
    if [ "$QUIET" -eq 0 ]; then
        echo "$line"
    fi
}

rotate_log() {
    if [ -f "$LOG_FILE" ]; then
        local size
        size=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
        if [ "$size" -gt "$MAX_LOG_BYTES" ]; then
            mv -f "$LOG_FILE" "$LOG_FILE.1"
            touch "$LOG_FILE"
        fi
    fi
}

if [ ! -x "$PHP_BIN" ] && ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    log "FATAL: php binary not found at $PHP_BIN (set PHP_BIN=...)"
    exit 127
fi

# Serialise runs: if a warmup is already in progress, exit quietly.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "another warmup is already running, skipping"
    exit 0
fi

rotate_log
log "warmup start (targets=$TARGETS)"

if [ "$DRY_RUN" -eq 1 ]; then
    log "dry run: would execute $PHP_BIN $SITE_ROOT/index.php refresh --target=$TARGETS"
    exit 0
fi

START_MS=$(date +%s%3N)
PHP_OUTPUT=$("$PHP_BIN" "$SITE_ROOT/index.php" refresh --target="$TARGETS" 2>&1)
STATUS=$?
END_MS=$(date +%s%3N)
DURATION=$((END_MS - START_MS))

if [ -n "$PHP_OUTPUT" ]; then
    while IFS= read -r line; do
        log "$line"
    done <<< "$PHP_OUTPUT"
fi

if [ "$STATUS" -eq 0 ]; then
    log "warmup ok in ${DURATION}ms"
else
    log "warmup FAILED (exit $STATUS) in ${DURATION}ms"
fi

# Housekeeping (rate limit buckets, old rotated logs, metrics snapshot).
"$PHP_BIN" "$SITE_ROOT/index.php" prune >> "$LOG_FILE" 2>&1 || true

exit "$STATUS"
