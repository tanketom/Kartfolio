<?php
/**
 * The web app manifest — /manifest.webmanifest. Lets a phone add the site to its
 * home screen and open it like an app: the league's own name and colour, the OMK
 * seal as the icon, no browser bar. Built from settings so every league gets its own.
 *
 * No service worker on purpose: an offline copy of the standings would show last
 * week's table on race night, and an installed page reloads from the network anyway.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode(kartfolioManifest($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
