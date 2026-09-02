<?php
/**
 * Simulation cache — for pages whose output is expensive to compute and a
 * pure function of a few inputs (the Crystal Ball Monte Carlo: ~430 ms of
 * PHP per view, and a different random answer every time).
 *
 * Callers build a key from everything the computation depends on (season,
 * results-table signature, today's date, parameters). A hit costs one
 * query; a miss computes, stores, and evicts the same page's older keys.
 * The one write per new key is deliberate — it happens once per new GP or
 * new day, not once per view.
 *
 * Path: /cdnmk/private/includes/sim_cache.php
 */

/** Decoded payload for $key, or null on a miss. */
function simCacheGet(PDO $pdo, string $key): ?array {
    try {
        $st = $pdo->prepare("SELECT payload FROM sim_cache WHERE cache_key = ?");
        $st->execute([$key]);
        $raw = $st->fetchColumn();
        if ($raw === false) return null;
        $v = json_decode((string)$raw, true);
        return is_array($v) ? $v : null;
    } catch (PDOException $e) { return null; }
}

/** Store $value under $key; drop this page's older keys ($prefix = key up to the first ':'). */
function simCachePut(PDO $pdo, string $key, array $value): void {
    try {
        $prefix = strstr($key, ':', true) ?: $key;
        $pdo->prepare("DELETE FROM sim_cache WHERE cache_key LIKE ? AND cache_key != ?")->execute([$prefix . ':%', $key]);
        $pdo->prepare("INSERT OR REPLACE INTO sim_cache (cache_key, payload, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
            ->execute([$key, json_encode($value)]);
    } catch (PDOException $e) { /* cache is best-effort */ }
}
