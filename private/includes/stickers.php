<?php
/**
 * Sticker Packs — the Panini-style collection system.
 *
 * The rules (locked with the commissioner):
 *   - 1 GP raced = 1 pack of 3 stickers, granted automatically on result
 *     entry, but only from the STICKERS_EPOCH onward (next season's start).
 *   - Every racer also gets one 5-sticker Founders Pack, granted lazily on
 *     first visit to their album page.
 *   - ANYONE can open anyone's packs (trust-the-room policy; CSRF only).
 *   - Collect-only v1 — no trading. Duplicates count up as ×N.
 *   - Pack contents are DETERMINISTIC: seeded from crc32 of the pack's
 *     identity, so contents are fixed the moment the pack is granted and
 *     survive restores. Opening only reveals.
 *
 * Catalog = registry pattern (single source of truth, like scoring systems).
 * Seven sets; Kartificial is sticker #001. Art resolution:
 *   - racers     → the racer's career-main character portrait (trading-card art)
 *   - characters → existing /assets/img/<Name>.png portraits
 *   - tracks     → existing /assets/img/tracks/<slug>.png screenshots
 *   - cups/items/lore/moments → /assets/img/stickers/<slug>.png, emoji fallback
 *
 * Path: /cdnmk/private/includes/stickers.php
 */

require_once __DIR__ . '/mk_data.php';

/** Packs only drop for GPs logged on/after this date (override via settings key stickers_epoch). */
const STICKERS_EPOCH_DEFAULT = '2026-06-20';

const STICKER_PACK_SIZE    = 3;
const STICKER_FOUNDERS_SIZE = 5;

/** Rarity draw weights (per slot). */
const STICKER_WEIGHTS = ['common' => 70, 'uncommon' => 22, 'rare' => 6, 'foil' => 2];

/**
 * The full ordered catalog. Memoized per request. Each entry:
 *   key, set, title, rarity, slug, img (path|null), emoji, num (album #).
 */
