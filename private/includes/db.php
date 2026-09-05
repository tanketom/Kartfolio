<?php
/**
 * Database Connection
 * Path: /private/includes/db.php
 */

// Define the path to the SQLite database file
// Since this file is in /private/includes/, we go up two levels to reach /private/data/
// KARTFOLIO_DB lets a dev server point at another SQLite file (see
// bin/dev_router.php + .claude/launch.json: a demo copy with a Territory
// season, never the live league). Unset in production.
$dbPath = getenv('KARTFOLIO_DB') ?: __DIR__ . '/../data/league.db';

/**
 * Site config (Gemini key, admin password, model). KARTFOLIO_CONFIG overrides
 * the path (bin/check.sh points it at nothing so checks never read a real key).
 * A clone with no config.php yet gets the example's defaults with the admin
 * login disabled, so every public page renders before the commissioner has
 * copied the file — the login page says what to do.
 */
function kartfolioConfig(): array {
    static $c = null;
    if ($c !== null) return $c;
    $path = getenv('KARTFOLIO_CONFIG') ?: __DIR__ . '/../config/config.php';
    if (is_file($path)) return $c = (array)(require $path);
    $c = (array)(require __DIR__ . '/../config/config.example.php');
    $c['admin_password'] = '';   // no config file = no admin login
    $c['_missing'] = true;
    return $c;
}

