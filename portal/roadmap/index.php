<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title   = 'Roadmap';
$page_section = 'roadmap';

$items = db_rows("SELECT * FROM roadmap_items ORDER BY phase, sort_order, id");
$by_phase = [];
foreach ($items as $item) $by_phase[$item['phase']][] = $item;

$phase_meta = [
    1 => ['tag'=>'PHASE 01 · ACTIVE', 'title'=>'Rapid Coverage',             'dates'=>'NOW — 2026',  'color'=>'var(--green)',  'status'=>'In Progress'],
    2 => ['tag'=>'PHASE 02',           'title'=>'Transition to RF Backbone',  'dates'=>'2026 — 2029', 'color'=>'var(--amber)',  'status'=>'Planned'],
    3 => ['tag'=>'PHASE 03',           'title'=>'Full Multi-Mode Network',    'dates'=>'2029+',       'color'=>'var(--t3)',     'status'=>'Future'],
];

$ardc_requested = 55567;

$extra_head = '<style>
.rm-wrap{padding:4rem 5vw}
.rm-hd{margin-bottom:3rem}
.rm-phases{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:2rem;margin-bottom:3rem}
.rm-card{background:var(--panel);border:1px solid var(--border2);padding:1.5rem}
.rm-num{font-family:var(--display);font-weight:800;font-size:3rem;line-height:1;margin-bottom:0.3rem}
.rm-tag{font-family:var(--mono);font-size:0.55rem;letter-spacing:0.18em;text-transform:uppercase;margin-bottom:0.5rem}
.rm-title{font-family:var(--display);font-weight:700;font-size:1.1rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1);margin-bottom:0.3rem}
.rm-dates{font-family:var(--mono);font-size:0.65rem;color:var(--t3);margin-bottom:1.2rem}
.rm-items{list-style:none}
.rm-item{display:flex;align-items:flex-start;gap:0.6rem;padding:0.4rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;color:var(--t2);line-height:1.5}
.rm-item:last-child{border-bottom:none}
.rm-item-icon{font-size:0.6rem;margin-top:0.2rem;flex-shrink:0;min-width:14px}
.rm-item-body{flex:1}
.rm-item-body .desc{font-size:0.7rem;color:var(--t3);display:block;margin-top:0.1rem}
.rm-grant{background:var(--panel);border:1px solid var(--border2);padding:2rem;max-width:500px}
.rm-grant-hd{font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem}
.rm-grant-title{font-family:var(--display);font-weight:700;font-size:1rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1);margin-bottom:1rem}
.rm-grant-amt{font-family:var(--display);font-weight:800;font-size:2.5rem;color:var(--green);line-height:1}
.rm-grant-sub{font-family:var(--mono);font-size:0.62rem;color:var(--t3);margin-top:0.3rem}
</style>';

require_once TTN_INCLUDES . '/header.php';
?>
<main style="padding-top:46px">
<div class="rm-wrap">
    <div class="rm-hd">
        <div style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.6rem">Roadmap</div>
        <h1 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--t1);margin-bottom:0.8rem">Network Build Plan · 2026–2035+</h1>
        <p style="font-family:var(--mono);font-size:0.75rem;color:var(--t2);max-width:640px;line-height:1.7">Three phases to build a statewide multi-mode backbone across Tennessee — Internet-linked now, Part-97 RF mesh by Phase 2, zero commercial dependency by Phase 3.</p>
    </div>

    <div class="rm-phases">
    <?php foreach ($phase_meta as $phase => $pm): ?>
    <div class="rm-card">
        <div class="rm-num" style="color:<?= $pm['color'] ?>"><?= $phase ?></div>
        <div class="rm-tag" style="color:<?= $pm['color'] ?>"><?= $pm['tag'] ?></div>
        <div class="rm-title"><?= htmlspecialchars($pm['title']) ?></div>
        <div class="rm-dates"><?= $pm['dates'] ?></div>
        <ul class="rm-items">
        <?php foreach ($by_phase[$phase] ?? [] as $item):
            $icon = match($item['status']) {
                'done'        => '<span style="color:var(--green)">✓</span>',
                'in_progress' => '<span style="color:var(--amber)">◐</span>',
                'cancelled'   => '<span style="color:var(--red)">✕</span>',
                default       => '<span style="color:var(--t3)">○</span>',
            };
        ?>
        <li class="rm-item">
            <span class="rm-item-icon"><?= $icon ?></span>
            <div class="rm-item-body">
                <?= htmlspecialchars($item['title']) ?>
                <?php if ($item['description']): ?>
                <span class="desc"><?= htmlspecialchars($item['description']) ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
        <?php if (empty($by_phase[$phase])): ?>
        <li class="rm-item"><span class="rm-item-icon" style="color:var(--t3)">—</span><div>No items yet.</div></li>
        <?php endif; ?>
        </ul>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- ARDC Grant section -->
    <div class="rm-grant">
        <div class="rm-grant-hd">ARDC Grant · Phase 1</div>
        <div class="rm-grant-title">Amateur Radio Digital Communications — Infrastructure</div>
        <p style="font-size:0.82rem;color:var(--t2);line-height:1.7;margin-bottom:1.2rem">Phase 1 infrastructure grant submitted to ARDC. Funds earmarked for tower work, solar systems, and repeater hardware across the initial backbone sites.</p>
        <div class="rm-grant-amt">$<?= number_format($ardc_requested) ?></div>
        <div class="rm-grant-sub">Requested · Phase 1</div>
    </div>
</div>
</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