function stickerCatalog(PDO $pdo): array {
    static $cat = null;
    if ($cat !== null) return $cat;

    $e = [];

    // ── 1. League Lore (Kartificial leads the album) ──
    $lore = [
        ['kartificial',        'Kartificial',         'foil',     '🍄', 'img' => '/assets/img/kartificial.png'],
        ['omk_crest',          'OMK Crest',           'uncommon', '🏛️', 'img' => '/assets/img/program_press_office.png'],
        ['ludwig_obstruction', 'Ludwig Obstruction',  'foil',     '⚠️'],
        ['wall_code',          'The Wall Code',       'uncommon', '🔢'],
        ['the_monster',        'The Monster',         'rare',     '👹'],
        ['mac',                'Mac',                 'rare',     '🚩'],
        ['honk',             'Honk!',              'foil',     '🪿'],
        ['gpscore',            'GPScore™',            'common',   '™️'],
        ['mikkoliiga_star',    'Mikkoliiga Star',     'uncommon', '🌟'],
        ['gameslab',      'The Games Lab',   'common',   '🧱'],
        ['glhf', 'GLHF Pledge',  'common',   '📰'],
        ['blue_shell_survivor','Blue Shell Survivor', 'rare',     '🩹'],
    ];
    foreach ($lore as $row) {
        $e[] = ['key' => 'lore_' . $row[0], 'set' => 'lore', 'title' => $row[1],
                'rarity' => $row[2], 'slug' => 'lore_' . $row[0], 'emoji' => $row[3],
                'img' => $row['img'] ?? null];
    }

    // ── 2. Racers (dynamic, uncommon; face = career-main character art) ──
    $mainChar = [];
    $cm = $pdo->query("
        SELECT racer_id, character_used, COUNT(*) AS c FROM results
        WHERE character_used IS NOT NULL AND character_used != ''
        GROUP BY racer_id, character_used ORDER BY c ASC
    ");
    foreach ($cm->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mainChar[(int)$row['racer_id']] = $row['character_used']; // last (highest c) wins
    }
    foreach ($pdo->query("SELECT id, name FROM racers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $char = $mainChar[(int)$r['id']] ?? 'Mii';
        $e[] = ['key' => 'racer_' . $r['id'], 'set' => 'racers', 'title' => $r['name'],
                'rarity' => 'uncommon', 'slug' => 'racer_' . $r['id'], 'emoji' => '🏎️',
                'img' => '/assets/img/' . rawurlencode($char) . '.png'];
    }

    // ── 3. Cups (24, common) ──
    foreach (getMKAllCups() as $cup) {
        $slug = 'cup_' . getMKCupSlug($cup);
        $e[] = ['key' => $slug, 'set' => 'cups', 'title' => $cup . ' Cup',
                'rarity' => 'common', 'slug' => $slug, 'emoji' => getMKCupEmoji($cup), 'img' => null];
    }

    // ── 4. Characters (full roster, common; reuse portraits) ──
    foreach (getMKCharacters() as $char) {
        $slug = 'char_' . getMKTrackImageSlug($char);
        $e[] = ['key' => $slug, 'set' => 'characters', 'title' => $char,
                'rarity' => 'common', 'slug' => $slug, 'emoji' => '🎮',
                'img' => '/assets/img/' . rawurlencode($char) . '.png'];
    }

    // ── 5. Items (18) ──
    $items = [
        ['banana',          'Banana',           'common',   '🍌'],
        ['triple_banana',   'Triple Banana',    'common',   '🍌'],
        ['green_shell',     'Green Shell',      'common',   '🐢'],
        ['red_shell',       'Red Shell',        'common',   '🔴'],
        ['spiny_shell',      'Spiny Shell',      'rare',     '🔵'],
        ['mushroom',        'Mushroom',         'common',   '🍄'],
        ['triple_mushroom', 'Triple Mushroom',  'common',   '🍄'],
        ['golden_mushroom', 'Golden Mushroom',  'uncommon', '✨'],
        ['star',            'Super Star',       'uncommon', '⭐'],
        ['lightning',       'Lightning',        'uncommon', '⚡'],
        ['bullet_bill',     'Bullet Bill',      'uncommon', '🚀'],
        ['blooper',         'Blooper',          'common',   '🦑'],
        ['bobomb',          'Bob-omb',          'common',   '💣'],
        ['fire_flower',     'Fire Flower',      'common',   '🔥'],
        ['boomerang',       'Boomerang Flower', 'common',   '🪃'],
        ['piranha',         'Piranha Plant',    'common',   '🌱'],
        ['super_horn',      'Super Horn',       'rare',     '📯'],
        ['coin',            'Coin',             'common',   '🪙'],
    ];
    foreach ($items as $row) {
        $e[] = ['key' => 'item_' . $row[0], 'set' => 'items', 'title' => $row[1],
                'rarity' => $row[2], 'slug' => 'item_' . $row[0], 'emoji' => $row[3], 'img' => null];
    }

    // ── 6. Legendary Tracks (11 fixed + the live Fan Favourite foil) ──
    $legendaryTracks = [
        'Rainbow Road', 'N64 Rainbow Road', 'Wii Rainbow Road', 'GCN Baby Park',
        'Mount Wario', 'Big Blue', 'Mute City', 'Wii Coconut Mall',
        'DS Waluigi Pinball', 'Hyrule Circuit', 'Animal Crossing',
    ];
    foreach ($legendaryTracks as $track) {
        $slug = 'track_' . getMKTrackImageSlug($track);
        $e[] = ['key' => $slug, 'set' => 'tracks', 'title' => $track,
                'rarity' => 'uncommon', 'slug' => $slug,
                'emoji' => getMKTrackEmoji($track),
                'img' => '/assets/img/tracks/' . getMKTrackImageSlug($track) . '.png'];
    }
    // The Fan Favourite — subject resolved live from the track-preference Elo.
    $fav = null;
    try {
        if (!function_exists('trackRankings')) require_once __DIR__ . '/track_ranking.php';
        $rank = trackRankings($pdo);
        uasort($rank, fn($a, $b) => $b['elo'] <=> $a['elo']);
        $fav = array_key_first($rank);
    } catch (Throwable $ex) { /* no votes yet — emoji face */ }
    $e[] = ['key' => 'track_fan_favourite', 'set' => 'tracks',
            'title' => 'Fan Favourite' . ($fav ? ': ' . $fav : ''),
            'rarity' => 'foil', 'slug' => 'track_fan_favourite', 'emoji' => '🏁',
            'img' => $fav ? '/assets/img/tracks/' . getMKTrackImageSlug($fav) . '.png' : null];

    // ── 7. Moments (15, rare/foil-leaning) ──
    $moments = [
        ['perfect_60',          'Perfect 60',             'foil',     '💎'],
        ['hat_trick',           'Hat Trick',              'rare',     '🎩'],
        ['monster_slain',       'Monster Slain',          'rare',     '⚔️'],
        ['wc_champion',         'World Cup Champion',     'foil',     '🌍'],
        ['comeback',            'The Comeback',           'rare',     '🎪'],
        ['photo_finish',        'Photo Finish',           'rare',     '📸'],
        ['wooden_spoon',        'Crazy Eight',            'common',   '🥄'],
        ['giant_killer',        'Giant Killer',           'uncommon', '🥊'],
        ['finnish',             'Finnish Line',           'rare',     '💀'],
        ['what_cup',            'What cup? What cup.',    'rare',     '🔮'],
        ['tim',                 'Just Tim Things',        'foil',     '👑'],
        ['blue_shell',          'Last Straight Shelling', 'rare',     '🏆'],
        ['ascended',            'Ascended (2000 Elo)',    'rare',     '🧗'],
        ['on_a_roll',           'On a Roll',              'uncommon', '🌀'],
        ['first_win',           'First Win',              'common',   '🚩'],
    ];
    foreach ($moments as $row) {
        $e[] = ['key' => 'moment_' . $row[0], 'set' => 'moments', 'title' => $row[1],
                'rarity' => $row[2], 'slug' => 'moment_' . $row[0], 'emoji' => $row[3], 'img' => null];
    }

    // Album numbering, in catalog order (Kartificial = #001).
    foreach ($e as $i => &$entry) $entry['num'] = $i + 1;
    unset($entry);

    return $cat = $e;
}

/** key => entry. */
function stickerByKey(PDO $pdo): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (stickerCatalog($pdo) as $s) $map[$s['key']] = $s;
    }
    return $map;
}

