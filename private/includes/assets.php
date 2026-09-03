<?php
/**
 * Versioned asset URLs.
 *
 * .htaccess caches CSS/JS for a month, so after a deploy a browser (and the
 * Lounge sign, which reloads every 60 s) could run last month's stylesheet
 * against today's HTML. Every /assets URL therefore carries ?v=<mtime> of the
 * file on disk: unchanged files keep their cached copy, changed files get a
 * new URL the moment the deploy's `git reset` writes them.
 *
 *   <link rel="stylesheet" href="<?= assetUrl('/assets/css/pages.css') ?>">
 *
 * Pages that build a raw tag string ($extraCss) get it stamped by header.php
 * through versionAssetTags(), so they need no change.
 */

function assetUrl(string $path): string {
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $file = __DIR__ . '/../../public_html' . (str_starts_with($path, '/') ? $path : '/' . $path);
    $v = @filemtime($file);
    return $cache[$path] = $path . '?v=' . ($v ? base_convert((string)$v, 10, 36) : 'x');
}

/** Stamp every href="/assets/…" and src="/assets/…" in a chunk of HTML that has no version yet. */
function versionAssetTags(string $html): string {
    return preg_replace_callback('#\b(href|src)="(/assets/[^"?]+)"#', fn($m) => $m[1] . '="' . assetUrl($m[2]) . '"', $html);
}
