-- ============================================================================
-- Lexicon seed — terminology and league jargon grounded in the codebase.
-- Safe to re-run; INSERT OR IGNORE preserves any term you've already added or
-- edited. To force-overwrite the seed, swap to INSERT OR REPLACE.
-- ============================================================================

BEGIN TRANSACTION;

INSERT OR IGNORE INTO lexicon_terms (term, slug, category, definition, example) VALUES
-- ─── Scoring ────────────────────────────────────────────────────────────
('GPScore™', 'gpscore', 'Scoring',
 'The original Kartfolio scoring system — average GP score plus an attendance bonus, capped per week. Internal key: average_attendance.',
 'GPScore™ rewards showing up as much as it rewards winning.'),
('MONSTER HUNT', 'monster-hunt', 'Scoring',
 'XP-per-GP scoring mode. The highest-Elo participant in each GP is the Monster; everyone else gains XP based on the Elo gap and how close they finish to slaying them. CR multiplier scales the payout.',
 'Season 3, ''Dungeons & Drifters'', runs on MONSTER HUNT.'),
('Bounty Hunter', 'bounty-hunter', 'Scoring',
 'Scoring system where every racer above the field median (by pre-GP Elo) carries a bounty equal to their Elo above the median. Beat them in a GP to collect — full bounty per beater, no splitting.',
 'A Bounty Hunter season punishes complacency at the top.'),
('Pari-Mutuel', 'pari-mutuel', 'Scoring',
 'Zero-sum scoring where every participant antes a fixed number of points per GP into a shared pot. The pot redistributes by finish position via the chosen payout curve. Net per GP can go negative.',
 'In Pari-Mutuel, an attended-but-last GP can cost you points.'),
('Black Box', 'black-box', 'Scoring',
 'Scoring system whose formula is intentionally hidden from players. Admins see the calculation; players see only "Black Box Score".',
 '[redacted]'),
('Top 12 Unique', 'top-12-unique', 'Scoring',
 'Cumulative score from your best 12 GPs, each from a different cup. Tiebreaker is most perfect 60s across unique cups.',
 'Top 12 Unique rewards variety as much as consistency.'),
('perfect 60', 'perfect-60', 'Scoring',
 'The maximum possible cup score: 1st place on every track in a cup (15 × 4).',
 'Mick stacked three perfect 60s in a single Top 12 Unique season.'),

-- ─── Sub-leagues & titles ───────────────────────────────────────────────
('Mikkoliiga', 'mikkoliiga', 'Sub-leagues',
 'An opt-in casual sub-league running parallel to the main season. Members race the same GPs but score internally on the canonical Mario Kart 12-position points scale (15/12/10/9/8/7/6/5/4/3/2/1). Best 10 GPs count.',
 'Mikkoliiga members get their own standings on /mikkoliiga.'),
('Mikkoligan', 'mikkoligan', 'Sub-leagues',
 'A member of Mikkoliiga. Plural: Mikkoligans.',
 'The four podium Mikkoligans get their own sidebar on the homepage.'),
('the Monster', 'the-monster', 'Sub-leagues',
 'In MONSTER HUNT scoring, the per-GP designated boss: the highest-Elo participant at race time. Adventurers earn XP for slaying them. Admins can override the pick via the is_monster flag on result entry.',
 'When the Monster wins outright, no one collects slay XP that night.'),
('Monster Hunter', 'monster-hunter', 'Sub-leagues',
 'Title given to the leading XP-earner in a MONSTER HUNT season. Distinct from "the Monster" — Monster Hunters chase, Monsters get chased.',
 'Anna ended Season 3 as Monster Hunter despite never being the Monster herself.'),

-- ─── Slang & in-jokes ───────────────────────────────────────────────────
('Ludwig Obstruction', 'ludwig-obstruction', 'Slang',
 'When an NPC racer (typically Ludwig) ruins an otherwise winning run by blocking, item-spamming, or worse. Logged per-result as the is_lol ("Ludwig Obstruction Law") flag.',
 'I had a 58 going into the Special Cup before a textbook Ludwig Obstruction on the last lap.'),
