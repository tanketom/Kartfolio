<?php
/**
 * Settings Helper Functions
 * Path: /cdnmk/private/includes/settings.php
 */

/**
 * Initialize settings table
 */
function initializeSettings($pdo) {
    // The settings table and its default rows are laid down by db.php's
    // versioned migration block (once per schema change). This used to exec
    // settings_schema.sql — a CREATE plus a 12-row INSERT OR IGNORE, i.e. a
    // write transaction — on every page render via header.php. Kept as a
    // no-op so existing callers (header, admin settings, setup) stay valid.
}

/**
 * Get a setting value by key
 * @param PDO $pdo
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSetting($pdo, $key, $default = null) {
    $map = settingsMap($pdo);
    if (!array_key_exists($key, $map)) return $default;   // a miss costs no query
    [$raw, $type] = $map[$key];
    switch ($type) {
        case 'boolean': return (bool)$raw;
        case 'number':  return is_numeric($raw) ? (int)$raw : $default;
        default:        return $raw;   // text, color, textarea - keep as string
    }
}

/**
 * The whole settings table, read once per request: key => [value, type].
 * Header + footer read six keys on every page — that was six queries, and a
 * key with no row (or a false/null value) re-queried on every call.
 * updateSetting() resets it so a write is visible in the same request.
 */
function settingsMap($pdo, bool $reset = false): array {
    static $map = null;
    if ($reset) { $map = null; return []; }
    if ($map === null) {
        $map = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value, setting_type FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $map[$r['setting_key']] = [$r['setting_value'], $r['setting_type']];
        } catch (PDOException $e) {
            error_log("Error loading settings: " . $e->getMessage());
        }
    }
    return $map;
}

/**
 * Get all settings
 * @param PDO $pdo
 * @return array
 */
function getAllSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM settings ORDER BY category, setting_key");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting all settings: " . $e->getMessage());
        return [];
    }
}

/**
 * Update a setting
 * @param PDO $pdo
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function updateSetting($pdo, $key, $value) {
    try {
        // Convert boolean to int for storage
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $stmt = $pdo->prepare("
            UPDATE settings
            SET setting_value = ?, updated_at = datetime('now')
            WHERE setting_key = ?
        ");
        $stmt->execute([$value, $key]);
        settingsMap($pdo, true);   // drop the per-request map so the write is visible now

        return true;
    } catch (PDOException $e) {
        error_log("Error updating setting $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Get settings grouped by category
 * @param PDO $pdo
 * @return array
 */
function getSettingsByCategory($pdo) {
    $allSettings = getAllSettings($pdo);
    $grouped = [];

    foreach ($allSettings as $setting) {
        $category = $setting['category'];
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }
        $grouped[$category][] = $setting;
    }

    return $grouped;
}

// ── Modules ─────────────────────────────────────────────────────────────────
/** Every switchable feature: key => setting row, title, icon, what it hides. */
function moduleCatalog(): array {
    return [
        'tournaments' => ['setting' => 'enable_tournaments', 'icon' => '🏆', 'title' => 'Tournaments',   'desc' => 'Brackets, Survivor, World Cup pick-em and the Hall of Fame. Players can run tournaments themselves while this is on.', 'hides' => 'the Tournaments menu entry, the tournament hub, pick-em and the tournaments Hall of Fame'],
        'fantasy'     => ['setting' => 'enable_fantasy',     'icon' => '🔮', 'title' => 'Fantasy',       'desc' => 'Weekly predictions with confidence picks, scored against the results.', 'hides' => '/fantasy and its links'],
        'stickers'    => ['setting' => 'enable_stickers',    'icon' => '🩹', 'title' => 'Sticker album', 'desc' => 'Packs dropped per GP, albums per racer, the admin sticker board.', 'hides' => 'the album pages and the album chip on profiles'],
        'mikkoliiga'  => ['setting' => 'enable_mikkoliiga',  'icon' => '🌟', 'title' => 'Mikkoliiga',    'desc' => 'The casual sub-league with its own best-10 table.', 'hides' => 'the menu entry, the homepage top-3 panel, member badges and the standings page'],
        'teams'       => ['setting' => 'enable_teams',       'icon' => '🤝', 'title' => 'Teams',         'desc' => 'Constructor scoring: racers grouped into teams, best N per GP.', 'hides' => 'the homepage team standings and /teams'],
        'cards'       => ['setting' => 'enable_cards',       'icon' => '🎴', 'title' => 'Trading cards', 'desc' => 'The printable season set with foil tiers and the OMK backing.', 'hides' => '/cards (the profile card stays)'],
        'wrapped'     => ['setting' => 'enable_wrapped',     'icon' => '🎁', 'title' => 'Wrapped',       'desc' => 'The December year-in-review per racer.', 'hides' => 'the Wrapped pages and the profile call-to-action'],
        'underground' => ['setting' => 'enable_underground', 'icon' => '⚠️', 'title' => 'Underground',   'desc' => "Waluigi's betting den, reachable from the footer's hidden link.", 'hides' => 'the page and the footer link'],
        'broadcasts'  => ['setting' => 'enable_broadcasts',  'icon' => '📻', 'title' => 'AI broadcasts', 'desc' => 'Gemini-written news programs (the Press Office stays available).', 'hides' => 'the generate form on the archive and the News desk generator'],
    ];
}

/** Is a module switched on? Unknown keys are on, so a new page never vanishes by accident. */
function moduleEnabled($pdo, string $module): bool {
    $cat = moduleCatalog();
    if (!isset($cat[$module])) return true;
    return (bool) getSetting($pdo, $cat[$module]['setting'], true);
}

/** Gate a module's page: 404 with a short page when the module is off. */
function requireModule($pdo, string $module): void {
    if (moduleEnabled($pdo, $module)) return;
    http_response_code(404);
    $title = moduleCatalog()[$module]['title'] ?? ucfirst($module);
    $pageTitle = "$title is switched off";
    include __DIR__ . '/../templates/header.php';
    echo '<div class="container"><div class="empty-state"><h1>' . htmlspecialchars($title) . ' is switched off</h1><p>A commissioner can switch it back on under Admin → Modules.</p><p><a href="/" class="btn btn-secondary">← Back to the standings</a></p></div></div>';
    include __DIR__ . '/../templates/footer.php';
    exit;
}

