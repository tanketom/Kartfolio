-- Kartfolio - Database Schema
-- Run this to create a fresh database:
--   sqlite3 private/data/league.db < private/data/schema.sql

PRAGMA foreign_keys = ON;

-- Racers
CREATE TABLE IF NOT EXISTS racers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    nickname TEXT,
    catchphrase TEXT,
    in_mikkoliiga BOOLEAN DEFAULT 0
);

-- Mikkoliiga membership snapshot — captured per-season at archive time so
-- historical standings stay stable even if a member toggles their flag later.
CREATE TABLE IF NOT EXISTS mikkoliiga_membership (
    season_id TEXT NOT NULL,
    racer_id INTEGER NOT NULL,
    snapshotted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (season_id, racer_id),
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);

-- Track head-to-head preferences. Each vote is "voter prefers winner_track
-- over loser_track". Rankings are derived from this table via Elo (see
-- private/includes/track_ranking.php). Powers /track-favourites.
CREATE TABLE IF NOT EXISTS track_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voter_id TEXT NOT NULL,
    winner_track TEXT NOT NULL,
    loser_track TEXT NOT NULL,
    voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_track_pref_voter  ON track_preferences(voter_id, voted_at DESC);
CREATE INDEX IF NOT EXISTS idx_track_pref_winner ON track_preferences(winner_track);
CREATE INDEX IF NOT EXISTS idx_track_pref_loser  ON track_preferences(loser_track);

-- AI-generated coaching reports per racer. Kept as a history (id PK), with
-- the latest = most recent generated_at. Generation is throttled.
CREATE TABLE IF NOT EXISTS coaching_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    racer_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    model_used TEXT,
    season_id TEXT,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_coaching_reports_racer ON coaching_reports(racer_id, generated_at DESC);

-- GP Results
CREATE TABLE IF NOT EXISTS results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gpid TEXT NOT NULL,
    racer_id INTEGER NOT NULL,
    gp_points INTEGER NOT NULL CHECK(gp_points >= 0 AND gp_points <= 60),
    rank INTEGER NOT NULL CHECK(rank >= 1 AND rank <= 12),
    character_used TEXT,
    is_lol BOOLEAN DEFAULT 0,
    race_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    cup_name TEXT,
    kart_setup TEXT,
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);

-- Season Metadata & Rules
CREATE TABLE IF NOT EXISTS season_meta (
    season_id TEXT PRIMARY KEY,
    status TEXT DEFAULT 'active',
    ecology_report TEXT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    attendance_weight FLOAT DEFAULT 1.0,
    weekly_bonus_cap INTEGER DEFAULT 2,
    min_races_threshold INTEGER DEFAULT 3,
    drop_rate INTEGER DEFAULT 10,
    champion_name TEXT DEFAULT '',
    champion_char TEXT DEFAULT 'Mii',
    scoring_system TEXT DEFAULT 'average_attendance',
    academic_term TEXT,
    academic_year INTEGER,
    start_week INTEGER,
    end_week INTEGER,
    start_date DATE,
    end_date DATE,
    grace_period_end DATE,
    finals_week INTEGER,
    cups_required INTEGER DEFAULT 12,
    allow_retries BOOLEAN DEFAULT 1,
    best_n_count INTEGER DEFAULT 15,
    drop_worst_count INTEGER DEFAULT 2,
    perfect_multiplier FLOAT DEFAULT 2.0,
    random_cups_assigned TEXT,
    season_name TEXT,
    season_description TEXT,

    -- MONSTER HUNT scoring
    mh_slay_xp INTEGER DEFAULT 100,
    mh_survive_xp INTEGER DEFAULT 20,
    mh_party_bonus_xp INTEGER DEFAULT 50,
    mh_monster_win_xp INTEGER DEFAULT 80,
    mh_monster_partial_xp INTEGER DEFAULT 30,
    mh_monster_loss_xp INTEGER DEFAULT -40,
    mh_min_gps INTEGER DEFAULT 6,
    mh_best_x INTEGER DEFAULT 20,
    team_best_n INTEGER DEFAULT 2,

    -- Positional Points aggregation: 'best_n' | 'average' | 'sum'
    pos_mode TEXT DEFAULT 'best_n'
);

-- AI Broadcast Archive
CREATE TABLE IF NOT EXISTS recap_archive (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    recap_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    headline TEXT DEFAULT NULL,
    key_quote TEXT DEFAULT NULL,
    program_key TEXT DEFAULT 'core_team',
    linked_gpids TEXT DEFAULT ''
);

-- GP Stories (MONSTER HUNT Chronicles)
CREATE TABLE IF NOT EXISTS gp_stories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gpid TEXT NOT NULL UNIQUE,
    season_id TEXT NOT NULL,
    story_text TEXT NOT NULL,
    story_data TEXT DEFAULT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Cup Picker History
