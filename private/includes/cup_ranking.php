<?php
/**
 * Cup preference ranking — thin adapter over preference_ranking.php
 * (prefConfig('cup')). Kept so existing call sites read naturally.
 * Path: /cdnmk/private/includes/cup_ranking.php
 */
require_once __DIR__ . '/preference_ranking.php';

function cupPrefVoterId(): string                                { return prefVoterId('cup'); }
function cupRankings(PDO $pdo): array                            { return prefRankings($pdo, 'cup'); }
function pickCupPair(PDO $pdo, string $voterId): array           { return prefPickPair($pdo, 'cup', $voterId); }
function cupPrefTotalVotes(PDO $pdo): int                        { return prefTotalVotes($pdo, 'cup'); }
function cupPrefVoterVotes(PDO $pdo, string $voterId): int       { return prefVoterVotes($pdo, 'cup', $voterId); }
