# Kartfolio

A self-hosted Mario Kart 8 Deluxe league management system. Turns a casual office racing habit into a sports universe — twelve configurable scoring systems, AI-narrated broadcasts, Elo ratings, tournaments, fantasy predictions, archived seasons, and edge-to-edge signage for the lounge screen.

Built with PHP, SQLite, and vanilla JavaScript. No frameworks, no build step — clone, drop in a config file, point Apache at it, and race.

---

## Features

### Core engine

- **GP result logging** with auto-incrementing GPID (`s03gp14`), smart character/kart auto-fill from each racer's last result, and a four-digit wall code that has to be entered at the Gameslab to prevent remote submissions
- **Twelve scoring systems**, picked per-season — GPScore™ (avg + attendance), Pre-Season, Cup-Based, Best-N GPs, Drop Worst, Perfect Hunt, Top 12 Unique Cups, Random Cup Draw, Black Box, **MONSTER HUNT** (XP-based RPG mode), **Bounty Hunter** (Elo-above-median collection), and **Pari-Mutuel** (zero-sum ante and payout)
- **Season management** with start/end dates, status (upcoming → active → archived), per-system rule knobs, and finals weeks
- **Elo ratings** computed across all-time race history with K-factor curves for newer racers
- **Mikkoliiga** — opt-in casual sub-league that races in the same GPs but scores internally with a Mario Kart 12-position scale, best 20 GPs counted. Membership is admin-flagged per racer and snapshotted at season close so historical standings stay frozen.

### AI media ecology

All AI features use the shared Gemini client (`private/includes/gemini_client.php`) with automatic retry-on-503 (exponential backoff) and a four-model fallback chain — so transient overloads don't take down individual features.

- **AI broadcasts** — eight programs/personas write weekly recaps in distinct voices (Kart Core Team, Reef's Dispatch, The Meta Report, The Rant, The Ghost Racer's Ascent, The Situated Spectator, Viberacing, or Surprise Me)
- **OMK Press Office** — a hand-written publishing path for straight news; bypasses Gemini entirely. Admin types headline + key quote + body, hits Publish.
- **MONSTER HUNT Chronicles** — medieval-bard-voiced post-GP stories for Monster Hunt seasons, archived per GP
- **Power Rankings** — weekly AI commentary on each racer's form and trajectory
- **Tournament recaps** — AI-generated bracket post-mortems
- **Season reports** — end-of-season AI archive entries
- **Season Awards** — six fixed core awards auto-determined from stats (Champion, Most Improved, Consistency King, Comeback Player, Most Entertaining, Best Rivalry) plus AI-generated personalized awards for every other racer
- **Coaching Reports** — per-racer personalized "what to work on" reports grounded in cup performance, character pairings, H2H Elo-expectation gaps, recent form delta, and streaks. Throttled to ~one per racer per 30 days.

### Competition layers

- **Tournament system** — five formats: Single Elimination, Double Elimination, Gauntlet, Team Relay, and **Survivor** (one big multi-player GP per round, last finisher out, deathboard view, configurable eliminations-per-round for big fields)
- **Fantasy predictions** — weekly MVP picks, head-to-head matchups, prop bets, with a **confidence picker** (Light ×1 / Medium ×2 / 🔒 Lock ×3) that multiplies both hits and misses. Leaderboard shows points, accuracy %, and locks-hit ratio.
- **Rivalry tracking** — pairwise head-to-head records, Nemesis Index (the tightest 50/50 matchups), rivalry web visualization
- **Badge system** — 27+ career milestone badges auto-awarded (first podium, perfect cups, base/booster cup completion, streak records, character variety, and more), with unlock alerts on the racer page

### Statistics & history

- **Per-racer profile** — career stats, signature character/kart, Elo history, per-cup performance, badges, recent results, milestone alerts, coaching report
- **Cup mastery grid** — 24-cup × all-racers completion matrix with best scores
- **All-time records** — peak single-GP scores, longest podium streaks, win droughts, perfect 60 counts
- **Season archives** — every closed season preserved with final standings, awards, and a Hall of Fame entry. Mikkoliiga standings get their own sidebar in archived season reports.

