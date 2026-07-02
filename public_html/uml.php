<?php
/**
 * System architecture — UML class diagram + data flow.
 *
 * Hidden URL (/uml). Renders two Mermaid diagrams: an entity-relationship
 * class diagram of the database schema, and a higher-level data-flow
 * diagram showing which code modules read or write which tables.
 *
 * Path: /cdnmk/public_html/uml.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');

$pageTitle = 'System UML - Kartfolio';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/map">Site map</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">UML</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🗂️ System UML</h1>
        <p class="page-subtitle">DATA MODEL &amp; FLOW · <?= strtoupper(htmlspecialchars($leagueName)) ?></p>
    </header>

    <div class="uml-intro">
        <p>
            Two views: a class diagram of the database tables and how they reference each other, and a flow diagram of which code modules read or write what. Both are rendered live from <a href="https://mermaid.js.org/" target="_blank">Mermaid</a> source — you can edit the source inline at the bottom and re-render.
        </p>
    </div>

    <h2 class="uml-section-title">1 · Database schema (class diagram)</h2>
    <p class="uml-section-sub">Every persistent table, its columns, and the foreign-key (or logical) relationships between them.</p>
    <div class="mermaid uml-diagram" id="uml-classes">
classDiagram
    direction LR

    namespace Core {
        class Racer {
            +int id PK
            +string name
            +string nickname
            +string catchphrase
            +bool in_mikkoliiga
        }
        class Result {
            +int id PK
            +string gpid
            +int racer_id FK
            +int gp_points
            +int rank
            +string character_used
            +string kart_setup
            +string cup_name
            +bool is_lol
            +bool is_monster
            +datetime race_date
        }
        class SeasonMeta {
            +string season_id PK
            +string status
            +string scoring_system
            +date start_date
            +date end_date
            +string season_name
            +string champion_name
            +string ecology_report
            +int min_races_threshold
            +int mh_slay_xp
            +int mh_best_x
            +float bh_multiplier
            +int pm_ante
            +string pm_payout_preset
        }
        class Settings {
            +int id PK
            +string setting_key
            +string setting_value
            +string setting_type
            +string category
        }
    }

    namespace SubLeagues {
        class MikkoliigaMembership {
            +string season_id FK
            +int racer_id FK
            +datetime snapshotted_at
        }
    }

    namespace Media {
        class RecapArchive {
            +int id PK
            +string season_id FK
            +string program_key
            +string headline
            +string key_quote
            +text recap_text
            +string linked_gpids
            +datetime created_at
        }
        class GpStories {
            +int id PK
            +string gpid PK
            +string season_id FK
            +text story_text
            +text story_data
            +datetime generated_at
        }
    }

    namespace Tournaments {
        class Tournament {
            +int id PK
            +string name
            +string format
            +string status
            +int winner_id FK
            +string season_id FK
            +string tiebreaker_rule
            +int eliminations_per_round
            +datetime start_date
            +datetime end_date
        }
        class TournamentParticipant {
            +int id PK
            +int tournament_id FK
            +int racer_id FK
            +int seed
            +int elo_at_registration
            +int final_placement
        }
        class TournamentMatch {
            +int id PK
            +int tournament_id FK
            +string round
            +int match_number
            +string bracket
            +int player1_id FK
            +int player2_id FK
            +int winner_id FK
            +string gpid
            +int num_participants
            +int num_advance
            +string status
        }
        class TournamentMatchParticipant {
            +int id PK
            +int match_id FK
            +int racer_id FK
            +int placement
            +int points
            +string character_used
            +bool is_winner
        }
        class TournamentRace {
            +int id PK
            +int match_id FK
            +int race_number
            +string gpid
            +int winner_id FK
        }
        class TournamentTrophy {
            +int id PK
            +int tournament_id FK
            +int racer_id FK
            +int placement
            +string trophy_type
        }
    }

    namespace Fantasy {
        class FantasyPredictor {
            +int id PK
            +int racer_id FK
            +string guest_name
        }
        class FantasyWeek {
            +int id PK
            +string week_key PK
            +datetime deadline
            +bool scored
        }
        class FantasyBet {
            +int id PK
            +string week_key FK
            +int predictor_id FK
            +string bet_type
            +string bet_key
            +string bet_value
            +int confidence
            +int points_earned
        }
    }

    namespace Reports {
        class SeasonAward {
            +int id PK
            +string season_id FK
            +string award_category
            +string winner_name
            +string status
        }
        class CoachingReport {
            +int id PK
            +int racer_id FK
            +text body
            +string model_used
            +string season_id
            +datetime generated_at
        }
    }

    namespace Voting {
        class TrackPreference {
            +int id PK
            +string voter_id
            +string winner_track
            +string loser_track
            +datetime voted_at
        }
    }

    %% ── Relationships ─────────────────────────────────────────────
    Racer "1" --> "*" Result : raced in
    SeasonMeta "1" ..> "*" Result : gpid prefix
    Racer "1" --> "*" TournamentParticipant
    Racer "1" --> "*" TournamentMatchParticipant
    Racer "1" --> "*" TournamentTrophy
    Racer "1" --> "0..1" FantasyPredictor
    Racer "1" --> "*" CoachingReport
    Racer "1" --> "*" MikkoliigaMembership : snapshot at season close

    SeasonMeta "1" --> "*" RecapArchive
    SeasonMeta "1" --> "*" GpStories
    SeasonMeta "1" --> "*" SeasonAward
    SeasonMeta "1" --> "*" MikkoliigaMembership
    SeasonMeta "1" --> "*" CoachingReport

    Tournament "1" --> "*" TournamentParticipant
    Tournament "1" --> "*" TournamentMatch
    Tournament "1" --> "*" TournamentTrophy
    TournamentMatch "1" --> "*" TournamentMatchParticipant
    TournamentMatch "1" --> "*" TournamentRace

    FantasyWeek "1" --> "*" FantasyBet
    FantasyPredictor "1" --> "*" FantasyBet
    </div>

    <h2 class="uml-section-title">2 · Data flow (which code modules produce what)</h2>
    <p class="uml-section-sub">Logical view of how user actions, scoring engines, and AI pipelines move data through the system.</p>
    <div class="mermaid uml-diagram" id="uml-flow">
flowchart LR
    classDef storage fill:#1a1408,stroke:#FFD700,color:#fff,stroke-width:2px
    classDef engine  fill:#222,stroke:#888,color:#fff
    classDef ai      fill:#1a0820,stroke:#9b59b6,color:#fff
    classDef user    fill:#0a1a14,stroke:#2EBD59,color:#fff
    classDef page    fill:#0e0e0e,stroke:#aaa,color:var(--gray-600)

    %% Storage nodes
    DB[(SQLite<br/>league.db)]:::storage
    Imgs[/Track + character images<br/>assets/img/.../]:::storage

    %% External
    Gemini((Google Gemini)):::ai
    Wiki((MK Fandom wiki<br/>image source)):::ai

    %% User actions
    AddGP([Player logs GP<br/>via /add-result]):::user
    Vote([Track vote<br/>via /track-favourites]):::user
    Pick([What Cup? modal]):::user
    Press([OMK Press Office<br/>publish]):::user
    AdminGen([Admin clicks<br/>Generate Awards / Recap]):::user

    %% Engines
    GpLogic[gp_logic.php<br/>12 scoring systems<br/>Mikkoliiga + MH + Bounty + Pari-Mutuel]:::engine
    Elo[elo_engine.php<br/>All-time Elo]:::engine
    Survivor[survivor_tournament.php<br/>Survivor format]:::engine
    Awards[season_awards_logic.php<br/>fixed + AI awards]:::engine
    TrackRank[track_ranking.php<br/>Elo for tracks]:::engine
    GemClient[gemini_client.php<br/>retry + 4-model fallback]:::engine
    Programs[programs.php<br/>AI personas + Press Office]:::engine
    MkData[mk_data.php<br/>cups + tracks + characters]:::engine

    %% Pages
    Index[/index.php<br/>standings/]:::page
    Racer[/racer.php<br/>profile + badges/]:::page
    Stats[/cup-stats / stats / power-rankings/]:::page
    Fantasy[/fantasy.php<br/>confidence picks/]:::page
    Overlay[/overlay.php<br/>7 hotkey views/]:::page
    Signage[/vertical, horizontal,<br/>auto-vertical/]:::page

    %% Action → write paths
    AddGP --> DB
    Vote --> DB
    Press --> DB
    Pick -.calls.-> GpLogic
    Pick --> AddGP

    AdminGen --> Awards
    AdminGen --> GemClient

    Awards --> GemClient
    GemClient --> Gemini
    GemClient --> DB

    %% Read paths through engines to pages
    DB --> GpLogic
    DB --> Elo
    DB --> TrackRank
    DB --> Survivor

    GpLogic --> Index
    GpLogic --> Stats
    GpLogic --> Fantasy
    GpLogic --> Overlay
    GpLogic --> Signage

    Elo --> Stats
    Elo --> Fantasy
    Elo --> Overlay
    Elo --> Survivor

    TrackRank --> Stats
    TrackRank --> Index

    %% Other engines
    MkData -.uses.-> GpLogic
    MkData -.uses.-> TrackRank
    Programs -.uses.-> Awards
    Programs -.uses.-> Index

    %% External image source
    Wiki -.fetch helper.-> Imgs
    Imgs --> Stats
    Imgs --> Racer
    </div>

    <h2 class="uml-section-title">3 · Edit the source</h2>
    <p class="uml-section-sub">Both diagrams are plain Mermaid text. Paste either source into <a href="https://mermaid.live/" target="_blank">mermaid.live</a> to export as SVG/PNG, or copy-edit the source below and click <em>Re-render</em> to test changes in place.</p>

    <div class="uml-editor-card">
        <label class="uml-editor-label">Class diagram source</label>
        <textarea id="uml-classes-src" class="uml-editor" rows="20" spellcheck="false"></textarea>
        <button type="button" class="uml-btn" onclick="rerender('uml-classes')">Re-render class diagram</button>
    </div>

    <div class="uml-editor-card">
        <label class="uml-editor-label">Flow diagram source</label>
        <textarea id="uml-flow-src" class="uml-editor" rows="20" spellcheck="false"></textarea>
        <button type="button" class="uml-btn" onclick="rerender('uml-flow')">Re-render flow diagram</button>
    </div>
</div>

<style>
.uml-intro {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 4px solid #FFD700;
    border-radius: 8px;
    padding: 16px 22px;
    margin-bottom: 28px;
    color: var(--gray-600);
    line-height: 1.55;
}
.uml-intro a { color: #FFD700; }
.uml-section-title {
    margin-top: 32px;
    font-size: 1.1rem;
    color: #fff;
    border-bottom: 1px solid #2a2a2a;
    padding-bottom: 6px;
}
.uml-section-sub { color: #888; margin: 6px 0 14px; font-size: 0.9rem; font-style: italic; }
.uml-section-sub a { color: #FFD700; }
.uml-diagram {
    background: var(--gray-100);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 24px;
    overflow-x: auto;
    text-align: center;
}
.uml-editor-card {
    background: var(--gray-50);
    border: 1px solid #1f1f1f;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 18px;
}
.uml-editor-label { display: block; font-size: 0.8rem; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.uml-editor {
    width: 100%;
    background: #050505;
    color: #e0e0e0;
    border: 1px solid #2a2a2a;
    border-radius: 4px;
    font-family: ui-monospace, "SF Mono", Menlo, Monaco, "Courier New", monospace;
    font-size: 0.78rem;
    padding: 10px;
    line-height: 1.45;
    resize: vertical;
}
.uml-btn {
    margin-top: 10px;
    background: #FFD700;
    color: #3a2c00;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    font-weight: 800;
    cursor: pointer;
    font-size: 0.85rem;
}
.uml-btn:hover { background: #e6b900; }
</style>

<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
<script>
mermaid.initialize({
    startOnLoad: true,
    theme: 'base',
    themeVariables: {
        background: '#fafafa',
        primaryColor: '#fff6dc',
        primaryTextColor: '#222',
        primaryBorderColor: '#e8c850',
        lineColor: '#666',
        secondaryColor: '#e6f6ec',
        tertiaryColor: '#f4eeee'
    },
    classDiagram: { useMaxWidth: true },
    flowchart: { useMaxWidth: true, htmlLabels: true }
});

// Pre-fill the editor textareas with the current rendered source so the
// admin can copy-modify-rerender without round-tripping through a file.
window.addEventListener('DOMContentLoaded', () => {
    const grabSource = (id) => {
        const el = document.getElementById(id);
        // mermaid replaces the diagram div's textContent with SVG, so grab
        // the original from a data attribute we set before init.
        return el ? (el.dataset.originalSource || '') : '';
    };
    // Stash originals BEFORE mermaid runs.
    ['uml-classes', 'uml-flow'].forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.originalSource) el.dataset.originalSource = el.textContent;
    });

    setTimeout(() => {
        const ce = document.getElementById('uml-classes-src');
        const fe = document.getElementById('uml-flow-src');
        if (ce) ce.value = document.getElementById('uml-classes').dataset.originalSource.trim();
        if (fe) fe.value = document.getElementById('uml-flow').dataset.originalSource.trim();
    }, 50);
});

async function rerender(targetId) {
    const srcEl = document.getElementById(targetId + '-src');
    const target = document.getElementById(targetId);
    if (!srcEl || !target) return;
    const src = srcEl.value.trim();
    target.removeAttribute('data-processed');
    target.innerHTML = src;
    try {
        const id = targetId + '-svg-' + Date.now();
        const { svg } = await mermaid.render(id, src);
        target.innerHTML = svg;
    } catch (e) {
        target.innerHTML = '<pre style="color:#c0392b; text-align:left; white-space:pre-wrap;">Render error: ' + (e.message || e) + '</pre>';
    }
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
