<?php
/**
 * Backfill historical Monster picks into the results.is_monster flag.
 *
 * Why: pickMonster() in gp_logic.php has changed mechanisms over time
 * (highest-Elo → top-2-via-crc32 → highest-Elo). Because scoring is
 * recomputed live from results rows, changing the algorithm silently
 * rescores every historical GP — which players have already played and
 * earned XP under.
 *
 * This script freezes whatever Monster was credited at the time by
 * replaying the OLD top-2-via-crc32 algorithm against every existing
 * MONSTER HUNT season GP and stamping is_monster=1 on that racer's
 * result row. pickMonster() consults the flag first, so once stamped,
 * the algorithm change doesn't disturb the historical result.
 *
 * Idempotent: GPs that already have a Monster flag are skipped.
 * Only touches results in seasons whose scoring_system = 'monster_hunt'.
 *
 * Usage:
 *   php bin/backfill_monster_flags.php --dry-run   # show what would happen
 *   php bin/backfill_monster_flags.php             # actually write the flags
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$dryRun = in_array('--dry-run', $argv, true);

echo "Backfilling is_monster flags for historical MONSTER HUNT season GPs…\n";
echo $dryRun ? "  --dry-run: no DB writes\n" : "  (will UPDATE results.is_monster = 1)\n";
echo str_repeat('-', 78) . "\n";

// Which seasons are MONSTER HUNT?
$mhSeasons = $pdo->query("SELECT season_id FROM season_meta WHERE scoring_system = 'monster_hunt'")
    ->fetchAll(PDO::FETCH_COLUMN);
if (empty($mhSeasons)) {
    echo "No MONSTER HUNT seasons in the database. Nothing to backfill.\n";
    exit(0);
}
echo "MONSTER HUNT seasons: " . implode(', ', $mhSeasons) . "\n\n";

// Pre-GP Elo for everyone, for every GP — same data the scoring engine uses.
$changelog = getMonsterHuntEloChangelog($pdo);

$updated = 0;
$skipped = 0;
$noData  = 0;

foreach ($mhSeasons as $sid) {
    $gpStmt = $pdo->prepare("SELECT DISTINCT gpid FROM results WHERE gpid LIKE ? ORDER BY gpid ASC");
    $gpStmt->execute([$sid . '%']);
    $gpids = $gpStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($gpids as $gpid) {
        $gpData = $changelog[$gpid] ?? [];
        if (count($gpData) < 2) {
            $noData++;
            continue;
        }

        // Is a Monster already flagged on this GP?
        $existing = $pdo->prepare("SELECT r.name FROM results res JOIN racers r ON res.racer_id = r.id WHERE res.gpid = ? AND res.is_monster = 1 LIMIT 1");
        $existing->execute([$gpid]);
        $existingName = $existing->fetchColumn();
        if ($existingName) {
            printf("  %s  already flagged (%s) — skipped\n", $gpid, $existingName);
            $skipped++;
            continue;
        }

        // Replay the OLD algorithm: top-2 by Elo (alphabetical tiebreak),
        // crc32(gpid) % count picks index 0 or 1.
        $ranked = [];
        foreach ($gpData as $name => $d) {
            $ranked[] = ['name' => $name, 'elo' => (float)$d['old_elo']];
        }
        usort($ranked, function ($a, $b) {
            if ($a['elo'] !== $b['elo']) return $b['elo'] <=> $a['elo'];
            return strcmp($a['name'], $b['name']);
        });
        $candidates  = array_slice($ranked, 0, 2);
        $pick        = (count($candidates) > 1) ? (crc32((string)$gpid) % count($candidates)) : 0;
        $monsterName = $candidates[$pick]['name'];

        // Resolve to racer_id.
        $ridStmt = $pdo->prepare("SELECT id FROM racers WHERE name = ?");
        $ridStmt->execute([$monsterName]);
        $monsterRacerId = $ridStmt->fetchColumn();
        if (!$monsterRacerId) {
            printf("  %s  ✗ couldn't resolve racer \"%s\" to an id\n", $gpid, $monsterName);
            continue;
        }

        printf("  %s  → Monster = %s\n", $gpid, $monsterName);

        if (!$dryRun) {
            $upd = $pdo->prepare("UPDATE results SET is_monster = 1 WHERE gpid = ? AND racer_id = ?");
            $upd->execute([$gpid, $monsterRacerId]);
            $updated++;
        }
    }
}

echo str_repeat('-', 78) . "\n";
echo "Stamped: $updated · Already-flagged (skipped): $skipped · No Elo data: $noData\n";
if ($dryRun) {
    echo "\n(--dry-run — no rows changed. Re-run without --dry-run to apply.)\n";
}
