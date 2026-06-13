<?php
/**
 * Fetch MK8 Deluxe track images from the Mario Kart wiki.
 *
 * Strategy:
 *   1. Enumerate Category:Mario_Kart_8_Deluxe_tracks to get every
 *      canonical track-page title the wiki uses.
 *   2. Fuzzy-match each of our 96 track names against that list.
 *   3. For each match, query pageimages (the article's main infobox
 *      image) and download it to /assets/img/tracks/{slug}.png.
 *
 * The voting page degrades to the parent-cup emoji whenever an image is
 * missing, so this script is opt-in — running it is purely an upgrade.
 *
 * Usage:
 *   php bin/fetch_track_images.php           # fetch missing only
 *   php bin/fetch_track_images.php --force   # re-fetch everything
 *   php bin/fetch_track_images.php --dry-run # show what would happen
 */

require_once __DIR__ . '/../private/includes/mk_data.php';

// curl_close() is a no-op since 8.0 and emits deprecation in 8.5+. Silence.
error_reporting(E_ALL & ~E_DEPRECATED);

$force  = in_array('--force',   $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$outDir = __DIR__ . '/../public_html/assets/img/tracks';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$api       = 'https://mariokart.fandom.com/api.php';
$userAgent = ['User-Agent: Kartfolio/1.0 (track-image fetcher)', 'Accept: application/json'];

/** Light wrapper around curl that returns the decoded JSON of an API call. */
function api_get(string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200 || !$body) return ['error' => "HTTP $code"];
    return json_decode($body, true) ?: ['error' => 'JSON decode failed'];
}

/**
 * Enumerate every page in the MK8 Deluxe tracks category. Returns a list
 * of canonical wiki titles.
 */
function getCategoryMembers(string $api, array $headers): array {
    $titles = [];
    $cont = null;
    do {
        $params = [
            'action'   => 'query',
            'list'     => 'categorymembers',
            'cmtitle'  => 'Category:Mario_Kart_8_Deluxe_tracks',
            'cmlimit'  => 500,
            'cmtype'   => 'page',
            'format'   => 'json',
        ];
        if ($cont) $params['cmcontinue'] = $cont;
        $url = $api . '?' . http_build_query($params);
        $data = api_get($url, $headers);
        foreach (($data['query']['categorymembers'] ?? []) as $m) {
            if (!empty($m['title'])) $titles[] = $m['title'];
        }
        $cont = $data['continue']['cmcontinue'] ?? null;
    } while ($cont);

    // Filter out anything that obviously isn't a track page.
    return array_values(array_filter($titles, function ($t) {
        return !preg_match('/^(File|Category|Template|User|Talk|City Tracks)/', $t);
    }));
}

/**
 * Normalise a track name for comparison: lowercase, strip punctuation and
 * apostrophes, collapse whitespace. Also strips known prefixes/suffixes
 * we wrote in mk_data.php but the wiki doesn't always use ("DS ", "GBA ",
 * "Tour ", "(Wii U)", "(Mario Kart 8 Deluxe)", etc).
 */
function normalizeForMatch(string $name): string {
    $n = $name;
    // Strip parenthetical disambiguators
    $n = preg_replace('/\s*\([^)]*\)\s*/', '', $n);
    // Strip leading platform tag (whether from us or from the wiki).
    $n = preg_replace('/^(Wii|GBA|GCN|SNES|N64|DS|3DS|Tour)\s+/i', '', $n);
    // Lowercase + strip apostrophes
    $n = strtolower(str_replace(["'", '’'], '', $n));
    // Collapse non-alphanumerics
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
    return trim($n);
}

/**
 * For a given track name, find the best-matching wiki title from the
 * category list. Returns null if no good match is found.
 */
