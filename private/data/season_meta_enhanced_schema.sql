-- Enhanced Season Metadata Schema
-- Supports multiple scoring systems and UiB calendar alignment

-- Drop and recreate with all new columns
-- Note: Use ALTER TABLE for production to preserve existing data

-- New columns to add to existing season_meta table
ALTER TABLE season_meta ADD COLUMN scoring_system TEXT DEFAULT 'average_attendance';
  -- Values: 'average_attendance', 'cup_based', 'best_n_gps', 'drop_worst', 'perfect_hunt', 'random_cup_draw'

ALTER TABLE season_meta ADD COLUMN academic_term TEXT;
  -- Values: 'spring', 'autumn'

ALTER TABLE season_meta ADD COLUMN academic_year INTEGER;
  -- Values: 2026, 2027, etc.

ALTER TABLE season_meta ADD COLUMN start_week INTEGER;
  -- ISO week number (1-53)

ALTER TABLE season_meta ADD COLUMN end_week INTEGER;
  -- ISO week number (1-53)

ALTER TABLE season_meta ADD COLUMN start_date DATE;
  -- Actual calendar start date

ALTER TABLE season_meta ADD COLUMN end_date DATE;
  -- Actual calendar end date

ALTER TABLE season_meta ADD COLUMN grace_period_end DATE;
  -- Grace period final deadline

ALTER TABLE season_meta ADD COLUMN finals_week INTEGER;
  -- Week number when finals period begins

-- Cup-based scoring specific
ALTER TABLE season_meta ADD COLUMN cups_required INTEGER DEFAULT 12;
  -- For cup-based seasons (12 or 24)

ALTER TABLE season_meta ADD COLUMN allow_retries BOOLEAN DEFAULT 1;
  -- Can players replay cups for better scores?

-- Best N GPs specific
ALTER TABLE season_meta ADD COLUMN best_n_count INTEGER DEFAULT 15;
  -- For 'best_n_gps' system: how many GPs to count

-- Drop worst specific
ALTER TABLE season_meta ADD COLUMN drop_worst_count INTEGER DEFAULT 2;
  -- For 'drop_worst' system: how many worst cups to drop

-- Perfect hunt specific
ALTER TABLE season_meta ADD COLUMN perfect_multiplier FLOAT DEFAULT 2.0;
  -- Multiplier for perfect 60 scores

-- Random cup draw specific
ALTER TABLE season_meta ADD COLUMN random_cups_assigned TEXT;
  -- JSON array of cup assignments per racer

-- Display metadata
ALTER TABLE season_meta ADD COLUMN season_name TEXT;
  -- Display name like "Spring 2026" or "The Cup Challenge"

ALTER TABLE season_meta ADD COLUMN season_description TEXT;
  -- Description of season format for display