CREATE TABLE IF NOT EXISTS cup_picks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cup_name TEXT NOT NULL,
    picked_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Season Awards
CREATE TABLE IF NOT EXISTS season_awards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    award_category TEXT NOT NULL,
    winner_name TEXT NOT NULL,
    votes INTEGER DEFAULT 0,
    voters INTEGER DEFAULT 0,
    status TEXT DEFAULT 'final',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(season_id, award_category)
);

-- Tournaments
CREATE TABLE IF NOT EXISTS tournaments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    format TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'setup',
    start_date DATETIME,
    end_date DATETIME,
    winner_id INTEGER,
    season_id TEXT,
    eliminations_per_round INTEGER DEFAULT 1, -- Survivor only: bottom N out per round
    snl_board_len INTEGER DEFAULT 30,  -- Snakes & Ladders: squares to the finish
    snl_chaos TEXT DEFAULT 'medium',   -- Snakes & Ladders: hazard density (low|medium|high)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

CREATE TABLE IF NOT EXISTS tournament_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    racer_id INTEGER NOT NULL,
    seed INTEGER NOT NULL,
    elo_at_registration INTEGER,
    final_placement INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (racer_id) REFERENCES racers(id),
    UNIQUE(tournament_id, racer_id)
);

CREATE TABLE IF NOT EXISTS tournament_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    round TEXT NOT NULL,
    match_number INTEGER NOT NULL,
    bracket TEXT NOT NULL DEFAULT 'winners',
    player1_id INTEGER,
    player2_id INTEGER,
    winner_id INTEGER,
    player1_wins INTEGER DEFAULT 0,
    player2_wins INTEGER DEFAULT 0,
    status TEXT DEFAULT 'pending',
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    gpid TEXT,
    num_participants INTEGER DEFAULT 2,
    num_advance INTEGER DEFAULT 1,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (player1_id) REFERENCES racers(id),
    FOREIGN KEY (player2_id) REFERENCES racers(id),
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

CREATE TABLE IF NOT EXISTS tournament_races (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    race_number INTEGER NOT NULL,
    gpid TEXT,
    winner_id INTEGER,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES tournament_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

CREATE TABLE IF NOT EXISTS tournament_trophies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    racer_id INTEGER NOT NULL,
    placement INTEGER NOT NULL,
    trophy_type TEXT NOT NULL,
    awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (racer_id) REFERENCES racers(id)
);

CREATE TABLE IF NOT EXISTS tournament_match_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    racer_id INTEGER NOT NULL,
    placement INTEGER,
    points INTEGER,
    character_used TEXT,
    kart_setup TEXT,
    is_winner BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES tournament_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (racer_id) REFERENCES racers(id),
    UNIQUE(match_id, racer_id)
);

-- Settings
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type TEXT DEFAULT 'text',
    category TEXT DEFAULT 'general',
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Fantasy / Predictions
CREATE TABLE IF NOT EXISTS fantasy_predictions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gpid TEXT NOT NULL,
    predictor_id INTEGER NOT NULL,
    gp_winner_id INTEGER,
    dark_horse_id INTEGER,
    over_under_target_id INTEGER,
    over_under_threshold INTEGER DEFAULT 45,
    over_under_pick TEXT DEFAULT 'over',
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (predictor_id) REFERENCES racers(id),
    UNIQUE(gpid, predictor_id)
);

CREATE TABLE IF NOT EXISTS fantasy_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gpid TEXT NOT NULL,
    predictor_id INTEGER NOT NULL,
    points_earned INTEGER DEFAULT 0,
    winner_correct BOOLEAN DEFAULT 0,
    dark_horse_correct BOOLEAN DEFAULT 0,
    over_under_correct BOOLEAN DEFAULT 0,
    scored_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (predictor_id) REFERENCES racers(id),
    UNIQUE(gpid, predictor_id)
);

CREATE TABLE IF NOT EXISTS fantasy_predictors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    racer_id INTEGER DEFAULT NULL,
    guest_name TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(racer_id),
    UNIQUE(guest_name)
);

CREATE TABLE IF NOT EXISTS fantasy_weeks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_key TEXT NOT NULL UNIQUE,
    deadline TEXT NOT NULL,
    scored BOOLEAN DEFAULT 0,
    scored_at DATETIME DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS fantasy_bets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_key TEXT NOT NULL,
    predictor_id INTEGER NOT NULL,
    bet_type TEXT NOT NULL,
    bet_key TEXT NOT NULL,
    bet_value TEXT NOT NULL,
    confidence INTEGER NOT NULL DEFAULT 1, -- 1 light, 2 medium, 3 lock
    points_earned INTEGER DEFAULT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(week_key, predictor_id, bet_type, bet_key)
);