/** rarity => [keys]. */
function stickerKeysByRarity(PDO $pdo): array {
    static $tiers = null;
    if ($tiers === null) {
        $tiers = ['common' => [], 'uncommon' => [], 'rare' => [], 'foil' => []];
        foreach (stickerCatalog($pdo) as $s) $tiers[$s['rarity']][] = $s['key'];
    }
    return $tiers;
}

/**
 * Deterministic pack contents: seed fixes both the rarity rolls and the
 * within-tier picks. Same pack row → same stickers, forever.
 */
function stickerPackContents(PDO $pdo, int $seed, int $size): array {
    $tiers = stickerKeysByRarity($pdo);
    mt_srand($seed);
    $out = [];
    for ($i = 0; $i < $size; $i++) {
        $roll = mt_rand(1, 100);
        $acc = 0; $tier = 'common';
        foreach (STICKER_WEIGHTS as $t => $w) {
            $acc += $w;
            if ($roll <= $acc) { $tier = $t; break; }
        }
        $pool = $tiers[$tier] ?: $tiers['common'];
        $out[] = $pool[mt_rand(0, count($pool) - 1)];
    }
    mt_srand(); // restore entropy for everyone else
    return $out;
}

/** The active epoch date (Y-m-d). */
function stickersEpoch(PDO $pdo): string {
    if (!function_exists('getSetting')) require_once __DIR__ . '/settings.php';
    return getSetting($pdo, 'stickers_epoch', STICKERS_EPOCH_DEFAULT) ?: STICKERS_EPOCH_DEFAULT;
}

