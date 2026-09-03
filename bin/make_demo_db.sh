#!/usr/bin/env bash
# Rebuild the gitignored demo database served by the `cdnmk-territory-demo`
# launch config: a copy of the live league with one season switched to any
# scoring system and made current, so you can preview a system (or the
# Territory map) without touching league.db.
#
#   bin/make_demo_db.sh <season> <system> [decay_gps]
#   bin/make_demo_db.sh s04 territory 4
set -euo pipefail
cd "$(dirname "$0")/.."
SEASON="${1:?season id, e.g. s04}"; SYSTEM="${2:?scoring system key, e.g. territory}"; DECAY="${3:-4}"
SRC=private/data/league.db; OUT=private/data/demo_territory.db
[ -f "$SRC" ] || { echo "no $SRC"; exit 1; }
php -r 'require "private/includes/gp_logic.php"; exit(isset(getScoringSystemRegistry()[$argv[1]]) ? 0 : 1);' "$SYSTEM" || { echo "unknown system '$SYSTEM' — see getScoringSystemRegistry()"; exit 1; }
cp "$SRC" "$OUT"; rm -f "$OUT-wal" "$OUT-shm"
TODAY=$(date +%Y-%m-%d)
sqlite3 "$OUT" "
  UPDATE season_meta SET status = CASE WHEN season_id < '$SEASON' THEN 'archived' ELSE 'upcoming' END WHERE season_id != '$SEASON';
  UPDATE season_meta SET start_date = date('$TODAY', '+400 days'), end_date = date('$TODAY', '+430 days') WHERE season_id > '$SEASON';
  UPDATE season_meta SET scoring_system = '$SYSTEM', status = 'active', start_date = date('$TODAY', '-120 days'), end_date = date('$TODAY', '+120 days'), tt_decay_gps = $DECAY WHERE season_id = '$SEASON';
"
echo "demo db: $OUT · current season $SEASON on $SYSTEM (decay $DECAY)"
echo "serve it: start the 'cdnmk-territory-demo' preview config, or"
echo "  php -S localhost:8081 -t public_html -d auto_prepend_file=bin/dev_demo_db.php bin/dev_router.php"
