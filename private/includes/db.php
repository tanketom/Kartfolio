<?php
/**
 * Database Connection
 * Path: /private/includes/db.php
 */

// Define the path to the SQLite database file
// Since this file is in /private/includes/, we go up two levels to reach /private/data/
$dbPath = __DIR__ . '/../data/league.db';

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

    // Coaching reports — AI-generated personalised "what to work on" notes
    // per racer. History kept; latest is the most recent generated_at.
    $pdo->exec("CREATE TABLE IF NOT EXISTS coaching_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        racer_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        model_used TEXT,
        season_id TEXT,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coaching_reports_racer ON coaching_reports(racer_id, generated_at DESC)");

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

    // Cup head-to-head preferences — feeds /cup-favourites ranking. (Missing
    // migration discovered when the page fataled locally; production had the
    // table from a manual create.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS cup_preferences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voter_id TEXT NOT NULL,
        winner_cup TEXT NOT NULL,
        loser_cup TEXT NOT NULL,
        voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

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

    // Blue Shell / Hard Mode / Form knobs.
    foreach ([
        "bs_rate FLOAT DEFAULT 0.10", "bs_cap FLOAT DEFAULT 2.0",
        "hm_cap FLOAT DEFAULT 2.0", "form_window INTEGER DEFAULT 8",
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