-- Cup head-to-head preferences — feeds /cup-favourites.
CREATE TABLE IF NOT EXISTS cup_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voter_id TEXT NOT NULL,
    winner_cup TEXT NOT NULL,
    loser_cup TEXT NOT NULL,
    voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Mac's Mushroom Musings — one cached strategy blurb per track.
CREATE TABLE IF NOT EXISTS track_musings (
    track_name TEXT PRIMARY KEY,
    body TEXT NOT NULL,
    model_used TEXT,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Hot-path indexes for the results table.
CREATE INDEX IF NOT EXISTS idx_results_gpid       ON results(gpid);
CREATE INDEX IF NOT EXISTS idx_results_racer_gpid ON results(racer_id, gpid);
CREATE INDEX IF NOT EXISTS idx_results_cup_gpid   ON results(cup_name, gpid);

-- Failed-attempt throttle for login and wall-code submissions.
CREATE TABLE IF NOT EXISTS auth_throttle (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    action TEXT NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_auth_throttle ON auth_throttle(ip, action, attempted_at);

-- Teams — constructor-style team season layer (manual per-season rosters).
CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    name TEXT NOT NULL,
    color TEXT DEFAULT '#e60012',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS team_members (
    season_id TEXT NOT NULL,
    team_id INTEGER NOT NULL,
    racer_id INTEGER NOT NULL,
    PRIMARY KEY (season_id, racer_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_teams_season ON teams(season_id);
CREATE INDEX IF NOT EXISTS idx_team_members_team ON team_members(team_id);

-- Sticker Packs — Panini-style collection (deterministic pack contents).
CREATE TABLE IF NOT EXISTS racer_packs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    racer_id INTEGER NOT NULL,
    source TEXT NOT NULL DEFAULT 'gp',
    gpid TEXT,
    seed INTEGER NOT NULL,
    size INTEGER NOT NULL DEFAULT 3,
    opened_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_racer_packs ON racer_packs(racer_id, opened_at);
CREATE TABLE IF NOT EXISTS racer_stickers (
    racer_id INTEGER NOT NULL,
    sticker_key TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 1,
    first_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (racer_id, sticker_key),
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);

-- World Cup Pick'em — one bracket prediction per name per tournament.
CREATE TABLE IF NOT EXISTS wc_predictions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    predictor_name TEXT NOT NULL,
    picks_json TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tournament_id, predictor_name),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
);

-- Side Quests — two random quests assigned per racer per season.
CREATE TABLE IF NOT EXISTS racer_quests (
    season_id TEXT NOT NULL,
    racer_id INTEGER NOT NULL,
    quest_key TEXT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (season_id, racer_id, quest_key),
    FOREIGN KEY (racer_id) REFERENCES racers(id) ON DELETE CASCADE
);

-- Commissioner's Desk — latest AI digest per season (admin-only).
CREATE TABLE IF NOT EXISTS commish_digests (
    season_id TEXT PRIMARY KEY,
    body TEXT NOT NULL,
    model_used TEXT,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Lexicon — terminology / in-joke reference. Powers /lexicon.
CREATE TABLE IF NOT EXISTS lexicon_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    term TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    category TEXT,
    definition TEXT NOT NULL,
    example TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_lexicon_category ON lexicon_terms(category, term);

-- Default Settings
INSERT OR IGNORE INTO settings (setting_key, setting_value, setting_type, category, description) VALUES
    ('league_name', 'Kartfolio League', 'text', 'league_identity', 'Main name of the league'),
    ('league_tagline', 'Premier Mario Kart Racing League', 'text', 'league_identity', 'League tagline/slogan'),
    ('primary_color', '#e60012', 'color', 'league_identity', 'Primary brand color'),
    ('secondary_color', '#0066cc', 'color', 'league_identity', 'Secondary accent color'),
    ('governing_body_full', 'Organisation Mondial du Karting', 'text', 'league_identity', 'Full name of governing body'),
    ('governing_body_short', 'OMK', 'text', 'league_identity', 'Short name/acronym of governing body'),
    ('footer_about', 'A competitive Mario Kart 8 Deluxe league with AI-powered broadcasts and deep statistics.', 'textarea', 'league_identity', 'Short about text in footer'),
    ('enable_broadcasts', '1', 'boolean', 'features', 'Enable AI broadcast generation'),
    ('enable_rivalries', '1', 'boolean', 'features', 'Enable rivalry tracking'),
    ('enable_tournaments', '0', 'boolean', 'features', 'Enable tournament system'),
    ('wall_code', '0000', 'text', 'features', 'Code required to submit GP results');

-- Default Season
INSERT OR IGNORE INTO season_meta (season_id, status, scoring_system, season_name)
    VALUES ('s01', 'active', 'average_attendance', 'Season 1');
