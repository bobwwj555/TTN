<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title = 'Donate';

$org_name = s('org_name',       'Tennessee Technological Community');
$callsign = s('org_callsign',   'W4BWW');
$ein      = s('org_ein',        '41-2680033');
$address  = s('contact_address','2266 Piedmont Rd · New Market TN 37820');
$email    = s('contact_email',  'bobwwj555@gmail.com');
$phone    = s('contact_phone',  '865-202-6696');
$paypal   = s('paypal_url',     '');
$gofundme = 'https://www.gofundme.com/f/support-ttns-6meter-network-initiative';
$facebook = s('social_facebook','');

$extra_head = '<style>
.donate-wrap{padding:4rem 5vw;max-width:800px}
.donate-hd{margin-bottom:2.5rem}
.donate-items{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1px;background:var(--border2);border:1px solid var(--border2);margin:2rem 0}
.donate-item{background:var(--panel);padding:1.2rem}
.donate-item-cat{font-family:var(--mono);font-size:0.55rem;color:var(--amber);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.4rem}
.donate-item-name{font-family:var(--display);font-weight:700;font-size:0.88rem;color:var(--t1);margin-bottom:0.3rem}
.donate-item-desc{font-size:0.78rem;color:var(--t2);line-height:1.6}
.donate-btns{display:flex;gap:0.8rem;flex-wrap:wrap;margin:2rem 0}
.btn-p{background:var(--green);color:#000;font-family:var(--mono);font-size:0.65rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;padding:0.75rem 1.4rem;text-decoration:none;transition:background 0.12s}
.btn-p:hover{background:#00cc6a;text-decoration:none}
.btn-g{background:transparent;color:var(--t2);font-family:var(--mono);font-size:0.65rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.75rem 1.4rem;border:1px solid var(--border2);text-decoration:none;transition:all 0.12s}
.btn-g:hover{border-color:var(--green);color:var(--green);text-decoration:none}
.donate-mail{background:var(--panel);border:1px solid var(--border2);padding:1.2rem;font-family:var(--mono);font-size:0.75rem;color:var(--t2);line-height:1.8;margin-top:1.5rem}
.donate-mail strong{color:var(--t1)}
</style>';

require_once TTN_INCLUDES . '/header.php';
?>
<main style="padding-top:46px">
<div class="donate-wrap">
    <div class="donate-hd">
        <div style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.6rem">Support TTN</div>
        <h1 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--t1);margin-bottom:0.8rem">Support TTN Infrastructure</h1>
        <p style="font-family:var(--mono);font-size:0.78rem;color:var(--t2);line-height:1.7;max-width:600px"><?= htmlspecialchars($org_name) ?> is a Tennessee 501(c)(3) nonprofit — EIN <?= htmlspecialchars($ein) ?>. All donations go directly to tower work, solar hardware, repeater equipment, and site buildouts. No salaries. No overhead. 100% infrastructure.</p>
    </div>

    <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.8rem">What your donation funds</div>
    <div class="donate-items">
        <?php foreach ([
            ['Tower Work',       'Rohn 25 hardware, gin poles, climbing gear, anchor kits'],
            ['Solar Systems',    '200W panels, 100Ah LiFePO4 batteries, MPPT charge controllers'],
            ['Repeater Hardware','Motorola CDM / Kenwood TK series radios, duplexers, cavities'],
            ['AllStar Nodes',    'Raspberry Pi compute, URI interface boards, AllScan licenses'],
            ['Coax & Feedline',  'LMR-400, N connectors, lightning arrestors, grounding'],
        ] as [$cat, $desc]): ?>
        <div class="donate-item">
            <div class="donate-item-cat"><?= $cat ?></div>
            <div class="donate-item-desc"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="donate-btns">
        <a href="<?= htmlspecialchars($gofundme) ?>" class="btn-p" target="_blank">▸ GoFundMe</a>
        <?php if ($paypal): ?><a href="<?= htmlspecialchars($paypal) ?>" class="btn-g" target="_blank">PayPal</a><?php endif; ?>
        <?php if ($facebook): ?><a href="<?= htmlspecialchars($facebook) ?>" class="btn-g" target="_blank">Facebook</a><?php endif; ?>
    </div>

    <div class="donate-mail">
        <strong>Mail check</strong> payable to <strong><?= htmlspecialchars($org_name) ?></strong><br>
        <?= htmlspecialchars($callsign) ?> · <?= htmlspecialchars($address) ?><br><br>
        PayPal / Venmo: <a href="mailto:<?= htmlspecialchars($email) ?>" style="color:var(--green)"><?= htmlspecialchars($email) ?></a><br>
        Questions: <?= htmlspecialchars($phone) ?>
    </div>
</div>
</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
