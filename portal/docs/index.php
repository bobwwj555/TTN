<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_section = 'docs';
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page'] ?? ''));

try {
    $all_pages = db_rows("SELECT id, slug, nav_label, title, nav_group FROM pages WHERE is_published=1 ORDER BY nav_group, sort_order, title");
} catch (Exception $e) {
    $all_pages = db_rows("SELECT id, slug, nav_label, title, '' AS nav_group FROM pages WHERE is_published=1 ORDER BY sort_order, title");
}

// Group pages by nav_group
$group_labels = ['about'=>'About','network'=>'Network','operators'=>'Operators',''=>'Other'];
$nav_groups   = [];
foreach ($all_pages as $p) {
    $nav_groups[$p['nav_group'] ?: ''][] = $p;
}

$page     = null;
$not_found = false;
if ($slug) {
    $page = db_row("SELECT * FROM pages WHERE slug=? AND is_published=1", [$slug]);
    if (!$page) { $not_found = true; http_response_code(404); }
} else {
    $page = !empty($all_pages) ? db_row("SELECT * FROM pages WHERE slug=? AND is_published=1", [$all_pages[0]['slug']]) : null;
    if ($page) $slug = $page['slug'];
}

$page_title = $page ? $page['title'] : ($not_found ? '404 Not Found' : 'Docs');

// Markdown renderer
function ttn_md(string $md): string {
    // Fenced code blocks
    $md = preg_replace_callback('/```([^\n]*)\n(.*?)```/s', function($m) {
        return '<pre><code class="lang-'.htmlspecialchars($m[1]).'">'.htmlspecialchars($m[2]).'</code></pre>';
    }, $md);
    // Tables
    $md = preg_replace_callback('/(\|.+\|\n)(\|[-| :]+\|\n)((?:\|.+\|\n?)*)/m', function($m) {
        $hdr  = array_filter(array_map('trim', explode('|', trim($m[1], "|\n"))));
        $rows = [];
        foreach (array_filter(explode("\n", trim($m[3]))) as $row)
            $rows[] = array_filter(array_map('trim', explode('|', trim($row, "|\n"))));
        $out = '<table><thead><tr>'.implode('', array_map(fn($c) => '<th>'.htmlspecialchars($c).'</th>', $hdr)).'</tr></thead><tbody>';
        foreach ($rows as $r) $out .= '<tr>'.implode('', array_map(fn($c) => '<td>'.htmlspecialchars($c).'</td>', $r)).'</tr>';
        return $out.'</tbody></table>';
    }, $md);
    $md = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $md);
    $md = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $md);
    $md = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $md);
    $md = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md);
    $md = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $md);
    $md = preg_replace('/`([^`]+)`/', '<code>$1</code>', $md);
    $md = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $md);
    $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md);
    $md = preg_replace_callback('/((?:^[-*] .+\n?)+)/m', function($m) {
        $items = preg_replace('/^[-*] (.+)$/m', '<li>$1</li>', trim($m[0]));
        return '<ul>'.$items.'</ul>';
    }, $md);
    $md = preg_replace_callback('/((?:^> .*\n?)+)/m', function($m) {
        $lines = explode("\n", trim($m[0]));
        $paras = []; $cur = [];
        foreach ($lines as $line) {
            $text = preg_replace('/^> ?/', '', $line);
            if (trim($text) === '') { if ($cur) { $paras[] = implode(' ', $cur); $cur = []; } }
            else { $cur[] = trim($text); }
        }
        if ($cur) $paras[] = implode(' ', $cur);
        return '<blockquote><p>'.implode('</p><p>', array_filter($paras)).'</p></blockquote>';
    }, $md);
    $md = preg_replace('/^---$/m', '<hr>', $md);
    $blocks = preg_split('/\n{2,}/', $md);
    $out = '';
    foreach ($blocks as $b) {
        $b = trim($b);
        if (!$b) continue;
        if (preg_match('/^<(h[1-6]|ul|ol|table|pre|blockquote|hr)/', $b)) $out .= $b."\n";
        else $out .= '<p>'.nl2br($b)."</p>\n";
    }
    return $out;
}

