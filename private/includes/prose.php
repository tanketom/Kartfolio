<?php
/**
 * League prose: one formatter for every long-form text the site renders, and
 * the lexicon auto-linker.
 *
 * There used to be two copies of this formatter, in view_recap.php and
 * view_season_report.php. Only one of them escaped, so the season narrative
 * reached the page as raw markup while the broadcast did not. One function
 * now, escaping first, so a racer-entered nickname carried into model output
 * can never become HTML.
 *
 * Order matters: escape → markdown-lite → paragraphs → lexicon links. The
 * linker runs last and walks text nodes only, so it can never rewrite the
 * markup the earlier steps produced.
 */

/** Lexicon entries worth linking, longest term first. Cached per request. */
function lexiconTerms(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = $pdo->query("SELECT term, slug, definition FROM lexicon_terms")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return $cache; }
    foreach ($rows as $r) {
        $term = trim((string)$r['term']);
        // Very short terms match too much ordinary prose to be worth linking.
        if ($term === '' || mb_strlen($term) < 4 || empty($r['slug'])) continue;
        $cache[] = ['term' => $term, 'slug' => (string)$r['slug'], 'definition' => trim((string)($r['definition'] ?? ''))];
    }
    // Longest first, so "Monster Hunter" is claimed before "MONSTER HUNT" can
    // bite into it — the same trap CLAUDE.md warns about for search-replace.
    usort($cache, fn($a, $b) => mb_strlen($b['term']) <=> mb_strlen($a['term']));
    return $cache;
}

/**
 * Link the first mention of each lexicon term in a block of already-escaped
 * HTML. Walks tag/text segments so nothing inside a tag or an existing link
 * is touched, and links each term once so a long broadcast doesn't turn blue.
 */
function lexiconLinkify(PDO $pdo, string $html, int $maxLinks = 10): string {
    $terms = lexiconTerms($pdo);
    if (!$terms || $html === '') return $html;

    // Matches become placeholders first and real anchors only at the very end.
    // Substituting the anchor inline let a later term match text inside the
    // title attribute of a link this function had just written, which produced
    // an anchor nested inside an attribute.
    $html = str_replace("\0", '', $html);
    $anchors = [];

    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;

    $linked = [];      // slug => true, so each term links once
    $anchorDepth = 0;  // never link inside an existing <a>

    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        if ($part[0] === '<') {
            if (stripos($part, '<a') === 0) $anchorDepth++;
            elseif (stripos($part, '</a') === 0 && $anchorDepth > 0) $anchorDepth--;
            continue;
        }
        if ($anchorDepth > 0 || count($anchors) >= $maxLinks) continue;

        foreach ($terms as $t) {
            if (count($anchors) >= $maxLinks) break;
            if (isset($linked[$t['slug']])) continue;
            // The haystack is escaped HTML, so the needle has to be escaped the
            // same way or a term with an apostrophe ("Mac's Mushroom Musings")
            // could never match its own &#039; in the text.
            $needle = htmlspecialchars($t['term'], ENT_QUOTES, 'UTF-8');
            // Word-ish boundaries: a term must not be glued to letters, digits
            // or hyphens on either side.
            $pattern = '/(?<![\p{L}\p{N}\-])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}\-])/iu';
            $replaced = preg_replace_callback($pattern, function ($m) use ($t, &$linked, &$anchors) {
                $linked[$t['slug']] = true;
                $tip = $t['definition'] !== '' ? mb_substr($t['definition'], 0, 180) : $t['term'];
                $anchors[] = '<a class="lex-link" href="/lexicon/' . htmlspecialchars($t['slug'], ENT_QUOTES, 'UTF-8')
                           . '" title="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">' . $m[0] . '</a>';
                return "\0LEX" . (count($anchors) - 1) . "\0";
            }, $part, 1);
            if ($replaced !== null) $part = $replaced;
        }
        $parts[$i] = $part;
    }

    $out = implode('', $parts);
    return preg_replace_callback('/\0LEX(\d+)\0/', fn($m) => $anchors[(int)$m[1]] ?? '', $out);
}

/**
 * Render a broadcast, season narrative or any other block of league prose:
 * escaped, **bold** honoured, blank lines become paragraphs, lexicon terms
 * linked once each.
 */
function formatLeagueProse(PDO $pdo, ?string $text, bool $linkLexicon = true): string {
    $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong class="highlight-name">$1</strong>', $text);

    $out = '';
    foreach (preg_split('/\n\s*\n/', (string)$text) as $p) {
        $p = trim($p);
        if ($p !== '') $out .= '<p>' . nl2br($p) . '</p>';
    }
    return $linkLexicon ? lexiconLinkify($pdo, $out) : $out;
}
