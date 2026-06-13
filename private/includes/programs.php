<?php
/**
 * News programs catalog — single source of truth.
 *
 * Every page that maps a program_key → display label / icon / color used
 * to declare its own copy of this map (and they drifted). Now they all
 * pull from here.
 *
 * Path: /cdnmk/private/includes/programs.php
 */

/**
 * Returns the catalog of recap_archive "programs" (broadcast shows).
 *
 * Each entry has:
 *   - label  : display name
 *   - img    : filename in /assets/img/
 *   - color  : accent color (used by view_recap.php)
 *   - ai     : true for AI-generated programs, false for hand-written
 */
function getProgramsCatalog(): array {
    static $catalog = null;
    if ($catalog !== null) return $catalog;

    $catalog = [
        'press_office'       => ['label' => 'OMK Press Office',           'img' => 'program_press_office.png',       'color' => '#0066CC', 'ai' => false],
        'core_team'          => ['label' => 'Kart Core Team',             'img' => 'program_core_team.png',          'color' => '#e60012', 'ai' => true],
        'reef_dispatch'      => ['label' => 'Reef’s Dispatch',            'img' => 'program_reef_dispatch.png',      'color' => '#2c3e50', 'ai' => true],
        'meta_report'        => ['label' => 'The Meta Report',            'img' => 'program_meta_report.png',        'color' => '#27ae60', 'ai' => true],
        'the_rant'           => ['label' => 'The Rant',                   'img' => 'program_the_rant.png',           'color' => '#c0392b', 'ai' => true],
        'ghost_racer'        => ['label' => 'The Ghost Racer’s Ascent',   'img' => 'program_ghost_racer.png',        'color' => '#8e44ad', 'ai' => true],
        'situated_spectator' => ['label' => 'The Situated Spectator',     'img' => 'program_situated_spectator.png', 'color' => '#f39c12', 'ai' => true],
        'viberacing'         => ['label' => 'Viberacing',                 'img' => 'program_viberacing.png',         'color' => '#ff00ff', 'ai' => true],
        'random'             => ['label' => '🎲 Surprise Me',             'img' => 'program_default.png',            'color' => '#333',    'ai' => true],
    ];
    return $catalog;
}

/**
 * Look up a program by key, falling back to a safe default if the key is
 * unknown (legacy records, deleted programs).
 */
function getProgramInfo(string $key): array {
    $catalog = getProgramsCatalog();
    return $catalog[$key] ?? [
        'label' => 'Unknown Broadcast',
        'img'   => 'program_default.png',
        'color' => '#333',
        'ai'    => true,
    ];
}

/**
 * Subset of the catalog that goes through AI generation. Used in admin
 * dropdowns when picking a broadcast to generate — hand-written programs
 * like OMK Press Office shouldn't appear there.
 */
function getAIProgramsCatalog(): array {
    return array_filter(getProgramsCatalog(), fn($p) => !empty($p['ai']));
}
