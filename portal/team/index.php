<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title   = 'Team';
$page_section = 'team';

$team = db_rows("
    SELECT *
    FROM operators
    WHERE is_active = 1 AND is_public = 1
    ORDER BY sort_order ASC, callsign ASC
");

$extra_head = '<style>
.team-wrap{padding:4rem 5vw}
.team-hd{margin-bottom:2.5rem}
.tc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.5rem}
.tc-card{background:var(--panel);border:1px solid var(--border2);padding:1.5rem;display:flex;flex-direction:column;gap:0.35rem;transition:border-color 0.12s}
.tc-card:hover{border-color:var(--border2)}
.tc-photo{width:64px;height:64px;object-fit:cover;border:1px solid var(--border2);margin-bottom:0.6rem;border-radius:2px}
.tc-photo-placeholder{width:64px;height:64px;background:var(--panel2);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-family:var(--display);font-weight:800;font-size:1.3rem;color:var(--t3);margin-bottom:0.6rem}
.tc-call{font-family:var(--display);font-weight:700;font-size:1.1rem;color:var(--amber);letter-spacing:0.04em}
.tc-name{font-size:0.85rem;color:var(--t1);font-weight:500}
.tc-sites{font-family:var(--mono);font-size:0.62rem;color:var(--green);margin-top:0.1rem}
.tc-loc{font-family:var(--mono);font-size:0.62rem;color:var(--t3)}
.tc-bio{font-size:0.8rem;color:var(--t2);line-height:1.65;margin-top:0.4rem}
.tc-asl{font-family:var(--mono);font-size:0.6rem;color:var(--t3);margin-top:0.2rem}
.tc-asl span{color:var(--amber)}
.tc-foot{margin-top:auto;padding-top:0.8rem;display:flex;gap:0.7rem;flex-wrap:wrap;border-top:1px solid var(--border)}
.tc-link{font-family:var(--mono);font-size:0.58rem;color:var(--green);text-decoration:none;letter-spacing:0.08em;text-transform:uppercase}
.tc-link:hover{text-decoration:underline}
.team-phil{padding:3rem 5vw;background:var(--panel);border-top:1px solid var(--border2)}
.team-phil-q{font-family:var(--display);font-weight:300;font-size:clamp(1rem,2vw,1.4rem);color:var(--t1);line-height:1.5;border-left:3px solid var(--green);padding-left:1.5rem;margin-bottom:1.2rem;max-width:680px}
.team-phil-p{font-size:0.86rem;color:var(--t2);line-height:1.8;max-width:680px;margin-bottom:0.8rem}
.join-bar{padding:2.5rem 5vw;display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;border-top:1px solid var(--border2)}
.join-btns{display:flex;gap:0.8rem;flex-wrap:wrap}
.jbtn{font-family:var(--mono);font-size:0.63rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.65rem 1.3rem;text-decoration:none;transition:all 0.15s;white-space:nowrap}
.jbtn.p{background:var(--green);color:#000;font-weight:700}.jbtn.p:hover{opacity:0.85}
.jbtn.s{border:1px solid var(--border2);color:var(--t2)}.jbtn.s:hover{border-color:var(--green);color:var(--green)}
</style>';

require_once TTN_INCLUDES . '/header.php';
$site_url = s('site_url', 'https://dev.ttn.radio');
?>
<main style="padding-top:46px">
<div class="team-wrap">
    <div class="team-hd">
        <div style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.6rem">Team</div>
        <h1 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--t1)">The Builders</h1>
        <p style="font-family:var(--mono);font-size:0.75rem;color:var(--t2);max-width:600px;margin-top:0.8rem">Licensed engineers and technical operators building and maintaining TTN infrastructure across Tennessee and north Mississippi.</p>
    </div>

    <div class="tc-grid">
    <?php foreach ($team as $m):
        $crew_sites = db_rows("
            SELECT si.name, si.id, sc.role
            FROM site_crew sc
            JOIN sites si ON si.id = sc.site_id
            WHERE sc.operator_id = ? AND sc.approved = 1
            ORDER BY si.name
        ", [$m['id']]);
        $all_asls = [];
        foreach ($crew_sites as $cs) {
            $asls = db_rows("SELECT sa.asl_number FROM sys_asl sa JOIN systems s ON s.id=sa.system_id WHERE s.site_id=? ORDER BY sa.is_hub DESC", [$cs['id']]);
            foreach ($asls as $a) $all_asls[] = $a['asl_number'];
        }
    ?>
    <div class="tc-card">
        <?php if (!empty($m['photo_url'])): ?>
        <img src="<?= htmlspecialchars($m['photo_url']) ?>" alt="<?= htmlspecialchars($m['callsign']) ?>" class="tc-photo">
        <?php else: ?>
        <div class="tc-photo-placeholder"><?= htmlspecialchars(substr($m['callsign'],0,2)) ?></div>
        <?php endif; ?>
        <div class="tc-call"><?= htmlspecialchars($m['callsign']) ?></div>
        <div class="tc-name"><?= htmlspecialchars($m['display_name'] ?: $m['callsign']) ?></div>
        <?php if (!empty($crew_sites)): ?>
        <div class="tc-sites"><?= implode(' · ', array_map(fn($s) => htmlspecialchars($s['name']), $crew_sites)) ?></div>
        <?php endif; ?>
        <?php $loc = trim(($m['city']??'').(($m['state']??'') ? ', '.($m['state']??'') : '')); ?>
        <?php if ($loc): ?><div class="tc-loc">📍 <?= htmlspecialchars($loc) ?></div><?php endif; ?>
        <?php if ($m['bio']): ?><div class="tc-bio"><?= nl2br(htmlspecialchars($m['bio'])) ?></div><?php endif; ?>
        <?php if (!empty($all_asls)): ?>
        <div class="tc-asl">ASL: <?php foreach($all_asls as $i=>$a): ?><span><?= htmlspecialchars($a) ?></span><?= $i<count($all_asls)-1?' ':'' ?><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="tc-foot">
            <?php if ($m['qrz_url']): ?><a href="<?= htmlspecialchars($m['qrz_url']) ?>" target="_blank" class="tc-link">QRZ ↗</a><?php endif; ?>
            <?php if (!empty($crew_sites)): ?><a href="<?= $site_url ?>/sites/detail/?site=<?= $crew_sites[0]['id'] ?>" class="tc-link">Site ›</a><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="team-phil">
    <div class="team-phil-q">"Elmer the Elmers — build infrastructure that teaches the teachers."</div>
    <div class="team-phil-p">TTN is built and operated by licensed amateur radio operators who believe in sovereign infrastructure, technical excellence, and teaching the next generation. No dues. No politics. If you hold a license and want to learn, build, or contribute — you belong here.</div>
    <div class="team-phil-p">TTN is a Tennessee 501(c)(3) nonprofit. EIN <?= htmlspecialchars(s('org_ein','41-2680033')) ?>. All donations go directly to hardware, tower work, and site infrastructure.</div>
</div>

<div class="join-bar">
    <div>
        <div style="font-family:var(--display);font-weight:700;font-size:1.2rem;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.4rem">Get Involved</div>
        <p style="font-size:0.84rem;color:var(--t2);line-height:1.6;max-width:500px">Licensed ham in Tennessee or north Mississippi? Want to host a node, contribute code, or connect on the network? Reach out.</p>
    </div>
    <div class="join-btns">
        <a href="mailto:<?= htmlspecialchars(s('contact_email','bobwwj555@gmail.com')) ?>" class="jbtn p">Contact W4BWW</a>
        <?php if (s('social_facebook')): ?><a href="<?= htmlspecialchars(s('social_facebook')) ?>" target="_blank" class="jbtn s">Facebook</a><?php endif; ?>
        <a href="<?= $site_url ?>/sites/" class="jbtn s">Network Map</a>
    </div>
</div>
</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