try {
    // Create (or open) the SQLite database
    $pdo = new PDO("sqlite:" . $dbPath);

    // Set error mode to exceptions for better debugging
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable Foreign Key constraints in SQLite (disabled by default)
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // WAL mode: concurrent readers don't block on writers (and vice versa).
    // Persistent once set — running it on every connect is a no-op if already WAL.
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    // LIKE is case-insensitive by default, and SQLite will not turn a
    // case-insensitive prefix (gpid LIKE 's04%') into an index range — every
    // one of the ~170 season filters was a full scan of results. GPIDs are
    // machine-generated lowercase, so nothing needs case folding there; the
    // two free-text searches (recap mentions, admin results search) fold with
    // LOWER() explicitly. Measured ~3x on this DB, widening with table size.
    $pdo->exec('PRAGMA case_sensitive_like = ON;');

    // ── Migrations run once per schema change, not once per request ─────
    // Everything from here to the closing brace at the bottom of this try
    // block (bootstrap, ~45 ALTER/CREATE statements, the settings seed) used
    // to run on EVERY page load, and the settings seed opened a write
    // transaction on every public render. The whole block is now keyed on a
    // signature of this file plus settings_schema.sql, stored in SQLite's
    // built-in `PRAGMA user_version`: edit either file and the next hit
    // re-runs the (idempotent) block once, then records the new signature.
    // Nothing to bump by hand, so a forgotten constant can't strand a
    // migration on the live server. The deploy model in CLAUDE.md §1 holds:
    // deploy, hit any page, the DB catches up.
    $schemaSig = crc32((string)@file_get_contents(__FILE__) . (string)@file_get_contents(__DIR__ . '/../data/settings_schema.sql'));
    if ($schemaSig > 0x7FFFFFFF) $schemaSig -= 0x100000000;   // user_version is a signed 32-bit int
    $dbSig = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($dbSig !== $schemaSig) {

    // ── Fresh install bootstrap ──────────────────────────────────────────
    // Everything below this point is an UPGRADE step: ALTER TABLEs and index
    // creations that assume the core tables already exist. On a brand-new
    // database they don't, and the first un-caught statement
    // (CREATE INDEX … ON results) aborted the whole connection with
    // "no such table: main.results" — a fresh clone could not serve a page.
    //
    // schema.sql is the canonical base schema and is fully idempotent
    // (CREATE TABLE IF NOT EXISTS throughout, INSERT OR IGNORE seeds), so
    // laying it down when the core is missing is safe and self-healing.
    $coreExists = (int)$pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='results'"
    )->fetchColumn();
    if ($coreExists === 0) {
        $schemaFile = __DIR__ . '/../data/schema.sql';
        if (is_readable($schemaFile)) {
            $pdo->exec(file_get_contents($schemaFile));
        }
    }

    // Inline migrations (idempotent — fail silently if the column exists).
    // MONSTER HUNT admin override on a result row. Used to be created lazily by
    // add_result.php, so a fresh install fataled on any Monster query until
    // someone opened that page — the fixture in bin/check.sh caught it.
    try { $pdo->exec("ALTER TABLE results ADD COLUMN is_monster BOOLEAN DEFAULT 0"); }
    catch (PDOException $e) {}
    // Mikkoliiga: per-racer opt-in flag for the casual sub-league.
    try { $pdo->exec("ALTER TABLE racers ADD COLUMN in_mikkoliiga BOOLEAN DEFAULT 0"); }
    catch (PDOException $e) {}

    // Retired: racers who've left the league. Their history/ELO is preserved,
    // but they're visually flagged (and de-emphasised) on the stats pages.
    try { $pdo->exec("ALTER TABLE racers ADD COLUMN is_retired BOOLEAN DEFAULT 0"); }
    catch (PDOException $e) {}

    // Mikkoliiga membership snapshot — frozen at season-close so historical
    // standings can't shift retroactively when a member changes their flag.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mikkoliiga_membership (
        season_id TEXT NOT NULL,
        racer_id INTEGER NOT NULL,
        snapshotted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (season_id, racer_id),
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");

    // (coaching_reports is no longer created — the feature was retired and
    // nothing reads or writes the table; an existing install keeps its rows.)

    // Fantasy bets — confidence picker (1 light, 2 medium, 3 lock). Default 1
    // so legacy bets continue scoring at base × 1 = unchanged.
    try { $pdo->exec("ALTER TABLE fantasy_bets ADD COLUMN confidence INTEGER NOT NULL DEFAULT 1"); }
    catch (PDOException $e) {}

    // Survivor tournaments — how many bottom finishers are eliminated per round.
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN eliminations_per_round INTEGER DEFAULT 1"); }
    catch (PDOException $e) {}

    // Snakes & Ladders tournaments — board length + hazard density ('chaos').
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN snl_board_len INTEGER DEFAULT 30"); }
    catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN snl_chaos TEXT DEFAULT 'medium'"); }
    catch (PDOException $e) {}

    // Track head-to-head preferences — feeds /track-favourites ranking.
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_preferences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voter_id TEXT NOT NULL,
        winner_track TEXT NOT NULL,
        loser_track TEXT NOT NULL,
        voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_pref_voter  ON track_preferences(voter_id, voted_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_pref_winner ON track_preferences(winner_track)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_pref_loser  ON track_preferences(loser_track)");

    // (cup_preferences is no longer created: /cup-favourites was deleted —
    // /track-favourites is the one that's used. An existing install keeps
    // whatever the table holds; nothing reads it.)

    // Mac's Mushroom Musings — one cached strategy blurb per track. Generated
    // on demand by admins via /api/generate_track_musings. One row per track;
    // re-generation overwrites in place (we don't keep history here).
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_musings (
        track_name TEXT PRIMARY KEY,
        body TEXT NOT NULL,
        model_used TEXT,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Indexes on the hot results-table query patterns. Every page filters by
    // gpid prefix (LIKE 'sNN%'), racer, or cup — without these, each query is
    // a full table scan and the homepage runs hundreds of them.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_results_gpid       ON results(gpid)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_results_racer_gpid ON results(racer_id, gpid)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_results_cup_gpid   ON results(cup_name, gpid)");
    // Chronological reads (Elo engine, timeline, power rankings, wrapped) sort
    // or filter on race_date; without this they build a temp B-tree per call.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_results_date       ON results(race_date, id)");

    // Failed-attempt throttle for login and wall-code submissions, keyed by
    // IP + action. Rows are pruned opportunistically by the consumers.
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_throttle (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        action TEXT NOT NULL,
        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_throttle ON auth_throttle(ip, action, attempted_at)");

    // Teams — constructor-style team season layer. A team belongs to one
    // season; team_members maps racers to a team within that season (so the
    // roster is inherently snapshotted per season — no separate snapshot table
    // needed, unlike Mikkoliiga's live flag). Standings recompute live from
    // results via getTeamStandings().
    $pdo->exec("CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        season_id TEXT NOT NULL,
        name TEXT NOT NULL,
        color TEXT DEFAULT '#e60012',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (
        season_id TEXT NOT NULL,
        team_id INTEGER NOT NULL,
        racer_id INTEGER NOT NULL,
        PRIMARY KEY (season_id, racer_id),
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_teams_season ON teams(season_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_team_members_team ON team_members(team_id)");
    // Per-season constructor depth (how many members count toward each GP).
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN team_best_n INTEGER DEFAULT 2"); }
    catch (PDOException $e) {}

    // Positional Points aggregation mode: 'best_n' (reuses best_n_count),
    // 'average' (points ÷ GPs), or 'sum'. (best_n_count + min_races_threshold
    // already exist and are reused by the Positional + Head-to-Head systems.)
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN pos_mode TEXT DEFAULT 'best_n'"); }
    catch (PDOException $e) {}

    // Head-to-Head: weight of a CPU kart as an opponent (0 = humans only, 1 = full grid).
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN h2h_npc_weight FLOAT DEFAULT 0.25"); }
    catch (PDOException $e) {}

    // Final placements per archived season — snapshotted at archive time (and
    // backfilled once for seasons archived before the table existed). Reading
    // this is one query; recomputing five seasons' standings on every page
    // load was ~18. Immutable by design (§8): history must not shift.
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_placements (
        season_id TEXT NOT NULL,
        racer_id  INTEGER NOT NULL,
        place     INTEGER NOT NULL,
        field     INTEGER NOT NULL,
        snapshotted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (season_id, racer_id)
    )");

    // Blue Shell / Hard Mode / Form knobs.
    foreach ([
        "bs_rate FLOAT DEFAULT 0.10", "bs_cap FLOAT DEFAULT 2.0",
        "hm_cap FLOAT DEFAULT 2.0", "form_window INTEGER DEFAULT 8",
        "tt_decay_gps INTEGER DEFAULT 4",   // Territory: undefended GPs before a cup changes hands (0 = never)
        "bg_line_pts INTEGER DEFAULT 100", "bg_card_pts INTEGER DEFAULT 500",           // Kart Bingo
        "pir_target TEXT DEFAULT 'median'", "pir_best_n INTEGER DEFAULT 15",           // The Price Is Right
        "eq_mode TEXT DEFAULT 'season'",                                             // The Great Equaliser
    ] as $col) {
        try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN $col"); } catch (PDOException $e) {}
    }

    // Sticker Packs — Panini-style collection. Packs are granted (1 per GP
    // raced from the stickers_epoch setting onward + a one-time Founders
    // pack) with DETERMINISTIC contents via `seed`; opening only reveals.
    // racer_stickers is the album (duplicates count up).
    $pdo->exec("CREATE TABLE IF NOT EXISTS racer_packs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        racer_id INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT 'gp',
        gpid TEXT,
        seed INTEGER NOT NULL,
        size INTEGER NOT NULL DEFAULT 3,
        opened_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_racer_packs ON racer_packs(racer_id, opened_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS racer_stickers (
        racer_id INTEGER NOT NULL,
        sticker_key TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 1,
        first_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (racer_id, sticker_key),
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");

    // World Cup Pick'em — one bracket prediction per name per tournament.
    // picks_json: {"groups":{"1":[racerId,racerId],...},"champion":racerId}
    // Scoring is computed live from group tables / results (never stored).
    $pdo->exec("CREATE TABLE IF NOT EXISTS wc_predictions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tournament_id INTEGER NOT NULL,
        predictor_name TEXT NOT NULL,
        picks_json TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(tournament_id, predictor_name),
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
    )");

    // Side Quests — two random quests assigned per racer per season (frozen
    // once assigned; completion is computed live). See quests.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS racer_quests (
        season_id TEXT NOT NULL,
        racer_id INTEGER NOT NULL,
        quest_key TEXT NOT NULL,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (season_id, racer_id, quest_key),
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");

    // Commissioner's Desk — latest AI digest per season (admin-only). One row
    // per season, regeneration overwrites in place.
    $pdo->exec("CREATE TABLE IF NOT EXISTS commish_digests (
        season_id TEXT PRIMARY KEY,
        body TEXT NOT NULL,
        model_used TEXT,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Lexicon — in-joke / terminology reference. Powers /lexicon.
    // Categories let the public page group entries (e.g. "Scoring",
    // "Personas", "Slang"). Term is the human-facing label; slug is
    // a URL-safe anchor so pages can deep-link a specific term.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lexicon_terms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        term TEXT NOT NULL UNIQUE,
        slug TEXT NOT NULL UNIQUE,
        category TEXT,
        definition TEXT NOT NULL,
        example TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lexicon_category ON lexicon_terms(category, term)");

    // Fantasy predictions — created here (versioned, once) instead of by
    // fantasy.php on every render. confidence was a later ALTER on old DBs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_predictors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        racer_id INTEGER DEFAULT NULL,
        guest_name TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(racer_id),
        UNIQUE(guest_name)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_weeks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        week_key TEXT NOT NULL UNIQUE,
        deadline TEXT NOT NULL,
        scored BOOLEAN DEFAULT 0,
        scored_at DATETIME DEFAULT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_bets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        week_key TEXT NOT NULL,
        predictor_id INTEGER NOT NULL,
        bet_type TEXT NOT NULL,
        bet_key TEXT NOT NULL,
        bet_value TEXT NOT NULL,
        confidence INTEGER NOT NULL DEFAULT 1,
        points_earned INTEGER DEFAULT NULL,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(week_key, predictor_id, bet_type, bet_key)
    )");
    try { $pdo->exec("ALTER TABLE fantasy_bets ADD COLUMN confidence INTEGER NOT NULL DEFAULT 1"); }
    catch (PDOException $e) {}

    // Badge sightings: when each racer was first seen holding each badge, so
    // the homepage can mark badges earned on the latest race night. Rows
    // with a NULL first_gpid were backfilled (earned before the log existed).
    $pdo->exec("CREATE TABLE IF NOT EXISTS badge_log (
        season_id   TEXT NOT NULL,
        racer_id    INTEGER NOT NULL,
        badge_title TEXT NOT NULL,
        first_gpid  TEXT,
        first_date  TEXT,
        PRIMARY KEY (season_id, racer_id, badge_title)
    )");

    // Final Territory map per archived season (the map payload frozen at
    // archive time, drawn by the same renderer — immutable, §8).
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_maps (
        season_id  TEXT PRIMARY KEY,
        payload    TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Simulation cache — see private/includes/sim_cache.php. One row per
    // (page, inputs signature); a new GP or a new day makes a new key.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sim_cache (
        cache_key  TEXT PRIMARY KEY,
        payload    TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Settings table + default rows (INSERT OR IGNORE — never overwrites an
    // admin's values). header.php used to exec this on every render.
    $settingsSchema = __DIR__ . '/../data/settings_schema.sql';
    if (is_readable($settingsSchema)) $pdo->exec(file_get_contents($settingsSchema));

    $pdo->exec('PRAGMA user_version = ' . (int)$schemaSig);
    }   // end of the versioned migration block

} catch (PDOException $e) {
    // If the connection fails, stop the script and show the error
    // In a production environment, you might want to log this instead of echoing it
    die("CRITICAL ERROR: Could not connect to the League Database. " . $e->getMessage());
}

/**
 * You can now use the $pdo variable in any file that includes this script:
 * include_once(__DIR__ . '/../private/includes/db.php');
 */
?>