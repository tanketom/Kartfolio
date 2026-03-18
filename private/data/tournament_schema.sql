-- Tournament System Schema

-- Main tournament metadata
CREATE TABLE IF NOT EXISTS tournaments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    format TEXT NOT NULL, -- 'single_elim', 'double_elim'
    status TEXT NOT NULL DEFAULT 'setup', -- 'setup', 'in_progress', 'completed'
    start_date DATETIME,
    end_date DATETIME,
    winner_id INTEGER,
    season_id TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

-- Tournament participants with seeding
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

-- Tournament matches (each match can have multiple races for best-of-X)
CREATE TABLE IF NOT EXISTS tournament_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    round TEXT NOT NULL, -- 'QF', 'SF', 'F', 'GF' (Grand Final), 'LB-R1', 'LB-R2', etc.
    match_number INTEGER NOT NULL,
    bracket TEXT NOT NULL DEFAULT 'winners', -- 'winners', 'losers' (for double elim)
    player1_id INTEGER,
    player2_id INTEGER,
    winner_id INTEGER,
    player1_wins INTEGER DEFAULT 0,
    player2_wins INTEGER DEFAULT 0,
    status TEXT DEFAULT 'pending', -- 'pending', 'in_progress', 'completed'
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (player1_id) REFERENCES racers(id),
    FOREIGN KEY (player2_id) REFERENCES racers(id),
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

-- Individual races within a tournament match
CREATE TABLE IF NOT EXISTS tournament_races (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    race_number INTEGER NOT NULL, -- 1, 2, 3 for best-of-3
    gpid TEXT, -- Link to actual race result if recorded
    winner_id INTEGER,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES tournament_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_id) REFERENCES racers(id)
);

-- Tournament trophy records (for hall of fame)
CREATE TABLE IF NOT EXISTS tournament_trophies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    racer_id INTEGER NOT NULL,
    placement INTEGER NOT NULL, -- 1 = winner, 2 = runner-up, 3 = third place
    trophy_type TEXT NOT NULL, -- 'champion', 'runner_up', 'third_place'
    awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (racer_id) REFERENCES racers(id)
);

-- Add tournament wins to racers table if not exists
-- ALTER TABLE racers ADD COLUMN tournament_wins INTEGER DEFAULT 0;
-- ALTER TABLE racers ADD COLUMN tournament_runner_ups INTEGER DEFAULT 0;
