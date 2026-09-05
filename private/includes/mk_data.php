<?php
/**
 * Mario Kart 8 Deluxe static data — single source of truth.
 *
 * Cup lists, character roster, and per-cup emoji lookup. Every page that
 * needs to enumerate cups or characters should pull from here instead of
 * declaring its own copy (which historically drifted out of sync).
 *
 * Path: /cdnmk/private/includes/mk_data.php
 */

// ── Cups ──────────────────────────────────────────────────────────────────

/** The 12 Base Game cups, in canonical Mario Kart order. */
/** A perfect Grand Prix: 4 races × 15 points. The magic number 60 lived in ~30 places. */
const MK_MAX_GP_POINTS = 60;

/**
 * Most human players a single Grand Prix can have. MK8 Deluxe raised local
 * multiplayer from 4 to 8; the AI fills the remaining karts up to the 12-place
 * finishing field (which is why rank stays 1–12, not 1–8). The add-result form
 * renders this many racer rows; scoring/Elo already count real participants.
 */
const MK_MAX_HUMAN_PLAYERS = 8;

/** 1 → "1st", 2 → "2nd", 11 → "11th", 22 → "22nd". */
function ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
}

const MK_BASE_CUPS = [
    'Mushroom', 'Flower', 'Star', 'Special',
    'Shell', 'Banana', 'Leaf', 'Lightning',
    'Egg', 'Triforce', 'Crossing', 'Bell',
];

/** The 12 Booster Course Pass cups, in canonical Mario Kart order. */
const MK_BOOSTER_CUPS = [
    'Golden Dash', 'Lucky Cat', 'Turnip', 'Propeller',
    'Rock', 'Moon', 'Fruit', 'Boomerang',
    'Feather', 'Cherry', 'Acorn', 'Spiny',
];

/** All 24 cups, base first then booster. */
function getMKAllCups(): array {
    return array_merge(MK_BASE_CUPS, MK_BOOSTER_CUPS);
}

/** Cups grouped by source — used in the cup picker dropdown on add_result. */
function getMKCupsByGroup(): array {
    return [
        'Base Game'           => MK_BASE_CUPS,
        'Booster Course Pass' => MK_BOOSTER_CUPS,
    ];
}

/**
 * Emoji per cup, used in dropdowns and broadcast headers. Falls back to '🏆'
 * for any cup not explicitly mapped.
 */
/**
 * A colour pair per cup — the trading card's portrait background blends a
 * racer's two best cups. Every cup has one so no card falls back to grey.
 */
function getMKCupColors(): array {
    return [
        // Base game
        'Mushroom'    => ['#f36f8f', '#e0203c'],   // red cap, pink spots
        'Flower'      => ['#ff9a3c', '#ffd23f'],   // fire flower orange → yellow
        'Star'        => ['#ffe25a', '#f5b400'],   // star gold
        'Special'     => ['#4facfe', '#00f2fe'],   // crown blue → cyan
        'Shell'       => ['#2ec27e', '#12a37a'],   // koopa green
        'Banana'      => ['#fff0a3', '#f9c74f'],   // banana yellow
        'Leaf'        => ['#a3d977', '#5aa64c'],   // tanooki leaf green
        'Lightning'   => ['#8f7cff', '#5b3fd6'],   // lightning violet
        'Egg'         => ['#c9f2a1', '#7fd18a'],   // yoshi egg pastel
        'Triforce'    => ['#e6d38a', '#b89b2e'],   // hyrule gold
        'Bell'        => ['#9ad7ff', '#4a9be8'],   // super bell sky
        'Crossing'    => ['#b8e29a', '#6bb84c'],   // animal crossing leaf
        // Booster Course Pass
        'Golden Dash' => ['#ffd36e', '#e08a12'],   // gold → bronze
        'Lucky Cat'   => ['#ff7a7a', '#d4a017'],   // maneki-neko red → gold
        'Turnip'      => ['#e6c4f0', '#8fd18f'],   // turnip lilac → leaf
        'Propeller'   => ['#a8d8ff', '#6d8fb3'],   // sky → steel
        'Rock'        => ['#d9a066', '#8c5a2b'],   // sandstone → earth
        'Moon'        => ['#3d3f8f', '#9aa0c8'],   // night indigo → moonlight
        'Fruit'       => ['#ff8fa3', '#e0324b'],   // berry pink → red
        'Boomerang'   => ['#5fc9c1', '#e8c98a'],   // teal → sand
        'Feather'     => ['#c9b8ff', '#f4f0ff'],   // violet → white
        'Cherry'      => ['#ff6b81', '#b3122e'],   // cherry pink → deep red
        'Acorn'       => ['#c99a5b', '#7a4a1e'],   // acorn tan → brown
        'Spiny'       => ['#4a7bd6', '#8a4bd6'],   // spiny blue → purple
    ];
}

function getMKCupColor(string $cup): array {
    return getMKCupColors()[$cup] ?? ['#c0c0c0', '#a0a0a0'];
}

