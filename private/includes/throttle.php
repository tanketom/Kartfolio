<?php
/**
 * Per-IP rate limit on the auth_throttle table (the same one the login and
 * wall-code throttles use). throttleAllow() records the attempt and says
 * whether it is within the window's limit.
 */
require_once __DIR__ . '/session.php';   // clientIp()

function throttleAllow(PDO $pdo, string $action, int $limit, int $minutes): bool {
    $ip = clientIp();
    $pdo->prepare("DELETE FROM auth_throttle WHERE attempted_at < datetime('now', '-1 day')")->execute();
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_throttle WHERE ip = ? AND action = ? AND attempted_at > datetime('now', ?)");
    $st->execute([$ip, $action, '-' . $minutes . ' minutes']);
    if ((int)$st->fetchColumn() >= $limit) return false;
    $pdo->prepare("INSERT INTO auth_throttle (ip, action) VALUES (?, ?)")->execute([$ip, $action]);
    return true;
}
