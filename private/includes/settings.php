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
    static $cache = [];

    // Check cache first
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return $default;
        }

        // Convert value based on type
        $value = $result['setting_value'];
        switch ($result['setting_type']) {
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'number':
                $value = is_numeric($value) ? (int)$value : $default;
                break;
            default:
                // text, color, textarea - keep as string
                break;
        }

        $cache[$key] = $value;
        return $value;

    } catch (PDOException $e) {
        error_log("Error getting setting $key: " . $e->getMessage());
        return $default;
    }
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