function getMKCupEmoji(string $cup): string {
    static $map = [
        // Base
        'Banana'    => '🍌',
        'Bell'      => '🔔',
        'Crossing'  => '🍃',
        'Egg'       => '🥚',
        'Flower'    => '🌺',
        'Leaf'      => '🍃',
        'Lightning' => '⚡',
        'Mushroom'  => '🍄',
        'Shell'     => '🐢',
        'Special'   => '👑',
        'Star'      => '⭐',
        'Triforce'  => '▲',
        // Booster
        'Acorn'       => '🌰',
        'Boomerang'   => '🪃',
        'Cherry'      => '🍒',
        'Feather'     => '🪶',
        'Fruit'       => '🍓',
        'Golden Dash' => '🍄',
        'Lucky Cat'   => '🐱',
        'Moon'        => '🌙',
        'Propeller'   => '🔴',
        'Rock'        => '🪨',
        'Spiny'       => '🔵',
        'Turnip'      => '🌱',
    ];
    return $map[$cup] ?? '🏆';
}

// ── Tracks ────────────────────────────────────────────────────────────────

/**
 * The 96 MK8 Deluxe tracks, grouped by their parent cup (4 tracks per cup).
 *
 * NOTE: Names are best-effort from memory. If a track here doesn't match
 * what your players call it, just edit the value — the rest of the system
 * keys on the strings as-is.
 *
 * Retro tracks are prefixed with their original-game tag (Wii, GCN, SNES,
 * N64, GBA, DS, 3DS, Tour) to match Nintendo's in-game labelling.
 */
function getMKTracksByCup(): array {
    static $tracks = null;
    if ($tracks !== null) return $tracks;
    $tracks = [
        // ── Base game (48 tracks) ────────────────────────────────────────
        'Mushroom'  => ['Mario Kart Stadium', 'Water Park', 'Sweet Sweet Canyon', 'Thwomp Ruins'],
        'Flower'    => ['Mario Circuit', 'Toad Harbor', 'Twisted Mansion', 'Shy Guy Falls'],
        'Star'      => ['Sunshine Airport', 'Dolphin Shoals', 'Electrodrome', 'Mount Wario'],
        'Special'   => ['Cloudtop Cruise', 'Bone-Dry Dunes', "Bowser's Castle", 'Rainbow Road'],
        'Shell'     => ['Wii Moo Moo Meadows', 'GBA Mario Circuit', 'DS Cheep Cheep Beach', "N64 Toad's Turnpike"],
        'Banana'    => ['GCN Dry Dry Desert', 'SNES Donut Plains 3', 'N64 Royal Raceway', '3DS DK Jungle'],
        'Leaf'      => ['DS Wario Stadium', 'GCN Sherbet Land', '3DS Music Park', 'N64 Yoshi Valley'],
        'Lightning' => ['DS Tick-Tock Clock', '3DS Piranha Plant Slide', 'Wii Grumble Volcano', 'N64 Rainbow Road'],
        'Egg'       => ['GCN Yoshi Circuit', 'Excitebike Arena', 'Dragon Driftway', 'Mute City'],
        'Triforce'  => ["Wii Wario's Gold Mine", 'SNES Rainbow Road', 'Ice Ice Outpost', 'Hyrule Circuit'],
        'Crossing'  => ['GCN Baby Park', 'GBA Cheese Land', 'Wild Woods', 'Animal Crossing'],
        'Bell'      => ['3DS Neo Bowser City', 'GBA Ribbon Road', 'Super Bell Subway', 'Big Blue'],

        // ── Booster Course Pass (48 tracks) ──────────────────────────────
        'Golden Dash' => ['Tour Paris Promenade', '3DS Toad Circuit', 'N64 Choco Mountain', 'Wii Coconut Mall'],
        'Lucky Cat'   => ['Tour Tokyo Blur', 'DS Shroom Ridge', 'GBA Sky Garden', 'Tour Ninja Hideaway'],
        'Turnip'      => ['Tour New York Minute', 'SNES Mario Circuit 3', 'N64 Kalimari Desert', 'DS Waluigi Pinball'],
        'Propeller'   => ['Tour Sydney Sprint', 'GBA Snow Land', 'Wii Mushroom Gorge', 'Sky-High Sundae'],
        'Rock'        => ['Tour London Loop', 'GBA Boo Lake', '3DS Rock Rock Mountain', 'Wii Maple Treeway'],
        'Moon'        => ['Tour Berlin Byways', 'DS Peach Gardens', 'Tour Merry Mountain', '3DS Rainbow Road'],
        'Fruit'       => ['Tour Amsterdam Drift', 'GBA Riverside Park', 'Wii DK Summit', "Yoshi's Island"],
        'Boomerang'   => ['Tour Bangkok Rush', 'DS Mario Circuit', 'GCN Waluigi Stadium', 'Tour Singapore Speedway'],
        'Feather'     => ['Tour Athens Dash', 'GCN Daisy Cruiser', 'Wii Moonview Highway', 'Squeaky Clean Sprint'],
        'Cherry'      => ['Tour Los Angeles Laps', 'GBA Sunset Wilds', 'Wii Koopa Cape', 'Tour Vancouver Velocity'],
        'Acorn'       => ['Tour Rome Avanti', 'Wii Daisy Circuit', 'Piranha Plant Cove', 'Tour Madrid Drive'],
        'Spiny'       => ['3DS Rosalina\'s Ice World', 'SNES Bowser Castle 3', 'Wii Rainbow Road', 'GCN DK Mountain'],
    ];
    return $tracks;
}

