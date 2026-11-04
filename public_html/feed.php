<?php
/**
 * RSS — /feed. The league's news, for anyone who would rather not visit.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/feed.php';

if (!moduleEnabled($pdo, 'broadcasts')) { http_response_code(404); exit; }

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');
echo rssFeedXml($pdo, getSetting($pdo, 'league_name', 'Kartfolio'));