function matchWikiTitle(string $trackName, array $wikiTitles): ?string {
    $needle = normalizeForMatch($trackName);
    if ($needle === '') return null;

    // Build a normalised → original map of wiki titles. If multiple wiki
    // titles normalise the same way (very rare), prefer the one whose
    // original disambiguator best matches our prefix (e.g. our "DS Mario
    // Circuit" should prefer wiki "Mario Circuit (DS)" over "Mario
    // Circuit (Wii U)").
    $candidates = [];
    foreach ($wikiTitles as $wt) {
        $norm = normalizeForMatch($wt);
        if ($norm === $needle) $candidates[] = $wt;
    }
    if (empty($candidates)) return null;
    if (count($candidates) === 1) return $candidates[0];

    // Disambiguation tiebreaker: prefer the wiki title whose disambig
    // (parenthetical OR prefix) matches our own platform tag.
    $ourTag = null;
    if (preg_match('/^(Wii|GBA|GCN|SNES|N64|DS|3DS|Tour)\s+/i', $trackName, $m)) {
        $ourTag = strtolower($m[1]);
    }
    if ($ourTag === null) {
        // Base game (no retro prefix). Prefer "(Wii U)" disambig.
        foreach ($candidates as $c) {
            if (stripos($c, '(Wii U)') !== false) return $c;
        }
        // Or no disambig at all.
        foreach ($candidates as $c) {
            if (strpos($c, '(') === false) return $c;
        }
    } else {
        // Look for an exact platform-tag match.
        foreach ($candidates as $c) {
            if (stripos($c, "($ourTag)") !== false) return $c;
            if (stripos($c, "$ourTag ") === 0) return $c;
        }
    }
    // Fallback: first match.
    return $candidates[0];
}

/** Get the main infobox image URL for a wiki page. */
function pageImageUrl(string $title, string $api, array $headers): ?string {
    $url = $api . '?' . http_build_query([
        'action'    => 'query',
        'titles'    => $title,
        'prop'      => 'pageimages',
        'piprop'    => 'original',
        'redirects' => 1,
        'format'    => 'json',
    ]);
    $data  = api_get($url, $headers);
    $pages = $data['query']['pages'] ?? [];
    foreach ($pages as $page) {
        if (isset($page['missing'])) continue;
        $src = $page['original']['source'] ?? null;
        // Wikia URLs look like .../foo.png/revision/latest?cb=1234567 — the
        // extension is mid-URL, not at the end, so we match anywhere.
        if ($src && preg_match('/\.(png|jpe?g)([\/?#]|$)/i', $src)) return $src;
    }
    return null;
}

/**
 * Fallback: scan all images on the page and pick the first one that
 * looks like a course screenshot. Used when pageimages returns nothing
 * (some pages don't have an infobox image indexed by the extension).
 *
 * Strategy: prefer filenames that contain track/course keywords or the
 * page title's distinctive words; reject obvious non-courses (flags,
 * kart parts, stamps, character renders, etc).
 */
function fallbackPageImage(string $title, string $api, array $headers): ?string {
    $url = $api . '?' . http_build_query([
        'action'    => 'query',
        'titles'    => $title,
        'prop'      => 'images',
        'imlimit'   => 50,
        'redirects' => 1,
        'format'    => 'json',
    ]);
    $data  = api_get($url, $headers);
    $files = [];
    foreach (($data['query']['pages'] ?? []) as $page) {
        foreach (($page['images'] ?? []) as $img) {
            if (!empty($img['title'])) $files[] = $img['title'];
        }
    }
    if (empty($files)) return null;

    // Reject patterns: anything that obviously isn't a course screenshot.
    $rejectRe = '/(flag|logo|icon|wiki|fandom|button|stamp|emblem|kart|tire|wheel|glider|render|crest|coin|button|cursor|item|character|amiibo)/i';

    // Distinctive words from the page title (length >= 4, exclude
    // platform tags and common joiners). Used to prefer filenames that
    // mention the track.
    $titleClean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $title);
    $titleClean = preg_replace('/\b(wii|gba|gcn|snes|n64|ds|3ds|tour|the|of|in)\b/i', '', $titleClean);
    $titleWords = array_filter(preg_split('/\s+/', strtolower(trim($titleClean))), fn($w) => strlen($w) >= 4);

    $candidates = [];
    foreach ($files as $f) {
        if (preg_match($rejectRe, $f)) continue;
        if (!preg_match('/\.(png|jpe?g)$/i', $f)) continue;
        $score = 0;
        $fLower = strtolower($f);
        if (preg_match('/(course|track|MK8|mariokart)/i', $f)) $score += 5;
        foreach ($titleWords as $w) {
            if (strpos($fLower, $w) !== false) $score += 3;
        }
        $candidates[$f] = $score;
    }
    if (empty($candidates)) return null;

    arsort($candidates);
    $bestFile = array_key_first($candidates);
    if ($candidates[$bestFile] === 0) return null; // Nothing scored.

    // Resolve File:foo.png → direct URL.
    $url = $api . '?' . http_build_query([
        'action' => 'query',
        'titles' => $bestFile,
        'prop'   => 'imageinfo',
        'iiprop' => 'url',
        'format' => 'json',
    ]);
    $data = api_get($url, $headers);
    foreach (($data['query']['pages'] ?? []) as $page) {
        $src = $page['imageinfo'][0]['url'] ?? null;
        if ($src && preg_match('/\.(png|jpe?g)([\/?#]|$)/i', $src)) return $src;
    }
    return null;
}

