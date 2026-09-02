<?php
/**
 * Settings Helper Functions
 * Path: /cdnmk/private/includes/settings.php
 */

/**
 * Initialize settings table
 */
function initializeSettings($pdo) {
    try {
        $schemaPath = __DIR__ . '/../data/settings_schema.sql';
        if (file_exists($schemaPath)) {
            $pdo->exec(file_get_contents($schemaPath));
        }
    } catch (PDOException $e) {
        error_log("Settings initialization error: " . $e->getMessage());
    }
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
