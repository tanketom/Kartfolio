# Kartfolio

A self-hosted Mario Kart 8 Deluxe league management system with AI-powered broadcasts, deep statistics, ELO ratings, tournament brackets, and more.

Built with PHP, SQLite, and vanilla JavaScript. No frameworks, no build step — just deploy and race.

## Features

- **GP Result Logging** — Log race results with OCR support, auto-GPID generation, and smart auto-fill
- **9 Scoring Systems** — Average/Attendance, Best N GPs, Drop Worst, Cup-Based, Perfect Hunt, Top 12 Unique Cups, Black Box, Random Cup Draw, and Preseason
- **Season Management** — Multiple seasons with configurable rules, grace periods, and finals weeks
- **ELO Ratings** — Dynamic player ratings with trend visualization
- **Power Rankings** — AI-generated weekly power rankings
- **AI Broadcasts** — Gemini-powered commentary in multiple "program" styles (sports desk, gonzo journalism, academic analysis, etc.)
- **Tournament System** — Single/double elimination brackets with multi-player support
- **Fantasy Predictions** — Weekly MVP picks, head-to-head matchups, and prop bets
- **Rivalry Tracking** — Head-to-head stats and rivalry web visualization
- **Season Awards** — Auto-determined core awards + AI-generated personalized awards
- **Badge System** — Achievement tracking with milestone alerts
- **Statistics** — Per-racer profiles, all-time records, cup stats, career timelines
- **Shareable Graphics** — Exportable standings cards, rank graphics, and season animations

## Requirements

- PHP 8.0+
- SQLite3 (PHP extension)
- Apache with `mod_rewrite` enabled
- [Google Gemini API key](https://aistudio.google.com/) (for AI features — optional)

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

# 4. Point Apache at the public_html directory
# Example virtual host:
#   DocumentRoot /path/to/kartfolio/public_html
#   <Directory /path/to/kartfolio/public_html>
#       AllowOverride All
#       Require all granted
#   </Directory>

# 5. Visit your site and log in at /login
```

### Generating a Secure Admin Password

```bash
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT) . PHP_EOL;"
```

Paste the resulting hash into `config.php`. Plaintext passwords also work for development.

## Project Structure

```
├── public_html/            # Web root (point Apache here)
│   ├── .htaccess           # URL routing
│   ├── index.php           # Homepage / current standings
│   ├── add_result.php      # GP result entry
│   ├── admin/              # Admin panel
│   ├── api/                # API endpoints (AI generation, etc.)
│   └── assets/             # CSS, JS, images
├── private/
│   ├── config/
│   │   └── config.example.php
│   ├── data/
│   │   ├── schema.sql      # Database schema
│   │   └── league.db       # SQLite database (gitignored)
│   ├── includes/           # PHP libraries
│   │   ├── db.php          # Database connection
│   │   ├── gp_logic.php    # Scoring engine
│   │   ├── auth.php        # Authentication
│   │   ├── csrf.php        # CSRF protection
│   │   ├── settings.php    # Dynamic site settings
│   │   └── discord.php     # Discord webhook notifications
│   └── templates/          # Header/footer templates
```

## Configuration

All league settings are configurable from the admin panel at `/admin/settings`:

- **League Identity** — Name, tagline, colors, governing body
- **Features** — Toggle broadcasts, rivalries, tournaments
- **Display** — Customize the look and feel

## Character Images

The engine expects Mario Kart character portraits at `public_html/assets/img/{CharacterName}.png`. These are not included in the repo due to copyright. The UI gracefully handles missing images with fallback icons.

You can source character images from fan wikis or create your own. File names should match the character names used in your result entries (e.g., `Mario.png`, `Princess Peach.png`).

## License

MIT
