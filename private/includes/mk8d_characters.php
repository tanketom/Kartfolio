<?php
/**
 * Mario Kart 8 Deluxe character list — back-compat forwarder.
 *
 * The canonical data now lives in mk_data.php (cups + characters together).
 * This file remains so existing callers (e.g. admin/tournament_bracket.php)
 * continue to work; new code should require_once mk_data.php directly.
 *
 * Path: /cdnmk/private/includes/mk8d_characters.php
 */
require_once __DIR__ . '/mk_data.php';
