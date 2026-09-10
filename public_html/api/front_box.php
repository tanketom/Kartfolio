<?php
/**
 * Cards for the homepage's rotating box. Read-only JSON, fetched after the
 * homepage has already rendered so none of this is on its critical path.
 *
 * See private/includes/front_box.php for what each card is and why the
 * expensive ones are cached rather than computed per request.
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/front_box.php';

header('Content-Type: application/json; charset=utf-8');
// The cards change at most once per GP (or once per fantasy hour), so a short
// shared cache keeps a busy game night off the Elo engine entirely.
header('Cache-Control: public, max-age=120');

try {
    echo json_encode(['cards' => frontBoxCards($pdo)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('front_box: ' . $e->getMessage());
    // An empty list is a valid answer: the box just stays hidden.
    echo json_encode(['cards' => []]);
}