('LOL flag', 'lol-flag', 'Slang',
 'Database shorthand for is_lol — the per-result boolean that marks a Ludwig Obstruction. Counts feed the season "LOLs" stat and the /vault page.',
 'Three LOL flags in one GP qualifies as a Special Cup curse.'),
('CR multiplier', 'cr-multiplier', 'Slang',
 'Challenge Rating multiplier in MONSTER HUNT — scales slay XP by the Elo gap between the Monster and the adventurer. Ranges roughly ×1.0 to ×2.0.',
 'Mick''s CR multiplier was 1.8 because the Monster outranked him by 200 Elo.'),

-- ─── Personas / News ────────────────────────────────────────────────────
('OMK', 'omk', 'Personas',
 'Organisation Mondial du Karting — the league''s in-fiction governing body. Sets rules, hands out sanctions, signs the broadcasts.',
 'The OMK does not recognise the Ludwig Obstruction Law as an official ruling.'),
('OMK Press Office', 'omk-press-office', 'Personas',
 'A hand-written publishing channel (no AI) for when the league needs sober copy instead of vibes. Press releases appear in the news feed alongside the AI broadcasts.',
 'The finals schedule was announced via OMK Press Office, not a Reef''s Dispatch broadcast.'),
('Saga', 'saga', 'Personas',
 'A season-spanning AI narrative arc — generated chapter-by-chapter as the season progresses, weaving real race results into a serialised story.',
 'The Season 2 Saga turned Anna''s late comeback into a redemption arc.'),
('Chronicles', 'chronicles', 'Personas',
 'Medieval-bard-voiced post-GP stories for MONSTER HUNT seasons. One per GP, told as a tavern tale of the night''s slaying.',
 'The Chronicles for s03gp14 are written in iambic pentameter and we don''t know why.'),
('Mac''s Mushroom Musings', 'macs-mushroom-musings', 'Personas',
 'Per-track strategy notes from Mac, an old Toad caddie who''s worked every cup since the SNES era. One blurb per track, shown on the cup detail page.',
 'Mac''s Mushroom Musings for Wii Coconut Mall mention his nephew Pippin worked the food court there.'),

-- ─── Mechanics ──────────────────────────────────────────────────────────
('GP', 'gp', 'Mechanics',
 'Grand Prix — one race night, four tracks, one cup. The basic unit of league activity.',
 'We''re three GPs into Season 3.'),
('GPID', 'gpid', 'Mechanics',
 'GP identifier, formatted sNNgpNN. The first three characters are the season ID. Tournament matches use t-prefixed IDs to keep them out of season standings.',
 'GPID s03gp14 was the night Anna leapfrogged Mick.'),
('Wall Code', 'wall-code', 'Mechanics',
 'The four-digit code, posted on the Gameslab wall, required to submit GP results via /add-result. Stops drive-by submissions from anyone not physically present.',
 'If the Wall Code is wrong, /add-result rejects the submission with no further explanation.'),
('Fantasy Confidence', 'fantasy-confidence', 'Mechanics',
 'The Light / Medium / 🔒 Lock picker on fantasy predictions that multiplies both hits and misses (×1, ×2, ×3). Locks separate brave predictors from chickens.',
 'A Lock on the right MVP pick is worth as much as three Light ones.'),
('Elo', 'elo', 'Mechanics',
 'Dynamic skill rating computed across every result in league history. Updated on every GP via the elo_engine. Drives the Nemesis Index, MONSTER HUNT''s Monster pick, and Bounty Hunter''s bounty calc.',
 'Mick''s Elo peaked at 1612 right before he started maining Rosalina.'),
('Nemesis Index', 'nemesis-index', 'Mechanics',
 'A pairwise rivalry tracker that surfaces the tightest head-to-head matchups across the league — closest to 50/50 wins the spotlight.',
 'The Anna vs Mick Nemesis Index has been within one game for two whole seasons.');

COMMIT;
