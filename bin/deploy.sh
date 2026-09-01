#!/usr/bin/env bash
#
# Kartfolio deploy — push-button update of a live install, run from your laptop.
#
#   1. checks the commit you're deploying is actually on the remote
#   2. ssh's in, fetches, and hard-resets the server to that commit
#   3. reports exactly which files changed on the server
#   4. loads a page so db.php applies any new migrations
#
# Hard reset (not `git pull`) is deliberate: a live install accumulates drift,
# and a merge that needs a human is the last thing you want mid-deploy. It is
# safe here because everything that must survive is gitignored and therefore
# untouched — the database, private/config/config.php, and the character and
# track images.
#
# Usage:  bin/deploy.sh [--dry-run]
# Config: copy bin/deploy.conf.example to bin/deploy.conf and fill it in
#         (or export the same variables in your shell).

set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"

# ── Config ────────────────────────────────────────────────────────────────
# Kept out of the repo: this is a shared codebase, so nobody's server details
# belong in it.
[ -f bin/deploy.conf ] && . bin/deploy.conf

DEPLOY_SSH="${DEPLOY_SSH:-}"
DEPLOY_PATH="${DEPLOY_PATH:-}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_URL="${DEPLOY_URL:-}"

if [ -z "$DEPLOY_SSH" ] || [ -z "$DEPLOY_PATH" ]; then
    cat >&2 <<'MSG'
deploy: not configured yet.

  cp bin/deploy.conf.example bin/deploy.conf
  # then edit it — DEPLOY_SSH and DEPLOY_PATH are required

MSG
    exit 1
fi

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

# ── Guard: never deploy a commit the server can't see ─────────────────────
# The server pulls from the git host, so deploying while your commit is still
# sitting on your laptop silently ships the PREVIOUS commit — which looks
# exactly like "it uploaded an old file".
LOCAL_SHA="$(git rev-parse HEAD)"
git fetch --quiet origin "$DEPLOY_BRANCH"
if ! git merge-base --is-ancestor "$LOCAL_SHA" "origin/$DEPLOY_BRANCH"; then
    echo "deploy: HEAD ($(git rev-parse --short HEAD)) is not on origin/$DEPLOY_BRANCH yet." >&2
    echo "        Push first:  git push origin $DEPLOY_BRANCH" >&2
    exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "deploy: note — you have uncommitted changes; they will NOT be deployed."
fi

echo "▸ deploying origin/$DEPLOY_BRANCH → $DEPLOY_SSH:$DEPLOY_PATH"
if [ "$DRY_RUN" = "1" ]; then
    echo "  (dry run — nothing will be changed)"
fi

# ── Remote deploy ─────────────────────────────────────────────────────────
# Runs as one script so a failure anywhere aborts before the reset.
REMOTE_SCRIPT=$(cat <<REMOTE
set -eu
cd "$DEPLOY_PATH"
OLD=\$(git rev-parse HEAD)
git fetch --quiet origin "$DEPLOY_BRANCH"
NEW=\$(git rev-parse "origin/$DEPLOY_BRANCH")

if [ "\$OLD" = "\$NEW" ]; then
    echo "ALREADY_CURRENT \$NEW"
    exit 0
fi

if [ "$DRY_RUN" = "1" ]; then
    echo "WOULD_CHANGE \$OLD \$NEW"
    git diff --name-status "\$OLD" "\$NEW"
    exit 0
fi

git reset --hard "origin/$DEPLOY_BRANCH" >/dev/null
echo "DEPLOYED \$OLD \$NEW"
git diff --name-status "\$OLD" "\$NEW"
REMOTE
)

OUTPUT="$(ssh "$DEPLOY_SSH" "$REMOTE_SCRIPT")"
STATUS_LINE="$(printf '%s\n' "$OUTPUT" | head -1)"
CHANGES="$(printf '%s\n' "$OUTPUT" | tail -n +2)"

case "$STATUS_LINE" in
    ALREADY_CURRENT*)
        echo "✓ server is already on $(echo "$STATUS_LINE" | awk '{print substr($2,1,7)}') — nothing to do"
        exit 0
        ;;
    WOULD_CHANGE*|DEPLOYED*)
        OLD_SHA="$(echo "$STATUS_LINE" | awk '{print substr($2,1,7)}')"
        NEW_SHA="$(echo "$STATUS_LINE" | awk '{print substr($3,1,7)}')"
        VERB="deployed"
        [ "${STATUS_LINE%% *}" = "WOULD_CHANGE" ] && VERB="would deploy"
        echo "▸ $VERB $OLD_SHA → $NEW_SHA"
        if [ -n "$CHANGES" ]; then
            echo "$CHANGES" | sed 's/^/    /'
            echo "  ($(printf '%s\n' "$CHANGES" | grep -c . ) file(s))"
        fi
        ;;
    *)
        echo "deploy: unexpected response from server:" >&2
        printf '%s\n' "$OUTPUT" >&2
        exit 1
        ;;
esac

[ "$DRY_RUN" = "1" ] && exit 0

# ── Warm the site so db.php runs any new migrations ───────────────────────
# Schema changes are applied on the next page load, not by the deploy itself.
if [ -n "$DEPLOY_URL" ]; then
    CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$DEPLOY_URL" || echo 000)"
    if [ "$CODE" = "200" ]; then
        echo "✓ $DEPLOY_URL responded 200 (migrations applied)"
    else
        echo "⚠ $DEPLOY_URL responded $CODE — check the site" >&2
        exit 1
    fi
fi

echo "✓ done"
