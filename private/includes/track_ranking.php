<?php
/**
 * Track preference ranking — thin adapter over preference_ranking.php
 * (prefConfig('track')). Kept so existing call sites read naturally.
 * Path: /cdnmk/private/includes/track_ranking.php
 */
require_once __DIR__ . '/preference_ranking.php';

function trackPrefVoterId(): string                              { return prefVoterId('track'); }
function trackRankings(PDO $pdo): array                          { return prefRankings($pdo, 'track'); }
function pickTrackPair(PDO $pdo, string $voterId): array         { return prefPickPair($pdo, 'track', $voterId); }
function trackPrefTotalVotes(PDO $pdo): int                      { return prefTotalVotes($pdo, 'track'); }
function trackPrefVoterVotes(PDO $pdo, string $voterId): int     { return prefVoterVotes($pdo, 'track', $voterId); }
