<?php
/**
 * Self-service profiles: each racer can set their own nickname and catchphrase
 * with a personal code, instead of asking the commissioner.
 *
 * Those two fields feed the trading cards, the profile and — since the
 * newsroom gained nicknames — every broadcast, so the people named in them now
 * have a reason to care what is there.
 *
 * The code is generated on /admin/racers and handed over in person, like the
 * wall code. Only its bcrypt hash is stored, so it is shown exactly once. The
 * public form is POST + CSRF and throttled per IP, the same as every other
 * public write (§11). The admin can still edit both fields and can reissue a
 * code at any time, which also revokes the old one.
 */

require_once __DIR__ . '/db.php';

const PROFILE_NICKNAME_MAX    = 40;
const PROFILE_CATCHPHRASE_MAX = 120;

/**
 * A six-character code, shown as ABC-234. The alphabet leaves out 0/O, 1/I/L
 * and 5/S so it survives being read aloud or scrawled on a note.
 * 30^6 ≈ 730 million, against a throttle of 10 guesses per 10 minutes.
 */
function profileCodeGenerate(): string {
    $alphabet = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($code, 0, 3) . '-' . substr($code, 3);
}

/** Case, spaces and the dash never matter when the code is typed back in. */
function profileCodeNormalize(string $input): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
}

/** Issue a new code for a racer; returns the plain code. Revokes any old one. */
function profileCodeIssue(PDO $pdo, int $racerId): string {
    $code = profileCodeGenerate();
    $pdo->prepare("UPDATE racers SET profile_code_hash = ? WHERE id = ?")
        ->execute([password_hash(profileCodeNormalize($code), PASSWORD_DEFAULT), $racerId]);
    return $code;
}

function profileHasCode(PDO $pdo, int $racerId): bool {
    $st = $pdo->prepare("SELECT profile_code_hash FROM racers WHERE id = ?");
    $st->execute([$racerId]);
    return (string)$st->fetchColumn() !== '';
}

function profileCodeCheck(PDO $pdo, int $racerId, string $input): bool {
    $st = $pdo->prepare("SELECT profile_code_hash FROM racers WHERE id = ?");
    $st->execute([$racerId]);
    $hash = (string)$st->fetchColumn();
    if ($hash === '') return false;
    return password_verify(profileCodeNormalize($input), $hash);
}

/**
 * Tidy what a racer typed. Control characters — newlines above all — are
 * collapsed to spaces: these strings are pasted into the broadcast briefing,
 * and a line break is how text in a nickname would try to pass itself off as
 * a fresh instruction. Then trimmed and cut to length.
 */
function profileCleanText(string $input, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input) ?? '';
    $s = trim(preg_replace('/\s{2,}/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}