/** Flat list of all 96 tracks. */
function getMKAllTracks(): array {
    static $flat = null;
    if ($flat !== null) return $flat;
    $flat = [];
    foreach (getMKTracksByCup() as $tracks) {
        foreach ($tracks as $t) $flat[] = $t;
    }
    return $flat;
}

/** Reverse lookup: track name → parent cup. */
function getMKTrackCup(string $track): ?string {
    static $reverse = null;
    if ($reverse === null) {
        $reverse = [];
        foreach (getMKTracksByCup() as $cup => $tracks) {
            foreach ($tracks as $t) $reverse[$t] = $cup;
        }
    }
    return $reverse[$track] ?? null;
}

/** Emoji for a track = its parent cup's emoji (or 🏁 if unknown). */
function getMKTrackEmoji(string $track): string {
    $cup = getMKTrackCup($track);
    return $cup ? getMKCupEmoji($cup) : '🏁';
}

/**
 * Filename slug for a track image — lowercase, ASCII, underscored.
 *   "Mario Kart Stadium"   → "mario_kart_stadium"
 *   "Bowser's Castle"      → "bowsers_castle"
 *   "N64 Toad's Turnpike"  → "n64_toads_turnpike"
 *   "Sky-High Sundae"      → "sky_high_sundae"
 *
 * The voting page renders <img src="/assets/img/tracks/{slug}.png"> with
 * an emoji fallback so the UI keeps working even when no image is present.
 */
function getMKTrackImageSlug(string $track): string {
    $s = strtolower($track);
    // Strip apostrophes outright.
    $s = str_replace("'", '', $s);
    // Map everything that isn't a-z0-9 to an underscore.
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    // Collapse and trim underscores.
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

/**
 * URL slug for a cup — lowercase kebab-case. Used by /cup/<slug> routing.
 *   "Mushroom"     → "mushroom"
 *   "Golden Dash"  → "golden-dash"
 */
function getMKCupSlug(string $cup): string {
    $s = strtolower($cup);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** Reverse lookup: slug → original cup name. Returns null if unknown. */
function getMKCupFromSlug(string $slug): ?string {
    static $reverse = null;
    if ($reverse === null) {
        $reverse = [];
        foreach (getMKAllCups() as $cup) {
            $reverse[getMKCupSlug($cup)] = $cup;
        }
    }
    return $reverse[$slug] ?? null;
}

/** Retro-era tag parsed from the leading prefix of a track name. */
function getMKTrackEra(string $track): ?string {
    static $tags = ['SNES', 'N64', 'GCN', 'GBA', 'DS', '3DS', 'Wii', 'Tour'];
    foreach ($tags as $tag) {
        if (str_starts_with($track, $tag . ' ')) return $tag;
    }
    return null; // Native MK8 Deluxe track
}

// ── Characters ────────────────────────────────────────────────────────────

/** The full MK8 Deluxe roster (base + Booster Pass). Sorted for consistency. */
function getMKCharacters(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $chars = [
        // Base Game
        'Mario', 'Luigi', 'Peach', 'Daisy', 'Rosalina', 'Tanooki Mario', 'Cat Peach', 'Yoshi',
        'Toad', 'Koopa Troopa', 'Shy Guy', 'Lakitu', 'Toadette', 'King Boo', 'Baby Mario',
        'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina', 'Metal Mario', 'Pink Gold Peach',
        'Wario', 'Waluigi', 'Donkey Kong', 'Bowser', 'Dry Bones', 'Bowser Jr.', 'Dry Bowser',
        'Lemmy', 'Larry', 'Wendy', 'Ludwig', 'Iggy', 'Roy', 'Morton',
        'Inkling Girl', 'Inkling Boy', 'Link', 'Villager (Male)', 'Villager (Female)',
        'Isabelle', 'Mii', 'Gold Mario',
        // Booster Pass
        'Birdo', 'Diddy Kong', 'Funky Kong', 'Pauline', 'Peachette', 'Kamek',
        'Wiggler', 'Petey Piranha',
    ];
    sort($chars);
    return $cached = $chars;
}

/**
 * Back-compat alias for older callers. New code should use getMKCharacters().
 */
function getMK8DCharacters(): array {
    return getMKCharacters();
}