### Display surfaces

- **Vertical signage** — 2048×2560 edge-to-edge for vertically-mounted screens
- **Horizontal signage** — 1920×1080 for TVs
- **Auto-vertical** — slide-rotating variant with news ticker
- **OBS overlay** — compact streaming overlay with **seven view modes** (standings, last GP, rivalry spotlight, fantasy leaderboard, Elo movers, cup-of-the-night, hide), keyboard hotkeys 0-6, URL params for OBS scene-switching, and optional `?rotate=N` auto-cycling
- **Shareable graphics** — exportable standings cards, rank graphics, animated season recaps

---

## Requirements

- PHP 8.0+
- SQLite3 (PHP extension)
- Apache with `mod_rewrite` enabled
- [Google Gemini API key](https://aistudio.google.com/) — required for any AI feature. The system has zero AI dependency for core scoring/standings; all Gemini paths fail gracefully.

---

## Quick Start

```bash
# 1. Clone the repo
git clone https://codeberg.org/tanketom/Kartfolio.git
cd kartfolio

# 2. Create the database
sqlite3 private/data/league.db < private/data/schema.sql

# 3. Set up config
cp private/config/config.example.php private/config/config.php
# Edit config.php — set your admin password and (optionally) Gemini API key

# 4. Point Apache at public_html/
# Example virtual host:
#   DocumentRoot /path/to/kartfolio/public_html
#   <Directory /path/to/kartfolio/public_html>
#       AllowOverride All
#       Require all granted
#   </Directory>

# 5. Visit your site and log in at /login
```

### Generating a secure admin password

```bash
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT) . PHP_EOL;"
```

Paste the hash into `config.php`. Plaintext passwords also work for development.

### Inline migrations

Schema changes ship as idempotent `ALTER TABLE` statements in `private/includes/db.php`, which run on every request. Pulling a new version doesn't require running migrations manually — just hit any page and the database catches up.

---

## Project Structure

```
├── public_html/                       # Web root (point Apache here)
│   ├── .htaccess                      # Clean URL routing
│   ├── index.php                      # Homepage + current standings
│   ├── add_result.php                 # GP result entry
│   ├── racer.php                      # Per-racer profile (with coaching report)
│   ├── archive.php                    # Broadcast feed + publish forms
│   ├── view_recap.php                 # Single broadcast viewer
│   ├── stats.php                      # Power rankings
│   ├── rivalries.php                  # H2H + Nemesis Index
│   ├── rivalry_web.php                # Force-directed rivalry graph
│   ├── cup_stats.php                  # Per-cup statistics
│   ├── cup_mastery.php                # 24-cup completion grid
│   ├── badges_overview.php            # Career badge progress
│   ├── all_time.php                   # Cross-season leaderboard
│   ├── timeline.php                   # Season-by-season timeline
│   ├── fantasy.php                    # Fantasy predictions + leaderboard
│   ├── mikkoliiga.php                 # Casual sub-league standings
│   ├── mh_dashboard.php               # MONSTER HUNT dashboard
│   ├── stories.php                    # MONSTER HUNT Chronicles archive
│   ├── season_archives.php            # Hall of Fame
│   ├── view_season_report.php         # Archived season report
│   ├── overlay.php                    # OBS streaming overlay (7 views)
│   ├── vertical.php                   # 2048×2560 signage
│   ├── horizontal.php                 # 1920×1080 signage
│   ├── auto-vertical.php              # Auto-rotating signage
│   ├── pick_cup.php                   # Weighted random cup picker
│   ├── about.php                      # About page
│   ├── admin/                         # Admin panel
│   │   ├── settings.php               # League identity, features, colors
│   │   ├── racers.php                 # Roster management (with Mikkoliiga flag)
│   │   ├── seasons.php                # Season config (12 scoring systems)
│   │   ├── close_season.php           # Season-close wizard (snapshots Mikkoliiga + awards)
│   │   ├── season_awards.php          # AI-assisted awards ceremony
│   │   ├── results_manage.php         # Result editing
│   │   ├── tournaments.php            # Tournament index
│   │   ├── tournament_create.php      # New tournament (5 formats)
│   │   ├── tournament_setup.php       # Bracket generation
│   │   ├── tournament_bracket.php     # Bracket viewer + match recording
│   │   ├── edit_recap.php             # Broadcast editor
│   │   └── import-database.php        # Backup restore
│   ├── api/                           # JSON / form endpoints
│   │   ├── gemini_recap.php           # AI broadcast generator
│   │   ├── gemini_power_rankings.php  # AI power rankings
│   │   ├── gemini_tournament_recap.php # AI tournament recap
│   │   ├── generate_season_report.php # AI season report
│   │   ├── generate_season_awards.php # AI season awards
│   │   ├── generate_gp_story.php      # MONSTER HUNT Chronicle generator
│   │   ├── generate_coaching_report.php # Per-racer coaching
│   │   ├── publish_press_release.php  # OMK Press Office (no AI)
│   │   ├── record_tournament_match.php # Tournament match recording
│   │   ├── simulate_scoring.php       # What-if scoring sandbox
│   │   ├── update_season_report.php
│   │   └── delete_recap.php
│   └── assets/                        # CSS, JS, images
│       ├── css/
│       │   ├── global.css             # Base styles
│       │   ├── pages.css              # Standard page layouts
│       │   ├── racer.css              # Racer profile
│       │   ├── admin.css              # Admin panel
│       │   ├── forms.css              # Form widgets
│       │   ├── screen-h.css           # Horizontal signage
│       │   ├── screen-v.css           # Vertical signage
│       │   └── underground.css        # Themed alt
│       └── img/                       # Character + program portraits (gitignored)
└── private/
    ├── config/
    │   └── config.example.php         # Copy → config.php, add your secrets
    ├── data/
    │   ├── schema.sql                 # Canonical schema for fresh installs
    │   ├── tournament_schema.sql      # Tournament tables (auto-applied)
    │   └── league.db                  # SQLite DB (gitignored)
    ├── includes/                      # Shared PHP libraries
    │   ├── db.php                     # DB connection + inline migrations
    │   ├── gp_logic.php               # Scoring engine + system registry
    │   ├── elo_engine.php             # All-time Elo calculator
    │   ├── badges.php                 # Badge unlock logic
    │   ├── card_rendering.php         # Trading card SVG
    │   ├── auth.php                   # Admin auth
    │   ├── csrf.php                   # CSRF protection
    │   ├── settings.php               # Dynamic site settings
    │   ├── ecology_text.php           # Broadcast persona prompts
    │   ├── gemini_client.php          # Shared retry-with-backoff Gemini caller
    │   ├── season_awards_logic.php    # Awards generation pipeline
    │   ├── coaching_stats.php         # Per-racer stats gathering for coaching
    │   ├── survivor_tournament.php    # Survivor format engine
    │   ├── mk_data.php                # MK character/cup constants
    │   └── programs.php               # News program catalog (AI + hand-written)
    └── templates/                     # Header/footer partials
```

---

## Scoring systems

Every season picks one of the twelve. New systems plug into a registry in `gp_logic.php::getScoringSystemRegistry()` — one entry per system, no edits required to the dispatch sites.

| Key | Display | Mechanics |
|---|---|---|
| `average_attendance` | Average + Attendance (GPScore™) | Avg of scores after drops + attendance bonus capped per week |
| `preseason` | Pre-Season | Simple average with 10% drop, off-season scoring |
| `cup_based` | Cup-Based | Best score per cup, sum across N required cups (12 or 24) |
| `best_n_gps` | Best N GPs | Sum of your top N GP scores, drop the rest |
| `drop_worst` | Drop Worst | Play all cups, drop the X worst scores |
| `perfect_hunt` | Perfect Hunt | Bonus multipliers on each perfect 60 |
| `top_12_unique` | Top 12 Unique | Best 12 GPs from 12 separate cups; tiebreaker = most 60s |
| `random_cup_draw` | Random Cup Draw | Each racer assigned random cups to complete |
| `black_box` | Black Box | Opaque formula with equalizer mechanics — leaderboard is plausible but unpredictable |
| `monster_hunt` | MONSTER HUNT | XP per GP; the highest-Elo participant is the Monster each GP (alphabetical tiebreak; admins can override per-result), others slay them for CR-scaled XP. Best 20 hunts counted. |
| `bounty_hunter` | Bounty Hunter | Every racer above field Elo median carries a bounty (= Elo above median). Beat them in a GP to collect (full, per beater). Configurable carrying-cost penalty. |
| `pari_mutuel` | Pari-Mutuel | All participants pay an ante; pot redistributes by finish. Net per GP = winnings − ante (can go negative). Three payout curves: steep/medium/flat. |

---

## Tournament formats

Created at `/admin/tournaments` → `/admin/tournament-create`. Five formats, all wired through the same recording flow (`api/record_tournament_match.php`):

- **Single Elimination** — Match-based; 4 per match → top 2 advance (or 2-3 player matches with top 1 advancing)
- **Double Elimination** — Winners + Losers brackets; lose twice and you're out
- **Gauntlet** — One Boss defends against all challengers in sequence
- **Team Relay** — Snake-drafted teams race legs; cumulative wins advance
- **Survivor** — One big multi-player GP per round, bottom finisher(s) eliminated each round, deathboard view, configurable eliminations-per-round for big fields

---

## Configuration

Site-wide settings live in `/admin/settings`:

- **League identity** — name, tagline, primary/secondary colors, governing body
- **Features** — toggle broadcasts, rivalries, tournaments
- **Wall code** — four-digit numeric code that must be entered on the GP-result form (prevents drive-by submissions)

Gemini model and API key live in `private/config/config.php`:

```php
return [
    'gemini_api_key' => '...',
    'admin_password' => 'plaintext-or-hash',
    'model_name'     => 'gemini-2.5-flash',  // primary; fallback chain handles overloads
];
```

The shared Gemini client (`gemini_client.php`) automatically retries on HTTP 503/429/UNAVAILABLE with 1s/2s/4s backoff, then falls through to `gemini-2.5-flash-lite` → `gemini-2.0-flash` → `gemini-2.0-flash-lite`. Cumulative per-model errors are surfaced to the admin on hard failure.

---

## Character images

The engine expects Mario Kart character portraits at `public_html/assets/img/{CharacterName}.png` (e.g. `Mario.png`, `Princess Peach.png`). These are not included in the repo due to copyright; the UI gracefully falls back to a generic Mii portrait.

The broadcast program icons (`program_press_office.png`, `program_core_team.png`, etc.) live in the same folder.

---

## Architecture notes

A few patterns worth knowing about if you're hacking on the code:

- **Scoring system registry** — adding a new system means one entry in `getScoringSystemRegistry()` plus the `calculate*Score` and `breakdown*` helpers. The five legacy switches (`calculateGPScore`, `getScoringSystemInfo`, `getScoringBreakdown`, `racerQualifies`, `sortStandingsByScoring`) all consult the registry, so no dispatch-site edits are needed.
- **Inline migrations** — `db.php` runs `ALTER TABLE ... ADD COLUMN` and `CREATE TABLE IF NOT EXISTS` blocks on every request, all wrapped in catch-and-ignore. Idempotent. Pull → reload → up-to-date.
- **Mikkoliiga membership snapshotting** — live seasons read the `racers.in_mikkoliiga` flag; archived seasons read from `mikkoliiga_membership(season_id, racer_id)` which is frozen at season-close time. Historical standings can't shift retroactively.
- **Single source of truth for static data** — MK character/cup lists live in `mk_data.php`. News program catalog lives in `programs.php`. Don't re-declare them in calling files.
- **CSRF everywhere** — every POST form ships with `csrf_field()`; every POST handler calls `verify_csrf()`. Token check happens before any state mutation.

---

## License

MIT