/**
 * Grant one GP pack per racer for a freshly logged GP — no-op before the
 * epoch. Called from add_result.php inside its transaction.
 * Returns the number of packs granted.
 */
function grantGpPacks(PDO $pdo, string $gpid, array $racerIds): int {
    if (date('Y-m-d') < stickersEpoch($pdo)) return 0;
    $ins = $pdo->prepare("INSERT INTO racer_packs (racer_id, source, gpid, seed, size) VALUES (?, 'gp', ?, ?, ?)");
    $n = 0;
    foreach ($racerIds as $rid) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        $ins->execute([$rid, $gpid, crc32($gpid . ':' . $rid), STICKER_PACK_SIZE]);
        $n++;
    }
    return $n;
}

/** Lazily grant the one-time 5-sticker Founders Pack. */
function ensureFoundersPack(PDO $pdo, int $racer_id): void {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM racer_packs WHERE racer_id = ? AND source = 'founders'");
    $chk->execute([$racer_id]);
    if ((int)$chk->fetchColumn() > 0) return;
    $pdo->prepare("INSERT INTO racer_packs (racer_id, source, gpid, seed, size) VALUES (?, 'founders', NULL, ?, ?)")
        ->execute([$racer_id, crc32('founders:' . $racer_id), STICKER_FOUNDERS_SIZE]);
}

/**
 * Open a pack: reveal its (predetermined) contents into the album.
 * Returns the revealed entries with an is_new flag, or null if the pack
 * doesn't exist / is already open.
 */
