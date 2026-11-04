<?php
/**
 * The news as RSS: what /feed serves. Published broadcasts only, like every other
 * public reader of recap_archive, and newest first rather than pinned first: a
 * pin is for the homepage, and a feed reader would just see an old story on top.
 */
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/prose.php';
require_once __DIR__ . '/programs.php';

function rssFeedXml(PDO $pdo, string $leagueName, int $limit = 30): string {
    $st = $pdo->prepare("SELECT id, recap_text, headline, program_key, created_at FROM recap_archive
                         WHERE status = 'published' ORDER BY created_at DESC, id DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    // created_at is SQLite's CURRENT_TIMESTAMP, which is UTC.
    $when = fn($s) => (new DateTimeImmutable((string)$s, new DateTimeZone('UTC')))->format(DATE_RSS);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
         . '<title>' . $x($leagueName) . ' news</title>'
         . '<link>' . $x(siteUrl('/archive')) . '</link>'
         . '<description>' . $x("Broadcasts from $leagueName") . '</description>'
         . '<atom:link href="' . $x(siteUrl('/feed')) . '" rel="self" type="application/rss+xml"/>'
         . ($rows ? '<lastBuildDate>' . $when($rows[0]['created_at']) . '</lastBuildDate>' : '');
    foreach ($rows as $r) {
        $url = siteUrl('/view-recap/' . (int)$r['id']);
        $title = trim((string)$r['headline']) ?: (getProgramInfo($r['program_key'] ?? '')['name'] ?? 'League news');
        // No lexicon links: they are relative, and a reader would have nowhere to send them.
        $out .= '<item><title>' . $x($title) . '</title><link>' . $x($url) . '</link>'
              . '<guid isPermaLink="true">' . $x($url) . '</guid>'
              . '<pubDate>' . $when($r['created_at']) . '</pubDate>'
              . '<description>' . $x(formatLeagueProse($pdo, $r['recap_text'], false)) . '</description></item>';
    }
    return $out . '</channel></rss>';
}
