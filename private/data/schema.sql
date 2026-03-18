-- Kartfolio - Database Schema
-- Run this to create a fresh database:
--   sqlite3 private/data/league.db < private/data/schema.sql

PRAGMA foreign_keys = ON;

-- Racers
CREATE TABLE IF NOT EXISTS racers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    nickname TEXT,
    catchphrase TEXT
);

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
    season_description TEXT
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
    points_earned INTEGER DEFAULT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(week_key, predictor_id, bet_type, bet_key)
);

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
