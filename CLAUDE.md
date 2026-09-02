# CLAUDE.md

Notes for Claude working in this repo. Read once at session start, then
keep in mind. The README explains *what* the system does for users; this
file explains *how to work on the code* without re-learning lessons.

## Project at a glance

Kartfolio — a self-hosted Mario Kart 8 Deluxe league. PHP 8 + SQLite +
vanilla JS. Apache web root is `public_html/`. Code includes live in
`private/includes/`. No frameworks, no build step. Deploy = `bin/deploy.sh`
(see §12).

Three personas use this code:
- **The user (Tom)** runs the live `cdnmk.bgo.city` league. Mostly admin
  features, broadcasts, season management.
- **Other league commissioners** running their own copies — keep features
  configurable, don't hard-code Tom's league name anywhere.
- **Future Claude** (you, next session) — this file is for you.

## Core conventions you must follow

### 1. Inline migrations live in `private/includes/db.php`

When you add a column or table, write the `ALTER TABLE ADD COLUMN` (or
`CREATE TABLE IF NOT EXISTS`) inside the existing `try/catch` block in
`db.php`. The catch silently ignores "column already exists" so it's
idempotent. Also update `private/data/schema.sql` for fresh installs.

```php
// Inside db.php
try { $pdo->exec("ALTER TABLE racers ADD COLUMN new_thing INTEGER DEFAULT 0"); }
catch (PDOException $e) {}
```

Do **not** ship standalone migration files or require manual runs. The
deploy model is "deploy and hit any page; the DB catches up."