$extra_head = '<style>
.docs-wrap{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 46px);width:100%}
.docs-nav{background:var(--panel);border-right:1px solid var(--border2);padding:1.5rem 0;position:sticky;top:46px;height:calc(100vh - 46px);overflow-y:auto}
.docs-nav-hd{font-family:var(--mono);font-size:0.52rem;color:var(--t3);letter-spacing:0.18em;text-transform:uppercase;padding:1rem 1.4rem 0.35rem;margin-top:0.3rem}
.docs-nav a{display:block;font-family:var(--mono);font-size:0.68rem;color:var(--t2);text-decoration:none;padding:0.45rem 1.4rem;border-left:2px solid transparent;transition:all 0.12s;letter-spacing:0.04em}
.docs-nav a:hover{color:var(--t1);background:var(--gglow);border-left-color:var(--gdim)}
.docs-nav a.active{color:var(--green);border-left-color:var(--green);background:var(--gglow)}
.docs-main{padding:2.5rem 3rem;width:100%;box-sizing:border-box}
.docs-main h1{font-family:var(--display);font-weight:700;font-size:1.5rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1);margin-bottom:0.4rem;border-bottom:1px solid var(--border2);padding-bottom:0.7rem}
.docs-main h2{font-family:var(--display);font-weight:700;font-size:1.05rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--t1);margin:2rem 0 0.6rem}
.docs-main h3{font-family:var(--mono);font-size:0.78rem;color:var(--amber);text-transform:uppercase;letter-spacing:0.1em;margin:1.5rem 0 0.5rem}
.docs-main p{font-size:0.87rem;color:var(--t2);line-height:1.8;margin-bottom:1rem}
.docs-main a{color:var(--green);text-decoration:none}.docs-main a:hover{text-decoration:underline}
.docs-main code{font-family:var(--mono);font-size:0.77rem;background:var(--panel2);border:1px solid var(--border2);padding:0.1rem 0.3rem;color:var(--amber)}
.docs-main pre{background:var(--panel2);border:1px solid var(--border2);padding:1rem;overflow-x:auto;margin:1rem 0}
.docs-main pre code{background:none;border:none;padding:0;font-size:0.77rem;color:var(--t1)}
.docs-main table{width:100%;border-collapse:collapse;font-size:0.8rem;margin:1rem 0}
.docs-main th{font-family:var(--mono);font-size:0.58rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--t3);padding:0.5rem 0.7rem;border-bottom:1px solid var(--border2);text-align:left;background:var(--panel2)}
.docs-main td{padding:0.55rem 0.7rem;border-bottom:1px solid var(--border);color:var(--t1);font-family:var(--mono);font-size:0.75rem}
.docs-main ul{margin:0.5rem 0 1rem 1.5rem}
.docs-main li{font-size:0.87rem;color:var(--t2);line-height:1.7;margin-bottom:0.2rem}
.docs-main blockquote{border-left:3px solid var(--green);margin:1rem 0;padding:0.5rem 1rem;font-family:var(--mono);font-size:0.77rem;color:var(--t3)}
.docs-main hr{border:none;border-top:1px solid var(--border2);margin:2rem 0}
.docs-main img{max-width:100%;height:auto;border:1px solid var(--border2);display:block;margin:1rem 0}
.docs-main strong{color:var(--t1)}
.docs-meta{font-family:var(--mono);font-size:0.57rem;color:var(--t3);letter-spacing:0.08em;margin-bottom:2rem}
.not-found{padding:4rem;text-align:center;font-family:var(--mono);color:var(--t3)}
@media(max-width:800px){.docs-wrap{grid-template-columns:1fr}.docs-nav{position:static;height:auto;border-right:none;border-bottom:1px solid var(--border2)}.docs-main{padding:2rem 5vw}}
</style>';

require_once TTN_INCLUDES . '/header.php';
$site_url = s('site_url', 'https://dev.ttn.radio');
?>
<main style="padding-top:46px;width:100%;box-sizing:border-box">
<div class="docs-wrap">
    <nav class="docs-nav">
        <?php foreach ($nav_groups as $gkey => $gpages): ?>
        <div class="docs-nav-hd"><?= htmlspecialchars($group_labels[$gkey] ?? ucfirst($gkey)) ?></div>
        <?php foreach ($gpages as $p): ?>
        <a href="<?= $site_url ?>/docs/?page=<?= htmlspecialchars($p['slug']) ?>" class="<?= $slug===$p['slug']?'active':'' ?>">
            <?= htmlspecialchars($p['nav_label'] ?: $p['title']) ?>
        </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (empty($all_pages)): ?>
        <div style="padding:1rem 1.4rem;font-family:var(--mono);font-size:0.65rem;color:var(--t3)">No docs published yet.</div>
        <?php endif; ?>
    </nav>
    <div class="docs-main">
    <?php if ($not_found): ?>
    <div class="not-found">404 · Page not found</div>
    <?php elseif ($page): ?>
    <div class="docs-meta">Updated <?= date('Y-m-d', strtotime($page['updated_at'])) ?></div>
    <div id="docs-body"><?= ttn_md($page['body']) ?></div>
    <?php else: ?>
    <div class="not-found">No documentation published yet.</div>
    <?php endif; ?>
    </div>
</div>
</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
