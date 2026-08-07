-- Settings System Schema
-- Stores site-wide configuration settings

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type TEXT DEFAULT 'text', -- 'text', 'color', 'number', 'boolean', 'textarea'
    category TEXT DEFAULT 'general', -- 'league_identity', 'features', 'display'
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT OR IGNORE INTO settings (setting_key, setting_value, setting_type, category, description) VALUES
    ('league_name', 'Kartfolio', 'text', 'league_identity', 'Main name of the league'),
    ('governing_body_full', 'Organisation Mondial du Karting', 'text', 'league_identity', 'Full name of governing body'),
    ('governing_body_short', 'OMK', 'text', 'league_identity', 'Short name/acronym of governing body'),
    ('league_tagline', 'Premier Mario Kart Racing League', 'text', 'league_identity', 'League tagline/slogan'),
    ('primary_color', '#E60012', 'color', 'league_identity', 'Primary brand color (Mario Red)'),
    ('secondary_color', '#0066CC', 'color', 'league_identity', 'Secondary accent color'),
    ('footer_about', 'The premier competitive Mario Kart 8 Deluxe league, governed by the Organisation Mondial du Karting. Racing excellence since 2024.', 'textarea', 'league_identity', 'Short about text in footer'),
    ('enable_tournaments', '1', 'boolean', 'features', 'Enable tournament system'),
    ('enable_broadcasts', '1', 'boolean', 'features', 'Enable AI broadcast generation'),
    ('enable_rivalries', '1', 'boolean', 'features', 'Enable rivalry tracking'),
    ('wall_code', '1234', 'text', 'features', 'Four-digit code displayed on the Gameslab wall. Required to submit GP results.'),
    ('stickers_epoch', '2026-06-20', 'text', 'features', 'Sticker packs drop for GPs logged on/after this date (YYYY-MM-DD).');