These migrations are **upgrade** steps — `ALTER TABLE`s and index creations
that assume the core tables exist. On a brand-new database they don't, so
`db.php` first applies `private/data/schema.sql` whenever the `results` table
is missing, then runs the migrations. Before that guard a fresh clone could
not serve a single page (`no such table: main.results`). Keep `schema.sql`
fully idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE`) — it is
safe to re-run and is a no-op on an existing install.

### 2. Scoring systems live in a registry

`private/includes/gp_logic.php::getScoringSystemRegistry()` is the single
source of truth. Adding a new scoring system means:

1. One new entry in the registry array (name, icon, description,
   long_description, `calculate` fn, `breakdown` fn, **`tooltip` fn**,
   threshold-gating flag, `sort` comparator).
2. Define your `calculate*Score()`, `breakdown*()` and `tooltip*()` helpers
   in `gp_logic.php` next to the existing ones.
3. Add an entry to `public_html/admin/seasons.php::$scoringSystems` for
   the admin dropdown.
4. Add a settings-fields block in `admin/seasons.php` if your system has
   configurable knobs (use the existing MONSTER HUNT / Bounty Hunter / Pari-Mutuel blocks as templates).
5. Add per-knob persistence to the `save_rules` handler in the same file.
6. If the system has knobs, wire them into the Scoring Simulator too — see
   §2b below. Skipping this doesn't break the page, it just means the
   simulator can only ever try your system on its saved settings.

You should **not** edit `calculateGPScore`, `getScoringSystemInfo`,
`getScoringBreakdown`, `racerQualifies`, or `sortStandingsByScoring` — they
all dispatch through the registry. `api/simulate_scoring.php` also dispatches
through it, but you *do* touch it to register a new system's knob overrides
(step 6).

### 2a. Every system explains its own score — pages never dispatch

The standings hover, the racer profile's season table and `/scoring` all show
"how did I get this number". That text comes from the registry's `tooltip`
entry via `scoringTooltipFromBreakdown($breakdown)`, which takes an
already-computed breakdown so callers pay no extra queries.

**Never write `if ($system === '…')` chains in a page to build this.** Three
pages used to, none were updated when new systems shipped, and four systems
(Positional Points, Head-to-Head, Bounty Hunter, Pari-Mutuel) silently fell
through to the GPScore™ wording — the standings showed
`Avg: 0.00 (0 GPs counted, 0 dropped) + Attendance: 0.00 = 207.00` for a
Positional season. A system with `'tooltip' => null` still gets a safe
fallback (its own name + score), never another system's formula.

Same rule for user-facing labels: don't hardcode "GPScore™" as the score's
name — that's `average_attendance` only. Read `$scoringInfo['name']` for
everything else.

### 2b. The Scoring Simulator must agree with the live standings

`api/simulate_scoring.php` (the simulator on `/admin/seasons`) has to produce
byte-identical standings to the homepage when a season is simulated under its
own system. Three rules keep that true:

- **Sort through the registry.** Call `sortStandingsByScoring()`, never a bare
  `usort` on score. PHP 8 sorts are stable, so a score-only sort silently
  resolves ties by input order — and the racers are fetched `ORDER BY name`,
  so ties came out alphabetical. That ranked Andreas above Hanna on s04 (both
  207) while the real standings put Hanna first on count-back. Rows must carry
  `'id'`; the sorters key off it. Strip the scratch keys they attach
  (`_pos*`, `tiebreaker`) before echoing JSON.
- **Base rules on `getSeasonRules()`**, then overlay overrides. Do not
  hardcode a rules array — the old one invented `attendance_weight 1.0`,
  `drop_rate 10`, `min_races_threshold 3` and omitted `pos_mode` / `bh_*` /
  `pm_*` entirely, so a Positional season set to average mode was always
  simulated as best-N.
- **Scope every knob override to its own system**, and clamp/whitelist it the
  same way `save_rules` does. `best_n_count` is shared by `best_n_gps` and
  `positional_points`, so an unscoped override lets one system's field rewrite
  the other's rules.

On the UI side (`admin/seasons.php`): each system shows only its own fields,
sends only its own params, and the fields are seeded from the season's real
saved config via the `SIM_SEASON_RULES` map (emitted from `season_meta`).
Seeding runs on season/system change only — never while a knob is being
edited, or you clobber admin input mid-typing. Hardcoded field defaults are
the bug here: a best-10 season would open showing 15 and quietly disagree
with its own standings.

### 3. All Gemini calls go through `gemini_client.php`

Use the shared client. It handles retry-on-503 (1s, 2s, 4s backoff) and
walks a four-model fallback chain. Never write a single-shot
`curl_exec()` to `generativelanguage.googleapis.com` — that pattern is
fragile and we've spent multiple sessions hardening it.

```php
require_once __DIR__ . '/../../private/includes/gemini_client.php';
$modelChain = geminiDefaultModelChain($config['model_name'] ?? 'gemini-2.5-flash');
[$response, $httpCode, $lastError, $modelUsed] =
    callGeminiWithRetry($modelChain, $apiKey, $payload);
