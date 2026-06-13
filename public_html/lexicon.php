<?php
/**
 * Lexicon — terminology / in-joke reference.
 *
 * Public list grouped by category, alphabetical within. Admins get inline
 * add / edit / delete forms. Individual terms are linkable via /lexicon/<slug>
 * (the slug column on lexicon_terms) which scrolls to that entry.
 *
 * Path: /cdnmk/public_html/lexicon.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/csrf.php';

$isAdmin = !empty($_SESSION['is_admin']);

// ── POST handlers (admin only) ─────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $isAdmin) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = '';

    if ($action === 'add' || $action === 'edit') {
        $term       = trim((string)($_POST['term'] ?? ''));
        $category   = trim((string)($_POST['category'] ?? '')) ?: null;
        $definition = trim((string)($_POST['definition'] ?? ''));
        $example    = trim((string)($_POST['example'] ?? '')) ?: null;

        if ($term === '' || $definition === '') {
            $flash = 'Term and definition are required.';
        } else {
            // Slug = lowercase kebab of term, apostrophes dropped, ™ stripped.
            $slug = strtolower($term);
            $slug = str_replace(['™', "'", '"'], '', $slug);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim($slug, '-');

            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO lexicon_terms (term, slug, category, definition, example) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$term, $slug, $category, $definition, $example]);
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare("UPDATE lexicon_terms SET term = ?, slug = ?, category = ?, definition = ?, example = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$term, $slug, $category, $definition, $example, $id]);
                }
                header('Location: /lexicon#' . $slug);
                exit;
            } catch (PDOException $e) {
                $flash = 'Save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM lexicon_terms WHERE id = ?")->execute([$id]);
            header('Location: /lexicon');
            exit;
        }
    }
}

// ── Load terms grouped by category ─────────────────────────────────────
$rows = $pdo->query("SELECT * FROM lexicon_terms ORDER BY COALESCE(category, 'zzzz') ASC, term ASC")->fetchAll(PDO::FETCH_ASSOC);
$byCategory = [];
foreach ($rows as $r) {
    $cat = $r['category'] ?: 'General';
    $byCategory[$cat][] = $r;
}

// Anchor highlight via /lexicon/<slug>
$highlightSlug = trim((string)($_GET['term'] ?? ''));

$pageTitle = "Lexicon — Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Lexicon</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">📖 Lexicon</h1>
        <p class="page-subtitle">EVERY TERM, IN-JOKE, AND PIECE OF JARGON THE LEAGUE USES</p>
    </header>

    <?php if (isset($flash) && $flash): ?>
        <div class="lex-flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="lex-empty">
            <p>No terms yet. <?= $isAdmin ? 'Add the first one below.' : 'An admin needs to seed this page.' ?></p>
        </div>
    <?php else: ?>
        <nav class="lex-toc">
            <strong>Categories:</strong>
            <?php foreach (array_keys($byCategory) as $cat): ?>
                <a href="#cat-<?= htmlspecialchars(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat))) ?>"><?= htmlspecialchars($cat) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($byCategory as $cat => $catRows):
            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat));
        ?>
        <section class="lex-section" id="cat-<?= htmlspecialchars($catSlug) ?>">
            <h2 class="lex-cat-h"><?= htmlspecialchars($cat) ?></h2>
            <dl class="lex-list">
                <?php foreach ($catRows as $r):
                    $isHighlighted = ($highlightSlug !== '' && $highlightSlug === $r['slug']);
                ?>
                    <div class="lex-entry <?= $isHighlighted ? 'lex-entry-active' : '' ?>" id="<?= htmlspecialchars($r['slug']) ?>">
                        <dt class="lex-term">
                            <a href="/lexicon/<?= htmlspecialchars($r['slug']) ?>" class="lex-anchor" title="Permalink">#</a>
                            <?= htmlspecialchars($r['term']) ?>
                        </dt>
                        <dd class="lex-def">
                            <?= nl2br(htmlspecialchars($r['definition'])) ?>
                            <?php if ($r['example']): ?>
                                <div class="lex-example">e.g. <?= htmlspecialchars($r['example']) ?></div>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <details class="lex-admin">
                                    <summary>Edit</summary>
                                    <form method="POST" class="lex-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <label>Term <input type="text" name="term" value="<?= htmlspecialchars($r['term']) ?>" required></label>
                                        <label>Category <input type="text" name="category" value="<?= htmlspecialchars($r['category'] ?? '') ?>"></label>
                                        <label>Definition <textarea name="definition" rows="3" required><?= htmlspecialchars($r['definition']) ?></textarea></label>
                                        <label>Example <input type="text" name="example" value="<?= htmlspecialchars($r['example'] ?? '') ?>"></label>
                                        <div class="lex-form-actions">
                                            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                            <button type="submit" formaction="?" name="action" value="delete" class="btn btn-sm" style="background:#5a1a1a;color:var(--gray-900);" onclick="return confirm('Delete this term?');">Delete</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <section class="lex-section lex-add-section">
            <h2 class="lex-cat-h">➕ Add a term</h2>
            <form method="POST" class="lex-form lex-form-add">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <label>Term <input type="text" name="term" placeholder="Ludwig Obstruction" required></label>
                <label>Category <input type="text" name="category" placeholder="Slang / Scoring / Personas / etc."></label>
                <label>Definition <textarea name="definition" rows="3" placeholder="Short, clear explanation." required></textarea></label>
                <label>Example <input type="text" name="example" placeholder="Optional — a sentence using the term naturally."></label>
                <div class="lex-form-actions">
                    <button type="submit" class="btn btn-primary">Add term</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<style>
.lex-flash {
    background: #5a1a1a; color: var(--gray-900); padding: 10px 16px;
    border-radius: 6px; margin-bottom: 16px;
}
.lex-empty {
    background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 8px;
    padding: 30px; text-align: center; color: var(--gray-500);
}

.lex-toc {
    background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 8px;
    padding: 12px 18px; margin-bottom: 24px; color: var(--gray-500); font-size: 0.9rem;
}
.lex-toc strong { color: var(--nintendo-red); margin-right: 8px; }
.lex-toc a { color: var(--gray-700); text-decoration: none; margin-right: 14px; }
.lex-toc a:hover { color: var(--nintendo-red); }

.lex-section { margin-bottom: 32px; }
.lex-cat-h {
    color: var(--nintendo-red); font-size: 1.3rem; font-weight: 900;
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 2px solid var(--gray-200); padding-bottom: 8px;
    margin-bottom: 14px;
}
.lex-list { margin: 0; padding: 0; }
.lex-entry {
    background: var(--gray-50); border: 1px solid var(--gray-200);
    border-left: 4px solid #444;
    border-radius: 8px; padding: 14px 18px; margin-bottom: 10px;
    transition: border-left-color 0.2s;
}
.lex-entry-active, .lex-entry:target {
    border-left-color: var(--nintendo-red);
    background: #fff6dc;
}
.lex-term {
    font-weight: 900; font-size: 1.1rem; color: var(--gray-900);
    text-transform: uppercase; letter-spacing: 0.5px;
    margin: 0 0 6px 0;
}
.lex-anchor {
    color: var(--gray-700); text-decoration: none; font-size: 0.9rem;
    margin-right: 6px;
}
.lex-entry:hover .lex-anchor { color: var(--nintendo-red); }
.lex-def {
    color: var(--gray-700); line-height: 1.55; margin: 0; font-size: 0.95rem;
}
.lex-example {
    margin-top: 8px; color: var(--gray-500); font-style: italic; font-size: 0.85rem;
    padding-left: 12px; border-left: 2px solid #333;
}

.lex-admin { margin-top: 10px; }
.lex-admin summary {
    cursor: pointer; color: var(--gray-500); font-size: 0.8rem;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.lex-form {
    display: flex; flex-direction: column; gap: 8px;
    margin-top: 10px;
}
.lex-form label {
    display: flex; flex-direction: column; gap: 4px;
    font-size: 0.8rem; color: var(--gray-500); text-transform: uppercase;
    letter-spacing: 0.5px;
}
.lex-form input, .lex-form textarea {
    padding: 8px 10px; background: var(--gray-200); border: 1px solid #333;
    color: var(--gray-900); border-radius: 4px; font: inherit;
}
.lex-form input:focus, .lex-form textarea:focus {
    outline: none; border-color: var(--nintendo-red);
}
.lex-form-actions { display: flex; gap: 10px; margin-top: 6px; }
.btn-sm { padding: 5px 12px; font-size: 0.85rem; }
.lex-add-section {
    background: var(--gray-50); border: 2px dashed #333;
    border-radius: 8px; padding: 20px 22px;
}
.lex-add-section .lex-cat-h { border-bottom: none; margin-bottom: 12px; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