/** Download a remote URL to a local path. Returns bytes written or false. */
function download(string $url, string $dest, array $headers) {
    $ch = curl_init($url);
    $fp = fopen($dest, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $ok   = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fp);
    if (!$ok || $code !== 200) {
        @unlink($dest);
        return false;
    }
    return filesize($dest);
}

// ── Main ─────────────────────────────────────────────────────────────────
$tracks = getMKAllTracks();
$total  = count($tracks);
$got = $skipped = 0;
$failed = [];

echo "Enumerating Category:Mario_Kart_8_Deluxe_tracks…\n";
$wikiTitles = getCategoryMembers($api, $userAgent);
echo '  Found ' . count($wikiTitles) . " wiki track pages.\n";
if (empty($wikiTitles)) {
    fwrite(STDERR, "Category lookup returned nothing. Check network / API URL.\n");
    exit(1);
}

echo "\nFetching $total track images into $outDir\n";
echo $force  ? "  --force: re-downloading existing files\n" : "  (skipping files that already exist)\n";
if ($dryRun) echo "  --dry-run: nothing will actually be written\n";
echo str_repeat('-', 78) . "\n";

foreach ($tracks as $idx => $track) {
    $slug = getMKTrackImageSlug($track);
    $dest = $outDir . '/' . $slug . '.png';

    if (file_exists($dest) && !$force) {
        $skipped++;
        printf("[%3d/%d] %-34s ↳ already exists\n", $idx + 1, $total, $track);
        continue;
    }

    $wikiTitle = matchWikiTitle($track, $wikiTitles);
    if (!$wikiTitle) {
        $failed[] = "$track — no matching wiki page in category";
        printf("[%3d/%d] %-34s ✗ no category match\n", $idx + 1, $total, $track);
        continue;
    }

    $url = pageImageUrl($wikiTitle, $api, $userAgent);
    $via = 'pageimages';
    if (!$url) {
        $url = fallbackPageImage($wikiTitle, $api, $userAgent);
        $via = 'fallback';
    }
    if (!$url) {
        $failed[] = "$track (matched to \"$wikiTitle\") — no image found";
        printf("[%3d/%d] %-34s ✗ matched %s · no image found\n", $idx + 1, $total, $track, $wikiTitle);
        continue;
    }

    if ($dryRun) {
        printf("[%3d/%d] %-34s → %s · %s · %s\n", $idx + 1, $total, $track, $wikiTitle, $via, $url);
        $got++;
        continue;
    }

    $bytes = download($url, $dest, $userAgent);
    if ($bytes === false) {
        $failed[] = "$track (download of $url failed)";
        printf("[%3d/%d] %-34s ✗ download failed\n", $idx + 1, $total, $track);
        continue;
    }

    $got++;
    printf("[%3d/%d] %-34s ✓ %s · %s · %d bytes\n", $idx + 1, $total, $track, $wikiTitle, $via, $bytes);
    usleep(250000);
}

echo str_repeat('-', 78) . "\n";
echo "Got: $got · Skipped: $skipped · Failed: " . count($failed) . "\n";
if (!empty($failed)) {
    echo "\nFailures:\n";
    foreach ($failed as $f) echo "  · $f\n";
    echo "\nFix: edit private/includes/mk_data.php to match the canonical wiki title shown in the category list above, then re-run.\n";
}