if ($response === null) { /* surface $lastError */ }
```

Shared host proxies kill long synchronous POSTs from within an HTML
response. For any Gemini-using admin action, prefer this shape:

- Render the page fast with a button.
- Button triggers `fetch()` to a JSON endpoint under `/api/`.
- That endpoint sets `@set_time_limit(300)` and `ignore_user_abort(true)`
  before calling the Gemini client.
- JS shows a status div with progress / cumulative error.

`/api/generate_season_awards.php` and `/api/generate_gp_story.php` are
templates.

### 4. Gemini 2.5 thinking tokens eat the response budget

If you're calling `gemini-2.5-flash` for a structured prose task (where
no internal reasoning helps), set:

```php
'generationConfig' => [
    'maxOutputTokens' => 4000,                  // generous ceiling
    'thinkingConfig'  => ['thinkingBudget' => 0],  // disable thinking
],
```

We hit this on coaching reports — the visible response was cut off
mid-sentence because thinking tokens consumed most of the 1200-token
budget. `thinkingBudget: 0` plus a higher cap is the fix.

### 5. Default model is `gemini-2.5-flash`. Never `gemini-1.5-flash`

`gemini-1.5-flash` is retired from the v1beta endpoint. Old defaults
returned 404. If you find any `?? 'gemini-1.5-flash'` in the codebase,
swap it for `?? 'gemini-2.5-flash'`. The fallback chain handles model
overloads automatically.

### 6. Static data has a single source of truth

- **MK cups** (24 of them) → `mk_data.php`: `MK_BASE_CUPS`,
  `MK_BOOSTER_CUPS`, `getMKAllCups()`, `getMKCupsByGroup()`,
  `getMKCupEmoji()`.
- **MK characters** (~51 of them) → `mk_data.php`: `getMKCharacters()`.
- **News programs** (8 AI personas + OMK Press Office) →
  `programs.php`: `getProgramsCatalog()`, `getAIProgramsCatalog()`,
  `getProgramInfo($key)`.

Do **not** re-declare these lists in pages. If you see a stale copy of
the cup list or character list inline somewhere, that's a refactor
opportunity — move it through the helper.

### 7. CSRF is mandatory on every state-changing POST

Every `<form method="POST">` includes `<?= csrf_field() ?>`. Every POST
handler (the controller side) calls `verify_csrf()` before any state
mutation. Same applies to `/api/` endpoints accepting POSTed CSRF tokens.

When adding a new admin form, verify both halves. We've found gaps; we
don't want to add more.

### 8. Historical data must not shift retroactively

If you add a feature whose calculation depends on a current racer
attribute (flags, settings, etc.), think about archived seasons. The
pattern we use:

- **Live / upcoming season** → read current state.
- **Archived season** → read from a snapshot table captured at
  `status='archived'` time.
- **No snapshot exists** → fall back to current state (for seasons
  archived before the feature existed).

Mikkoliiga (`mikkoliiga_membership` table, `snapshotMikkoliigaMembership()`)
is the reference implementation. The admin `seasons.php` page has a
"Re-snapshot" button so admins can correct historical snapshots if
membership changes need to retroactively land.

### 9. Hot pages read through per-request caches — never per-row query loops

Leaderboard-style pages must not run a query per racer / per GP / per cup.
A page that loops `getActiveRacers()` and queries inside the loop will be a
few hundred queries before you notice. Reuse these patterns:

- **Season-results cache** — `getSeasonResultsByRacer()` /
  `getRacerSeasonRows()` in `gp_logic.php` fetch a season's `results` rows
  **once per request** (static cache, `SELECT *`, ordered
  `gp_points ASC, id ASC`) and serve per-racer slices. Scoring fns,
  breakdowns, `getRaceCount`, `getMostUsedCharacter`, `getCupProgress`,
  `getBestScorePerCup`, `calculatePreviousStandings`, and badges all read
  from it. New leaderboard consumers read from it too.
- **Signature-keyed memoization** — heavy pure-of-DB computations
  (`calculateAllELORatings`, `trackRankings`) cache on a cheap table
  signature (`COUNT || ':' || MAX(id)`), so they recompute only when rows
  actually change — safe even if a write lands mid-request.
- **Batched context** — season/career-wide badge inputs live in
  `badgeSeasonContext()`, built once per season per request. Don't add
  per-racer queries to `getRacerBadges`.
- **Batch, don't loop** — replace "one query per cup/GP" with one query +
  PHP grouping (see `cup_stats.php`, `timeline.php`, `cup_detail.php`).
- The `results` table is indexed on `gpid`, `(racer_id, gpid)`, and
  `(cup_name, gpid)`. Keep new hot filters covered by an index.

### 10. Deterministic ordering, not query-plan-dependent

When you move a sort between SQL and PHP, or drop an `ORDER BY` with no
tiebreak, equal-key rows previously had an arbitrary order that silently
changed when indexes were added. Always pick an explicit, stable tiebreak
(`id ASC`, `name ASC`). We hit this on podium ties, cup "best GP" links,
most-used character, and previous-standings ranks.

### 11. Auth, throttling, and no stray admin pages

- The admin password in `config.php` is a **bcrypt hash**; `auth.php` keeps
  a legacy plaintext fallback compared with `hash_equals`. Login calls
  `session_regenerate_id(true)` and is throttled via the `auth_throttle`
  table (8 fails / 15 min / IP). The `add_result` wall code is throttled the
  same way (10 / 10 min) and compared with `hash_equals`.
- Season editing is admin-only via `/admin/seasons.php`. The old
  unauthenticated `admin_season.php` was deleted — do not reintroduce a
  public season editor. Every new state-changing page lives under `/admin/`
  with `require_admin()` + `verify_csrf()`.

### 12. Deploying, and what must survive a deploy

The live site is a **git checkout** of this repo (`~/www/cdnmk` on the host;
Apache serves `public_html/` beneath it). Deploy from your machine with

```bash
git push
bin/deploy.sh          # add --dry-run to preview
```

`bin/deploy.sh` ssh's in and does `git fetch` + `git reset --hard origin/main`,
prints which files changed, and loads the site so `db.php` applies migrations.
Server details live in `bin/deploy.conf` (**gitignored**; `deploy.conf.example`
is committed). Never put a hostname or path in the repo — it is shared code.

**Hard reset, not pull.** A live install accumulates drift, and a merge that
needs a human is the worst thing to hit mid-deploy. The reset is safe only
because everything that must survive is gitignored and therefore untouched:

- `private/data/league.db` — the league itself
- `private/config/config.php` — Gemini key, admin password hash
- `assets/img/*.png`, `assets/img/tracks/` — character and track art

**So: any new file holding per-install state must be gitignored**, or the next
deploy deletes or overwrites it. That is the one rule this section exists for.

Other things learned the hard way:

- **Never commit the database.** Players create data on the live server (wall
  code, packs, quests, fantasy). A committed `league.db` would overwrite that
  on every push. Data comes *back* to a laptop via Admin → Export Database
  only.
- **There is one codebase.** Codeberg is not a "public empty version" distinct
  from the live site — the live site *is* that code. What makes it Tom's
  league is `league.db` + `config.php`, both ignored. League identity lives in
  the `settings` table (§ "Project at a glance"), never in source. Do not
  create a "live" fork.
- **Don't download the live site into the repo folder.** It flips file modes
  (`755` → `640`, showing as spurious diffs), resurrects deleted files, and
  invites committing dead code. Flow is one-way: laptop → Codeberg → server.
- `deploy.sh` **refuses an unpushed commit** — the server pulls from the
  forge, so deploying first would silently ship the *previous* commit, which
  looks exactly like "it uploaded an old file".
- The host's git is old: no `git init -b`; use `git init` then
  `git branch -M main` after the first reset.
- Converting a drag-and-drop install to a checkout: `git diff origin/main` on
  an empty index reports *every* file as deleted and tells you nothing. Run
  `git read-tree origin/main` first, then `git diff --stat` — that compares
  what is actually on disk. Then `git clean -nd` (preview) before `-fd`;
  gitignored files such as `admin_season.php` need an explicit `rm`.

## Naming / style rules

### MONSTER HUNT is always all-caps

The mode name is `MONSTER HUNT` in every user-facing string. The player
*title* (the rank/role of someone in a Monster Hunt season) is
`Monster Hunter` — keep that as-is. The DB key is `monster_hunt`
(snake_case as with all internal scoring system keys).

If you `sed` over the codebase, use a word-boundary regex
(`\bMonster Hunt\b`) to avoid mangling "Monster Hunter" into
"MONSTER HUNTer".

### GPScore™ has a trademark glyph

The original scoring system in user-facing copy is `GPScore™` — keep
the ™. Internal key is `average_attendance` (legacy naming).

### Mikkoliiga, not Mikkoligan / Mikko Liiga / etc.

One word, one casing. It's the casual sub-league. Members are
"Mikkoliigans" or "Mikkoliiga members."

### Routing conventions

- Clean URLs use **kebab-case**: `/add-result`, `/press-release`,
  `/mh-dashboard`.
- Underlying PHP files use **snake_case**: `add_result.php`,
  `publish_press_release.php`, `mh_dashboard.php`.
- The `.htaccess` rewrite map handles the mapping.
- API endpoints live under `/api/`.
- Admin pages live under `/admin/`.
- Physical signage lives under `/display/`.

### GPID format

`s{NN}gp{NN}` (e.g. `s03gp14`). The first three characters are the
season ID. Tournament match results use `t...` prefixes to keep them out
of season standings — the `WHERE gpid LIKE 's%'` filter in queries is
deliberate and load-bearing.

## Key files you'll touch most

| File | Purpose |
|---|---|
| `private/includes/db.php` | DB connection + inline migrations. Add new column/table migrations here. |
| `private/includes/gp_logic.php` | Scoring engine, system registry, Elo, Mikkoliiga, Bounty Hunter, Pari-Mutuel. |
| `private/includes/elo_engine.php` | All-time Elo computation. Returns `ratings` map, `history`, `gp_changelog`. Heavy — cache results when possible. |
| `private/includes/gemini_client.php` | **Use for every Gemini call.** `callGeminiWithRetry()` + `geminiDefaultModelChain()`. |
| `private/includes/programs.php` | News program catalog (AI personas + OMK Press Office). |
| `private/includes/mk_data.php` | Cups and characters. |
| `private/includes/badges.php` | Badge unlock logic (~27 badges). |
| `private/includes/survivor_tournament.php` | Survivor tournament engine. |
| `private/includes/season_awards_logic.php` | Season awards generation pipeline. |
| `public_html/admin/seasons.php` | Season config UI — scoring system per season, per-knob fields, Mikkoliiga re-snapshot button, Scoring Simulator panel (fields + `SIM_SEASON_RULES` seeding). |
| `public_html/api/simulate_scoring.php` | Scoring Simulator backend. Must mirror live standings — see §2b. |
| `public_html/admin/tournament_setup.php` | Tournament bracket generation (5 formats). |
| `public_html/admin/tournament_bracket.php` | Tournament viewer + match recording. |
| `public_html/racer.php` | Per-racer profile. |
| `public_html/index.php` | Homepage standings + Mikkoliiga top-3 panel. |
| `public_html/archive.php` | News feed + Generate Broadcast + OMK Press Office forms. |
| `public_html/overlay.php` | OBS overlay (7 view modes, hotkeys, URL params, auto-rotate). |
| `public_html/fantasy.php` | Fantasy predictions with confidence picker. |

## Test-before-shipping checklist

We have no test suite. The closest things we have are:

1. **`php -l` syntax check** on any file you edit. Always.
2. **Inline smoke tests** via `php -r '...'` — pull a real racer from the
   DB, run your new helper, eyeball the output. This caught the Mikkoliiga
   snapshot edge cases and the scoring registry equivalence.
3. **Render every URL state for changed pages.** For multi-view things
   (overlay views, scoring systems, tournament formats), loop through
   each variant.
4. **Prove perf refactors with an equivalence harness.** When you swap a
   query path for a cached/batched one, run the old and new code against
   real DB data and diff the output (byte-identical, or field-by-field
   across every racer/season/cup). To prove the query-count win, wrap
   `$pdo` in a `CountingPDO extends PDO` that increments on
   `prepare`/`query`. Both techniques carried the index/timeline/cup_stats/
   badges performance pass — the diff is the proof the behaviour didn't move.

When refactoring a switch-style dispatcher into a registry: write a tiny
regression script that runs both the new and old code paths against real
DB data and compares. The scoring registry refactor used this pattern
and it caught zero bugs *because of the test*. The test is the proof.

## Behavioral cues from the user

The user prefers:
- **Iterative scoping** — start with a short directional prompt, refine
  in follow-ups. Don't ask for a full spec up front; offer 2-3 design
  sketches and let them pick.
- **Concrete examples in proposals** — when proposing a feature, show
  one worked example (e.g. the Bounty Hunter explanation includes a
  worked GP). They react to specifics, not abstractions.
- **Visual confirmation** — they keep the Claude Preview MCP open and
  often verify changes by reload + screenshot. CSS changes especially
  warrant a preview check.
- **Decisive language** — "Do 3." or "Both." is their working style.
  When you propose multiple options, number them so they can answer
  tersely.
- **Concise responses** — they don't need long preambles after a fix.
  "Done. Here's what landed. Try X." is the right shape.

## Things the user has explicitly named "scrapped"

These were proposed and rejected — don't re-suggest them as standalone
features:
- Streak Stack scoring (attendance-streak multiplier)
- Pace / Personal Best scoring
- Glicko-2 / TrueSkill replacement for Elo
- Salary Cap draft scoring
- Daily Double mechanic
- Trifecta picks
- Mood log
- Replay link attachments
- RSVP / calendar
- Roster timeline
- Character meta tracker
- Futures market
- Discord bot (existing "copy to Discord" button stays; full bot
  integration was scrapped earlier)
- Audio TTS broadcasts
- Voice/Whisper result entry
- Pace projection
- Achievement gallery with progress bars
- Rivalry vault page

If they ask "what could we add" again, propose new ideas — don't recycle
this list.

## Things that have been done (per-conversation accomplishments)

Cross-reference if you find half-implemented work:

- Mikkoliiga sub-league with snapshotting
- MONSTER HUNT (highest-Elo participant is the Monster; alphabetical tiebreak; `is_monster` admin override on result row)
- Bounty Hunter scoring (`bh_multiplier`, `bh_carrying_cost`)
- Pari-Mutuel scoring (`pm_ante`, `pm_payout_preset`)
- Survivor tournament format
- Coaching reports — feature retired; code deleted in the dead-code sweep
  (the `coaching_reports` table keeps its 7 historical rows)
- Confidence picks in fantasy (`fantasy_bets.confidence`)
- OBS overlay with 7 hotkey views
- OMK Press Office (hand-written news, no AI)
- Saga/Chronicles AI fallbacks (now uses shared client)
- Scoring registry refactor
- All 5 Gemini callers retrofitted through `gemini_client.php`
- CSRF audit (no gaps remaining as of last sweep)
- Constants pass — MK data in `mk_data.php`, programs in `programs.php`
- Inline-style audit (Mikkoliiga and admin styles moved to `admin.css` /
  `pages.css`)
- **Performance pass** — `results` indexes; season-results cache; Elo +
  track-ranking signature memoization; `badgeSeasonContext` batching;
  N+1 elimination on `index.php`, `timeline.php`, `cup_stats.php`,
  `cup_detail.php` (homepage went ~450 → under 40 queries)
- **Security pass** — bcrypt admin password + login/wall-code throttle
  (`auth_throttle`) + `session_regenerate_id`; deleted the unauthenticated
  `admin_season.php`; JS-context escaping fix in `view_recap.php`;
  `*.db.bak-*` gitignored; `session.cookie_secure`
- **New public pages** — `/scoring-systems`, `/cup/<slug>` (per-track "Mac's
  Mushroom Musings"), `/timeline/<gpid>`, `/lexicon`, `/vault`,
  `/season-chart`
- **New tables** — `track_musings`, `lexicon_terms`, `auth_throttle`. Musings
  + lexicon ship as handwritten `INSERT OR IGNORE` seed files in
  `private/data/` — load on the server with
  `sqlite3 league.db < seed.sql` (the DB is gitignored, so seeded content
  does NOT ride along with `git pull` — only schema migrations in `db.php`
  auto-apply)
- **Coaching reports removed** from `racer.php` (the table + API endpoint
  were left on disk, unused)
- **Favicon** — OMK crest SVG (white-on-red) wired in `header.php`
- **Mikkoliiga** best-20 → best-10 (`MIKKOLIIGA_BEST_X`)
- **Registry tooltips** (§2a) — `tooltip` key on all 14 systems +
  `scoringTooltipFromBreakdown()`; removed the hardcoded if/else chains from
  `index.php`, `racer.php` and `scoring.php`
- **Positional Points explainer** — `/scoring` gained the ladder, count-back
  row and per-GP chips with a cut line; `positionalPointsDetail()` in
  `gp_logic.php`
- **Simulator correctness** (§2b) — sorts via the registry, scores from
  `getSeasonRules()`, per-system knob scoping; Positional / Bounty Hunter /
  Pari-Mutuel knobs exposed in the UI and seeded from the season
- **MONSTER HUNT** — `/scoring` best-N selection had a tie bug (early tied
  hunts ate the slots, under-reporting the sum); the displayed average now
  matches the title's band. Ranking is the best-N **sum**, not an average —
  the old copy claimed otherwise in three places
- **`/season/<id>` route** — `index.php` now honours `?season=`; it had been
  ignoring the param, so the rewrite silently served the current season
- **First-run setup** — `/admin/setup` (league identity, first season, pasted
  roster) shown only while the league is empty; roster parser shared with an
  "add several at once" box on `/admin/racers` (`private/includes/roster.php`).
  Uncovered that a fresh clone couldn't boot — `db.php` now bootstraps
  `schema.sql` (§1)
- **News fallback** — `gemini_recap.php` no longer dies on a quiet week or an
  unraced season: recent → whole season → previous season with results, and
  tells the writer which timeframe it got so old races aren't narrated as
  "last night"
- **Seasons stuck in `upcoming`** — seasons created on `/admin/seasons` had no
  status controls at all (only `active`/`archived` branches existed) and the
  Transition Wizard listed only `active`; s04 (52 GPs) was unclosable. Both
  now handle `upcoming`; the wizard shows status + GP count per season
- **Deploy** (§12) — `bin/deploy.sh`; live server converted from a
  drag-and-drop SFTP copy to a git checkout at `457b522`
- **Head-to-Head negatives** — `headToHeadRaw()` computed `wins = humans − 12-kart
  rank`, so any human behind an NPC went negative (Ola s05: −60%). Now compares
  against the other humans' real grid positions, with CPU karts weighted by the
  new per-season knob `h2h_npc_weight` (0 = pure duels, 1 = full grid, default
  0.25) — the "participation" gradation for the last human. `MK_FIELD_SIZE`,
  `headToHeadGrid()` (season cache, zero per-racer queries). Only s05 ever used
  the system, so nothing archived moved
- **Five new scoring systems** — Blue Shell (catch-up multiplier per place
  behind the leader, `bs_rate`/`bs_cap`), Territory (hold the most cups),
  Median, Hard Mode (league-wide cup-difficulty factor, `hm_cap`), Form
  (rolling `form_window`). All whole-season/cached, all through the registry
  with knobs in config + simulator. Hard Mode barely moves on this league's
  data (factors 0.93–1.04) — the cups are similar in difficulty
- **Tie-break explainer** — registry `tie_explain` fns +
  `explainStandingsTie()`; a "tie" pill on the standings rank explains what
  separated two level racers. Pages never dispatch on the system (§2a)
- **Career arc** — inline SVG of placement per season on the racer profile
- `/scoring-systems` groups systems by a hand-kept key list and silently
  dropped anything unlisted (five new systems, none shown). Now appends a
  catch-all group for any registry key not placed
- **Eight more badges** — Landlord / Usurper / Fortress (cup ownership, one
  chronological pass in `badgeSeasonContext`), Dead Heat (level on score with
  another qualifier), Dynasty (consecutive archived titles by champion name),
  Ever-Present, Full Roster, Questmaster. Questmaster reads `racer_quests`
  directly and evaluates the quest `check` closures itself — never call
  `getRacerQuests()` from badges, it ASSIGNS quests as a side effect
- **Badge icons deduplicated** — 84 badges, 84 distinct emoji. Keep it that
  way: the inventory script in this session's history greps `'icon' =>` in
  `badges.php` + `badges_overview.php` and reports collisions
- **Seven more badges** — On the Up / From the Back (from the new
  `seasonPlacements()` in `gp_logic.php`: registry-sorted, qualifier-gated,
  cached — the one ranking pages should use for "where did X finish"),
  Purple Patch, Constructor, Fantasy Champion, Bracket Buster, Snake Bitten
  (`snlReplay()` now returns `snakeHits`). The last four are empty until
  teams/fantasy/tournaments have data

## When in doubt

- Read the `README.md` for what the system does.
- Read this file (`CLAUDE.md`) for how to work on it.
- Grep `gp_logic.php` if you're touching anything scoring-related.
- Use `php -l` and inline smoke tests before claiming a feature works.
- If the user says "do X," do X concisely — don't ask for clarification
  unless you genuinely cannot proceed.