function openPack(PDO $pdo, int $packId): ?array {
    $pStmt = $pdo->prepare("SELECT * FROM racer_packs WHERE id = ? AND opened_at IS NULL");
    $pStmt->execute([$packId]);
    $pack = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pack) return null;

    $keys = stickerPackContents($pdo, (int)$pack['seed'], (int)$pack['size']);
    $defs = stickerByKey($pdo);

    $own = $pdo->prepare("SELECT count FROM racer_stickers WHERE racer_id = ? AND sticker_key = ?");
    $up  = $pdo->prepare("
        INSERT INTO racer_stickers (racer_id, sticker_key, count) VALUES (?, ?, 1)
        ON CONFLICT(racer_id, sticker_key) DO UPDATE SET count = count + 1
    ");
    $revealed = [];
    foreach ($keys as $k) {
        $own->execute([(int)$pack['racer_id'], $k]);
        $had = $own->fetchColumn();
        $up->execute([(int)$pack['racer_id'], $k]);
        $revealed[] = ($defs[$k] ?? ['key' => $k, 'title' => $k, 'rarity' => 'common', 'emoji' => '❔', 'img' => null, 'set' => '?', 'num' => 0])
            + ['is_new' => ($had === false)];
    }
    $pdo->prepare("UPDATE racer_packs SET opened_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$packId]);
    return $revealed;
}

/** Owned map for a racer: sticker_key => count. */
function racerAlbum(PDO $pdo, int $racer_id): array {
    $stmt = $pdo->prepare("SELECT sticker_key, count FROM racer_stickers WHERE racer_id = ?");
    $stmt->execute([$racer_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Per-set progress: set => ['owned','total']. Plus '_album' overall. */
function albumProgress(PDO $pdo, int $racer_id): array {
    $owned = racerAlbum($pdo, $racer_id);
    $prog = [];
    $allOwned = 0; $allTotal = 0;
    foreach (stickerCatalog($pdo) as $s) {
        $prog[$s['set']] ??= ['owned' => 0, 'total' => 0];
        $prog[$s['set']]['total']++;
        $allTotal++;
        if (isset($owned[$s['key']])) { $prog[$s['set']]['owned']++; $allOwned++; }
    }
    $prog['_album'] = ['owned' => $allOwned, 'total' => $allTotal];
    return $prog;
}

// ============================================================================
// ADMIN BOARD HELPERS
// ============================================================================

/** Filesystem dir holding bespoke sticker art. */
function stickerArtDir(): string { return __DIR__ . '/../../public_html/assets/img/stickers/'; }

/** Does a card have its bespoke art present? (img-backed cards count as covered.)
 *  Accepts either a .png or .jpg file at the card's slug. */
function stickerHasArt(array $s): bool {
    if (!empty($s['img'])) return true;   // reuses an existing asset (portrait/track/etc.)
    $d = stickerArtDir();
    return is_file($d . $s['slug'] . '.png') || is_file($d . $s['slug'] . '.jpg');
}

/**
 * Displayable image URL for a card: an explicit asset path if set, otherwise
 * the bespoke art file (png OR jpg) if present, else the .png path so the
 * onerror→emoji fallback still fires. One place, used by every render site.
 */
function stickerArtUrl(array $s): string {
    if (!empty($s['img'])) return $s['img'];
    $base = '/assets/img/stickers/' . $s['slug'];
    $d = stickerArtDir();
    if (is_file($d . $s['slug'] . '.png')) return $base . '.png';
    if (is_file($d . $s['slug'] . '.jpg')) return $base . '.jpg';
    return $base . '.png';
}

/** Grant N admin packs of a given size to a racer. Each gets a unique seed so
 *  contents differ; the seed is stored, so the pack stays fixed once granted. */
function grantAdminPacks(PDO $pdo, int $racer_id, int $count, int $size, string $source = 'gift'): int {
    $ins = $pdo->prepare("INSERT INTO racer_packs (racer_id, source, gpid, seed, size) VALUES (?, ?, NULL, ?, ?)");
    $n = 0;
    for ($i = 0; $i < $count; $i++) {
        $seed = crc32('admin:' . $racer_id . ':' . $source . ':' . $i . ':' . microtime(true) . ':' . mt_rand());
        $ins->execute([$racer_id, $source, $seed, $size]);
        $n++;
    }
    return $n;
}

/** Award one specific sticker straight into a racer's album (dupes stack). */
function grantSticker(PDO $pdo, int $racer_id, string $sticker_key): bool {
    if (!isset(stickerByKey($pdo)[$sticker_key])) return false;
    $pdo->prepare("
        INSERT INTO racer_stickers (racer_id, sticker_key, count) VALUES (?, ?, 1)
        ON CONFLICT(racer_id, sticker_key) DO UPDATE SET count = count + 1
    ")->execute([$racer_id, $sticker_key]);
    return true;
}

/** League-wide ownership per card: sticker_key => ['owners'=>n, 'copies'=>n]. */
function stickerOwnershipStats(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query("SELECT sticker_key, COUNT(*) AS owners, SUM(count) AS copies
                          FROM racer_stickers GROUP BY sticker_key")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[$r['sticker_key']] = ['owners' => (int)$r['owners'], 'copies' => (int)$r['copies']];
    }
    return $map;
}

/** Completion leaderboard: each racer's distinct-stickers-owned, best first. */
function stickerCompletionBoard(PDO $pdo): array {
    return $pdo->query("
        SELECT r.id, r.name, COUNT(DISTINCT s.sticker_key) AS owned
        FROM racers r LEFT JOIN racer_stickers s ON s.racer_id = r.id
        GROUP BY r.id ORDER BY owned DESC, r.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Unopened pack count per racer: racer_id => n. */
function unopenedPackCounts(PDO $pdo): array {
    return array_map('intval', $pdo->query("
        SELECT racer_id, COUNT(*) FROM racer_packs WHERE opened_at IS NULL GROUP BY racer_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR));
}
