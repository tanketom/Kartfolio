#!/usr/bin/env bash
# Kartfolio checks: lint everything, build a synthetic league, render every
# page against it, and run the invariants in bin/check.php. Exit 1 on any
# failure. Runs locally and in GitHub Actions (.github/workflows/check.yml).
set -uo pipefail
cd "$(dirname "$0")/.."
FIX="${1:-$(mktemp -d)/fixture.db}"
# Never read the developer's real config (Gemini key, admin hash): point the loader
# at nothing so every check runs the way a fresh clone / the CI runner does.
export KARTFOLIO_CONFIG="$(dirname "$FIX")/no-config.php"
fail=0

echo "▸ php -l"
while IFS= read -r f; do out=$(php -l "$f" 2>&1) || { echo "  ✗ $f: $out"; fail=1; }; done < <(git ls-files '*.php' 2>/dev/null || find . -name '*.php' -not -path './.git/*')
[ $fail = 0 ] && echo "  ✓ $(git ls-files '*.php' | wc -l | tr -d ' ') files clean"

echo "▸ node --check"
for f in public_html/assets/js/*.js bin/*.js; do [ -f "$f" ] || continue; node --check "$f" 2>&1 || fail=1; done; [ $fail = 0 ] && echo "  ✓ js clean"

echo "▸ fixture (fresh-install bootstrap through db.php)"
php bin/make_fixture.php "$FIX" || { echo "  ✗ fixture build failed"; exit 1; }

echo "▸ overworld renderer (node, headless)"
node -e '
const O = require("./public_html/assets/js/overworld.js").Overworld;
let bad = 0;
for (const name of ["landscape", "portrait"]) { const L = O.layout(name); let put = 0; O.renderOverworld(() => { put++; }, { layout: name });
  const off = L.stops.filter(([x, y]) => !L.road[y][x] || !(L.land[y][x] || L.raise[y][x])).length;
  if (L.stops.length !== 24 || off || put < 1000) { bad++; console.log("  ✗", name, "stops", L.stops.length, "off-road", off); } else console.log("  ✓", name, L.W + "×" + L.H, "tiles, 24 stops on road"); }
process.exit(bad ? 1 : 0)' || fail=1

echo "▸ invariants + page renders"
php bin/check.php "$FIX" || fail=1

[ $fail = 0 ] && echo "✓ all checks passed" || { echo "✗ checks failed"; exit 1; }
