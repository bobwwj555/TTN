<?php
/**
 * TTN Admin Head — outputs full HTML head for admin pages
 * LOCATION: /home/obdswlpx/dev.ttn.radio/includes/admin_head.php
 */
$_site_url = s('site_url', 'https://dev.ttn.radio');
$adm_title = $adm_title ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($adm_title) ?> · TTN Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#0a0a0a;--bg2:#0d0d0d;--panel:#111318;--panel2:#141720;
    --border:#1a1d24;--border2:#1f2330;
    --t1:#e8eaf0;--t2:#9ca3af;--t3:#4b5563;
    --green:#00ff88;--amber:#fbbf24;--red:#f87171;
    --gglow:rgba(0,255,136,0.06);--gdim:#00cc6a;
    --mono:'Share Tech Mono',monospace;
    --display:'Oxanium',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;background:var(--bg);color:var(--t1);font-family:var(--mono);font-size:14px}
a{color:var(--green);text-decoration:none}
a:hover{text-decoration:underline}

/* ── LAYOUT ── */
.adm-wrap{display:flex;min-height:100vh}
.adm-sidebar{width:200px;min-width:200px;background:var(--panel);border-right:1px solid var(--border2);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.adm-main{flex:1;display:flex;flex-direction:column;min-width:0}
.adm-topbar{display:flex;align-items:center;justify-content:space-between;padding:0.8rem 1.5rem;border-bottom:1px solid var(--border2);background:var(--panel);gap:1rem;flex-wrap:wrap}
.adm-topbar-title{font-family:var(--display);font-weight:700;font-size:0.9rem;letter-spacing:0.06em;text-transform:uppercase;color:var(--t1)}
.adm-body{padding:1.5rem;flex:1}

/* ── SIDEBAR ── */
.adm-brand{padding:1rem 1.2rem;border-bottom:1px solid var(--border2);font-family:var(--display);font-weight:800;font-size:0.9rem;letter-spacing:0.1em;color:var(--green)}
.adm-brand span{display:block;font-size:0.55rem;color:var(--t3);letter-spacing:0.12em;margin-top:0.1rem;font-family:var(--mono);font-weight:400}
.adm-nav{padding:0.8rem 0;flex:1}
.adm-nav-section{font-size:0.5rem;color:var(--t3);letter-spacing:0.15em;text-transform:uppercase;padding:0.8rem 1.2rem 0.3rem;margin-top:0.3rem}
.adm-nav a{display:block;font-size:0.65rem;letter-spacing:0.06em;color:var(--t2);padding:0.5rem 1.2rem;border-left:2px solid transparent;transition:all 0.12s;text-decoration:none;text-transform:uppercase}
.adm-nav a:hover{color:var(--t1);background:var(--panel2);border-left-color:var(--border2)}
.adm-nav a.active{color:var(--green);border-left-color:var(--green);background:var(--gglow)}
.adm-footer{padding:0.8rem 1.2rem;border-top:1px solid var(--border2);font-size:0.58rem;color:var(--t3)}
.adm-footer a{color:var(--t3)}
.adm-footer a:hover{color:var(--red)}

/* ── PANELS ── */
.panel{background:var(--panel);border:1px solid var(--border2);margin-bottom:1.5rem}
.panel-hd{padding:0.75rem 1rem;border-bottom:1px solid var(--border2);font-family:var(--display);font-weight:700;font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--t2);display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap}
.panel-body{padding:1rem}

/* ── FORMS ── */
.field{margin-bottom:1rem}
.field label{display:block;font-size:0.58rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.35rem}
.field input[type=text],.field input[type=email],.field input[type=number],.field input[type=url],.field input[type=password],.field select,.field textarea{width:100%;background:var(--bg2);border:1px solid var(--border2);color:var(--t1);font-family:var(--mono);font-size:0.78rem;padding:0.55rem 0.7rem;outline:none;transition:border-color 0.12s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green)}
.field select option{background:var(--panel)}
.field textarea{resize:vertical;min-height:80px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
.check-row{display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem}
.check-row input[type=checkbox]{accent-color:var(--green)}
.check-row label{font-size:0.68rem;color:var(--t2);cursor:pointer}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:0.3rem;font-family:var(--mono);font-size:0.68rem;letter-spacing:0.08em;text-transform:uppercase;padding:0.55rem 1rem;border:none;cursor:pointer;text-decoration:none;transition:all 0.12s;white-space:nowrap}
.btn-primary{background:var(--green);color:#000;font-weight:700}.btn-primary:hover{background:var(--gdim);text-decoration:none}
.btn-secondary{background:transparent;color:var(--t2);border:1px solid var(--border2)}.btn-secondary:hover{border-color:var(--green);color:var(--green);text-decoration:none}
.btn-danger{background:transparent;color:var(--red);border:1px solid var(--red)}.btn-danger:hover{background:rgba(248,113,113,0.1);text-decoration:none}
.btn-sm{font-size:0.58rem;padding:0.35rem 0.7rem}
.actions{display:flex;gap:0.4rem;flex-wrap:wrap}

/* ── TABLES ── */
.adm-tbl{width:100%;border-collapse:collapse}
.adm-tbl th{font-size:0.55rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--t3);padding:0.5rem 0.7rem;border-bottom:1px solid var(--border2);text-align:left;background:var(--panel2);white-space:nowrap}
.adm-tbl td{padding:0.55rem 0.7rem;border-bottom:1px solid var(--border);font-size:0.75rem;color:var(--t1);vertical-align:middle}
.adm-tbl tr:last-child td{border-bottom:none}
.adm-tbl tr:hover td{background:var(--panel2)}
.mono{font-family:var(--mono)}
.muted{color:var(--t3)}

/* ── MESSAGES ── */
.msg-ok{background:rgba(0,255,136,0.08);border:1px solid var(--green);color:var(--green);font-size:0.72rem;padding:0.65rem 1rem;margin-bottom:1rem;border-radius:0;word-break:break-all}
.msg-err{background:rgba(248,113,113,0.08);border:1px solid var(--red);color:var(--red);font-size:0.72rem;padding:0.65rem 1rem;margin-bottom:1rem}
</style>
</head>
<body>
<?php if (defined('TTN_ENV') && TTN_ENV === 'development'): ?>
<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#ffab00;color:#000;font-family:monospace;font-size:0.72rem;font-weight:700;letter-spacing:0.15em;text-align:center;padding:0.25rem;text-transform:uppercase">
⚠ DEVELOPMENT ENVIRONMENT — ttn_dev DB — NOT PRODUCTION
</div>
<style>body{margin-top:24px!important}</style>
<?php endif; ?>

<div class="adm-wrap">
