-- ============================================================
-- TTN Database Seed Data v4
-- Tennessee Technological Community · ttn.radio
--
-- Run AFTER ttn_v4_schema.sql
-- ============================================================

-- ── SITE SETTINGS ────────────────────────────────────────────
INSERT INTO `site_settings` (`setting_key`, `setting_val`) VALUES
('site_url',         'https://dev.ttn.radio'),
('hub_url',          'https://hub.ttn.radio'),
('hub_node',         '65392'),
('hub_freq',         '53.870'),
('ami_proxy_url',    'https://tn.w4bww.net/ttn-status.php'),
('site_donate_url',  'https://dev.ttn.radio/donate/'),
('org_name',         'Tennessee Technological Community'),
('org_callsign',     'W4BWW'),
('org_ein',          '41-2680033'),
('org_nonprofit',    '501(c)(3)'),
('org_state',        'TN'),
('contact_name',     'Bobby Whitaker'),
('contact_email',    'bobwwj555@gmail.com'),
('contact_phone',    '865-202-6696'),
('contact_address',  '2266 Piedmont Rd · New Market TN 37820'),
('social_facebook',  'https://www.facebook.com/groups/867375109631931'),
('social_github',    'https://github.com/bobwwj555/TTN'),
('paypal_url',       'https://paypal.me/bobwwj555'),
('img_logo',         '/assets/img/Diamond_and_Small_Coil.jpeg'),
('img_logo_full',    '/assets/img/cropped-Diamond-and-Small-Coil-1.jpeg'),
('img_hero',         '/assets/img/image-2-687x1024.jpg'),
('img_hero_bg',      '/assets/img/image-2-687x1024.jpg'),
('img_coverage',     '/assets/img/Estimate_Coverage_Map.png'),
('meta_description', 'TTN — volunteer-built RF network for technical hams. AllStarLink 6m backbone, solar-powered, no dues.'),
('asl_nodes',        '450330,450331,450332,450333,450320,450321');

-- ── OPERATORS ────────────────────────────────────────────────
-- Passwords are hashed — use admin panel to reset after import
-- Default password hash for 'changeme123' shown — CHANGE IMMEDIATELY
INSERT INTO `operators` (`id`, `callsign`, `display_name`, `bio`, `qrz_url`, `city`, `state`, `is_public`, `sort_order`, `email`, `phone`, `password_hash`, `role`, `is_active`) VALUES
(1,  'W4BWW',  'Bobby Whitaker', 'Mechanical Engineering, Tennessee Tech. TWRA Radio Tech. Runs the Piedmont hub — 120ft Rohn 25, solar primary, 4 ASL nodes. Driving force behind TTN infrastructure!', 'https://www.qrz.com/db/W4BWW', 'New Market', 'TN', 1, 0, 'bobwwj555@gmail.com', '865-202-6696', '$2y$10$placeholder_change_me_immediately_W4BWW', 'admin', 1),
(2,  'KW4ME',  'Eric Pullen',   'Software developer and AllStar node operator. Leading Middleton solar and 6m build. Building sovereign AI infrastructure independently.', 'https://www.qrz.com/db/KW4ME',   'Middleton',   'TN', 1, 1, NULL, NULL, '$2y$10$placeholder_change_me_immediately_KW4ME', 'admin', 1),
(8,  'WB4SKY', 'Sam DeLay',     'Licensed Professional Engineer. TVA Solar and Strategic Research (ret.). Disaster response experience. Chattanooga remote base host.', 'https://www.qrz.com/db/WB4SKY',  'Chattanooga', 'TN', 1, 2, NULL, NULL, '$2y$10$placeholder_change_me_immediately_WB4SKY', 'operator', 1),
(9,  'WA4ADT', 'Danny Efnor',   'Long-time repeater trustee and 6m AllStarLink group operator. Deep institutional knowledge. Memphis area expansion lead.', 'https://www.qrz.com/db/WA4ADT',  'Memphis',     'TN', 1, 3, 'dpefnor@comcast.net', '901-335-9968', '$2y$10$placeholder_change_me_immediately_WA4ADT', 'operator', 1),
(10, 'KE4WX',  'Mike Honeycutt','Retired electrical engineer. Remote base host and site lead. Mentoring new builders joining the TTN project.', 'https://www.qrz.com/db/KE4WX',   'Sneedville',  'TN', 1, 4, NULL, NULL, '$2y$10$placeholder_change_me_immediately_KE4WX', 'operator', 1),
(11, 'KB4REC', 'Joe',           'Established repeater trustee on Beaver Ridge. Former TN Wing Commander, Civil Air Patrol USAF Auxiliary.', 'https://www.qrz.com/db/KB4REC',  'Knoxville',   'TN', 1, 5, NULL, NULL, '$2y$10$placeholder_change_me_immediately_KB4REC', 'operator', 1),
(12, 'K5VD',   'Vaughn',        'Millwright and field engineer. Mississippi site lead with solar primary system expertise.', 'https://www.qrz.com/db/K5VD',    NULL, 'MS', 0, 6, NULL, NULL, '$2y$10$placeholder_change_me_immediately_K5VD', 'operator', 1);

-- ── SITES ────────────────────────────────────────────────────
INSERT INTO `sites` (`id`, `name`, `city`, `state`, `phase`, `status`, `power_primary`, `is_public`) VALUES
(1,  'TTN',                  'New Market',        'TN', 1, 'building', 'Solar Primary',  1),
(2,  'Middleton',            NULL,                'TN', 1, 'planned',  'Solar Hybrid',   1),
(3,  'Cookeville',           NULL,                'TN', 1, 'live',     NULL,             1),
(4,  'Beaver Ridge, Knoxville', NULL,             'TN', 1, 'building', NULL,             1),
(5,  'Holcomb',              NULL,                'MS', 1, 'planned',  'Solar Primary',  1),
(6,  'Chattanooga',          NULL,                'TN', 1, 'planned',  NULL,             1),
(7,  'Sneedville',           NULL,                'TN', 1, 'planned',  NULL,             1),
(8,  'TN',                   'New Market',        'TN', 1, 'building', 'Solar, Generator',1);

-- Update site 8 tower specs
UPDATE `sites` SET `tower_height_ft`=120, `tower_type`='Rohn 25', `lat`=36.0273, `lng`=-83.5351, `elevation_ft`=1000 WHERE `id`=8;
-- Update site 5 tower
UPDATE `sites` SET `tower_height_ft`=64 WHERE `id`=5;
-- Update site 2 tower
UPDATE `sites` SET `tower_height_ft`=100 WHERE `id`=2;
-- Update site 6
UPDATE `sites` SET `tower_height_ft`=25 WHERE `id`=6;
-- Update site 7
UPDATE `sites` SET `tower_height_ft`=25 WHERE `id`=7;

-- ── ASL SERVERS ──────────────────────────────────────────────
INSERT INTO `asl_servers` (`id`, `site_id`, `hostname`, `ip_address`, `asl_version`, `os`, `is_active`) VALUES
(1, 8, 'tn.w4bww.net',  '172.20.1.7', 'AllStarLink3', 'Debian 12', 1),
(2, 1, 'hub.ttn.radio', '172.20.0.5', 'AllStarLink3', 'Debian 12', 1);

-- ── SYSTEMS ──────────────────────────────────────────────────
-- Site 1 (TTN hub server)
INSERT INTO `systems` (`id`, `site_id`, `callsign`, `system_type`, `status`, `label`, `sort_order`, `is_public`) VALUES
(8, 1, 'W4BWW', 'hub', 'planned', 'HUB', 0, 1);

-- Site 2 (Middleton)
INSERT INTO `systems` (`id`, `site_id`, `callsign`, `system_type`, `status`, `label`, `freq_tx`, `freq_rx`, `band`, `access_type`, `access_code`, `sort_order`, `is_public`) VALUES
(5, 2, 'KW4ME', 'repeater', 'planned', '2m Repeater', 145.3300, 144.7300, '2m', 'CTCSS', '107.2', 0, 1),
(6, 2, 'KW4ME', 'repeater', 'planned', '6m Repeater', NULL,     NULL,     '6m', 'CTCSS', '107.2', 1, 1);

-- Site 8 (TN - W4BWW Piedmont systems)
INSERT INTO `systems` (`id`, `site_id`, `callsign`, `system_type`, `status`, `label`, `freq_tx`, `freq_rx`, `band`, `access_type`, `access_code`, `tx_power_watts`, `erp_watts`, `feedline_make`, `feedline_length_ft`, `sort_order`, `is_public`) VALUES
(1, 8, 'W4BWW', 'repeater',    'building', 'Primary 6m Repeater', 53.8700, 52.8700, '6m',  'CTCSS', '118.8', 300, 360, 'Andrew LDF 7/8"', 80, 0, 1),
(2, 8, 'W4BWW', 'repeater',    'building', '440 D125N',            440.4000,439.4000,'70cm','DCS',   '125',  NULL,NULL,NULL,NULL, 1, 1),
(3, 8, 'W4BWW', 'remote_base', 'building', '10m Repeater',         29.6200, 29.5200, '10m', 'CTCSS', '118.8',NULL,NULL,NULL,NULL, 2, 1),
(4, 8, 'W4BWW', 'remote_base', 'building', 'Remote Base',          145.5100,145.5100,'2m',  'CTCSS', '118.8',NULL,NULL,NULL,NULL, 3, 1);

-- ── SYS MODES ────────────────────────────────────────────────
INSERT INTO `sys_modes` (`system_id`, `mode`, `bandwidth_khz`, `fcc_emission`, `is_primary`) VALUES
(8, 'Hub', NULL,  NULL,      1),
(5, 'FM',  25.0, '16K0F3E',  1),
(6, 'FM',  25.0, '16K0F3E',  1),
(1, 'FM',  25.0, '16K0F3E',  1),
(2, 'FM',  25.0, '16K0F3E',  1),
(3, 'FM',  25.0, '16K0F3E',  1),
(4, 'FM',  25.0, '16K0F3E',  1);

-- ── SYS ASL ──────────────────────────────────────────────────
-- Site 1 TTN hub
INSERT INTO `sys_asl` (`system_id`, `server_id`, `asl_number`, `is_hub`, `label`) VALUES
(8, 2, '65392', 1, 'TTN Hub');

-- Site 2 Middleton
INSERT INTO `sys_asl` (`system_id`, `server_id`, `asl_number`, `is_hub`, `label`) VALUES
(5, NULL, '450320', 0, '2m Repeater'),
(6, NULL, '450321', 0, '6m TBD');

-- Site 8 TN W4BWW
INSERT INTO `sys_asl` (`system_id`, `server_id`, `asl_number`, `is_hub`, `label`) VALUES
(1, 1, '450330', 1, 'Hub'),
(1, 1, '450333', 0, '6m Node'),
(3, 1, '450332', 0, '10m Node'),
(4, 1, '450331', 0, '2m Remote Base');

-- ── SYS ECHOLINK ─────────────────────────────────────────────
INSERT INTO `sys_echolink` (`system_id`, `el_callsign`, `el_number`, `label`) VALUES
(1, 'W4BWW',   '535789', 'Primary'),
(1, 'W4BWW-L', '995754', 'Link'),
(1, 'W4BWW-R', '358104', 'Remote');

-- ── SERA RECORDS ─────────────────────────────────────────────
INSERT INTO `sera_records` (`system_id`, `sera_id`, `sera_guid`, `coordinated_at`, `trustee_call`, `alt_contact`) VALUES
(1, '7188', '6c2cfb81-5e2a-5ac7-2f56-c25ab498', '2025-11-16', 'W4BWW', 'WA4ADT dpefnor@comcast.net 901-335-9968');

-- ── SITE CREW ────────────────────────────────────────────────
INSERT INTO `site_crew` (`operator_id`, `site_id`, `role`, `can_edit_site`, `can_edit_systems`, `can_post_buildlog`, `can_manage_assets`, `can_nominate_crew`, `notify_buildlog`, `notify_scheduled_work`, `notify_telemetry_alarm`, `notify_system_status`, `notify_email`, `notify_portal`, `approved`, `approved_by`, `approved_at`) VALUES
-- W4BWW on TTN (site 1)
(1, 1, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, NOW()),
-- W4BWW on TN (site 8) — his primary site
(1, 8, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, NOW()),
-- KW4ME on Middleton
(2, 2, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, NOW()),
-- WB4SKY on Chattanooga
(8, 6, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, NOW()),
-- KB4REC on Beaver Ridge
(11, 4, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, NOW()),
-- KE4WX on Sneedville
(10, 7, 'site_manager', 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, NOW());

-- ── ROADMAP ITEMS ────────────────────────────────────────────
INSERT INTO `roadmap_items` (`phase`, `sort_order`, `title`, `description`, `item_type`, `status`) VALUES
-- Phase 1
(1, 1,  'TTN 501(c)(3) chartered',           'Tennessee nonprofit, EIN 41-2680033, Control #002066298',        'milestone', 'done'),
(1, 2,  'W4BWW Piedmont hub operational',    '4 ASL nodes, 120ft Rohn 25, solar primary, 6m/10m/2m/440',      'milestone', 'done'),
(1, 3,  'ttn.radio portal launched',         'Custom PHP portal with live AMI polling, node DB, admin panel',  'milestone', 'done'),
(1, 4,  'ARDC Phase 1 grant submitted',      '$55,567 requested for tower/solar/repeater hardware',            'milestone', 'done'),
(1, 5,  '10-15 initial 6m FM sites online',  'AllStar nodes + commercial Internet links',                      'goal',      'in_progress'),
(1, 6,  'AllScan URI201 remote base active', 'David Gleason URI201 v1.2 for W4BWW remote base',               'task',      'planned'),
(1, 7,  'Full 6m backbone linked',           'All Phase 1 sites interconnected via AllStarLink',               'goal',      'planned'),
(1, 8,  'Standardized tower site agreements','Template covering liability, access, power, no-cost hosting',    'task',      'planned'),
(1, 9,  'Automated telemetry dashboard',     'Solar volts, battery %, load amps, temp — live at ttn.radio',    'task',      'planned'),
(1, 10, 'ARRL Foundation grant',             '$5,000 education grant — October deadline',                      'milestone', 'in_progress'),
-- Phase 2
(2, 1,  'Replace Internet links with RF backbone','900MHz / 1.2 / 2.4 / 5.8GHz enterprise-grade P2P dishes',  'goal',  'planned'),
(2, 2,  'True mesh topology',                'Multiple redundant paths per site, self-healing',                 'goal',  'planned'),
(2, 3,  'Digital voice interfaces',          'DMR bridges on major hubs — FM + M17 + DMR',                     'task',  'planned'),
(2, 4,  'ARES / RACES coordination',         'TTN as recognized supplemental EmComm asset for Tennessee',      'goal',  'planned'),
(2, 5,  'Winlink gateway integration',        'Select sites with Winlink capability',                           'task',  'planned'),
(2, 6,  'ARDC Phase 2 grant application',    'RF backbone hardware funding',                                    'milestone','planned'),
(2, 7,  'Nashville / Middle TN link',        'Extend backbone west — Cumberland Plateau crossing',              'goal',  'planned'),
(2, 8,  'Memphis corridor node',             'West Tennessee anchor site',                                      'goal',  'planned'),
(2, 9,  'Sovereign AI nodes at each site',   'VLAN-isolated compute, Open WebUI, model-agnostic stack',        'goal',  'planned'),
(2, 10, 'AREDN mesh integration',            'RF mesh VLAN per site, Proxmox VM router bridges to AREDN',      'goal',  'planned'),
-- Phase 3
(3, 1,  'Zero commercial Internet dependency','Every link is dedicated Part-97 RF. No ISP required',           'goal',  'planned'),
(3, 2,  'Adaptive mesh rerouting',           'Outage-proof — ARDEN or HAMWan integration',                     'goal',  'planned'),
(3, 3,  'Statewide + regional coverage',     '6m analog + DMR/digital. Every county reachable',                'goal',  'planned'),
(3, 4,  'Open to all licensed amateurs',     'No dues for basic access — core principle maintained',           'goal',  'planned'),
(3, 5,  'Elmer program — training curriculum','Elmer the Elmers — build infrastructure that teaches',          'goal',  'planned'),
(3, 6,  'Annual TTN hamfest / buildathon',   'Community gathering + hands-on build event',                     'goal',  'planned');

-- ── PAGES ────────────────────────────────────────────────────
INSERT INTO `pages` (`slug`, `title`, `nav_label`, `nav_group`, `sort_order`, `is_published`, `body`) VALUES
('about', 'About TTN', 'About TTN', 'about', 1, 1,
'# The Technological Network (TTN)

**Connecting Amateur Radio Operators Through Resilient, Volunteer-Built, Ham-Owned Infrastructure**

## About TTN

The Technological Network (TTN) is a Tennessee nonprofit corporation dedicated to connecting amateur radio operators through resilient, volunteer-built, ham-owned infrastructure.

We begin with a statewide 6-meter FM repeater backbone linked by AllStarLink. This provides more than just ragchew — it is a practical lab for operators who want to build, not just use.

TTN evolves into decentralized mesh LAN networks (900 MHz–5.8 GHz+) that replace internet dependencies. We are building a self-healing system rooted in community ownership at zero cost to users.

---

## Core Tenets

**Stewardship over Consumption** — We are not just using radio; we are maintaining a legacy.

**Accountability of the Callsign** — You cannot tune a duplexer with a ghost.

**A Technical Sanctuary** — No dues, no gatekeeping. Your work is yours.

**The Living Archive** — Training today so the craft survives us.

---

## Core Principles from HOP

1. Error is Normal
2. Systems Drive Behavior
3. Blame Fixes Nothing
4. Learning is Vital
5. Response to Failure Matters

---

## ARRL Tennessee Section Manager Endorsement

> I have spoken numerous times with Bobby Whitaker W4BWW about the Tennessee Technology Network implementation of an amateur radio communications system in Tennessee. The proposed use of 6 meter repeaters offers several valuable benefits, primarily enormous room for expansion utilizing an uncrowded band that performs well across various terrain.

**David R. Thomas KM4NYI**
Section Manager — ARRL Tennessee Section'),

('mission', 'Mission & Principles', 'Mission', 'about', 2, 1,
'## Why TTN Exists

The Technological Network exists because the craft is dying quietly.

Duplexer tuning. Tower work. RF propagation knowledge. Repeater builds from scratch. These are skills carried by a generation of hams going silent key faster than they can pass them on. TTN is the teachers lounge — a place to elmer the elmers before the knowledge goes with them.

---

## Core Tenets

**Stewardship over Consumption** — We are not just using radio; we are maintaining a legacy.

**Accountability of the Callsign** — You cannot tune a duplexer with a ghost. Mastery requires putting your callsign behind your physical work.

**A Technical Sanctuary** — No dues mining. No gatekeeping. Your intellectual property is yours.

**The Living Archive** — Training today so the craft survives us.

---

## HOP Principles

1. Error is Normal
2. Systems Drive Behavior
3. Blame Fixes Nothing
4. Learning is Vital
5. Response to Failure Matters

---

## Long-Term Vision

**Phase 1 (Now-2026):** 6m FM backbone, AllStar-linked statewide.

**Phase 2 (2026-2029):** Replace internet links with sovereign 900MHz-5.8GHz RF mesh.

**Phase 3 (2029+):** Zero internet dependency. Self-healing distributed network.

---

> A system must have an aim. Without an aim, there is no system. — W. Edwards Deming'),

('6m-initiative', '6 Meter Initiative', '6m Initiative', 'network', 10, 1,
'## The 6 Meter Backbone Initiative

TTN is building a statewide 6-meter FM repeater backbone across Tennessee and into north Mississippi.

6 meters is the magic band — wide enough for statewide links, underutilized enough to coordinate cleanly, and propagation characteristics that favor the terrain of East Tennessee.

## Why 6 Meters

- Uncrowded — far less interference than 2m/70cm repeater bands
- Excellent terrain penetration for ridge-to-ridge links
- SERA coordination available statewide
- 50MHz allows higher ERP on modest tower heights

## Support the Initiative

[GoFundMe — Support TTN 6m Network](https://www.gofundme.com/f/support-ttns-6meter-network-initiative)

Phase 1 hardware funds: Rohn 25 sections, duplexers, CDM radios, solar systems, coax.'),

('network', 'Network Map', 'Network Map', 'network', 11, 1,
'## Phase 1 Coverage

TTN Phase 1 targets coverage across Tennessee with 10-15 sites by end 2026.

![Coverage Map](/assets/img/Estimate_Coverage_Map.png)

Sites are linked via AllStarLink during Phase 1 while RF backbone links are engineered for Phase 2.

## Site Status

See the [Sites page](/sites/) for current status of all Phase 1 locations.'),

('frequencies', 'Frequencies', 'Frequencies', 'network', 12, 1,
'## TTN Frequency Reference

| Site | TX | RX | PL | Band | ASL |
|------|----|----|-----|------|-----|
| W4BWW Piedmont 6m | 53.870 | 52.870 | 118.8 | 6m | 450333 |
| W4BWW Piedmont 2m RB | 145.510 | 145.510 | 118.8 | 2m | 450331 |
| W4BWW Piedmont 10m RB | 29.620 | 29.520 | 118.8 | 10m | 450332 |
| KW4ME Middleton 2m | 145.330 | 144.730 | 107.2 | 2m | 450320 |
| KW4ME Middleton 6m | TBD | TBD | 107.2 | 6m | 450321 |

## Hub Node

AllStarLink Hub: **65392** · hub.ttn.radio

Connect via AllStar: Dial **65392** from any AllStar node.'),

('emcomm', 'EmComm', 'EmComm', 'network', 13, 1,
'## Emergency Communications

TTN infrastructure is built for resilience. Solar primary power, battery backup, and RF backbone links ensure operation when commercial infrastructure fails.

## Capabilities

- 6m FM voice — wide area coverage
- AllStarLink linking — coordinate across the state
- Phase 2: RF-only backbone — no internet dependency
- Solar + battery — 72+ hour autonomy at full load

## Coordination

TTN is working toward ARES/RACES coordination agreements as a recognized supplemental EmComm asset for Tennessee.

Contact W4BWW for EmComm coordination inquiries.'),

('connect', 'Connect / On the Air', 'Connect', 'operators', 20, 1,
'## W4BWW Piedmont Hub — New Market, TN

| Node | Freq | PL | Type |
|------|------|----|------|
| ASL 450330 | Hub | — | AllStar Hub |
| ASL 450331 | 145.510 MHz | 118.8 | Remote Base 2m |
| ASL 450332 | 29.620 MHz | 118.8 | Remote Base 10m |
| ASL 450333 | 53.870 MHz | 118.8 | 6m Repeater |

EchoLink: W4BWW #535789 · W4BWW-L #995754 · W4BWW-R #358104

## KW4ME Middleton Hub

| Node | Freq | PL | Type |
|------|------|----|------|
| ASL 450320 | 145.330 MHz | 107.2 | 2m Repeater |
| ASL 450321 | TBD | 107.2 | 6m TBD |

## Connect via AllStar

Dial node **65392** from any AllStar node to reach the TTN hub at hub.ttn.radio.'),

('builders', 'Build a Site', 'Build a Site', 'operators', 21, 1,
'## Want to Host a TTN Node?

TTN is looking for licensed amateur radio operators in Tennessee and surrounding states who can host a repeater or remote base site.

## What We Need

- Tower or rooftop access (25ft minimum, 60ft+ preferred)
- Power availability (grid or solar)
- Willingness to be the on-site point of contact
- Amateur radio license (Technician or above)

## What TTN Provides

- Duplexer and antenna hardware
- AllStar node software and support
- SERA frequency coordination assistance
- Remote monitoring and management
- Connection to the statewide backbone

## Get in Touch

Contact Bobby W4BWW to discuss your site and start the conversation.

[Email W4BWW](mailto:bobwwj555@gmail.com) · 865-202-6696');

-- ── NETWORK SUBNETS (Piedmont) ───────────────────────────────
INSERT INTO `network_subnets` (`site_id`, `subnet`, `cidr`, `network_type`, `label`, `gateway`, `is_ttn_managed`) VALUES
(8, '172.19.0.0', 24, 'lan',     'TN Network — Main',         '172.19.0.1', 0),
(8, '172.19.1.0', 24, 'lan',     'TN Network — Devices',      '172.19.0.1', 0),
(8, '172.19.4.0', 24, 'cameras', 'TN Network — Cameras',      '172.19.0.1', 0),
(8, '172.20.0.0', 24, 'lan',     'TTN Network — Main',        '172.20.0.1', 1),
(8, '172.20.1.0', 24, 'lan',     'TTN Network — Devices',     '172.20.0.1', 1),
(8, '172.20.4.0', 24, 'cameras', 'TTN Network — Cameras',     '172.20.0.1', 0);

-- Remote site subnets
INSERT INTO `network_subnets` (`site_id`, `subnet`, `cidr`, `network_type`, `label`, `gateway`, `is_ttn_managed`) VALUES
(3, '172.22.0.0', 24, 'lan', 'Cookeville Network',    '172.22.0.1', 1),
(6, '172.23.0.0', 24, 'lan', 'Chattanooga Network',   '172.23.0.1', 1),
(7, '172.24.0.0', 24, 'lan', 'Sneedville Network',    '172.24.0.1', 1);

-- ── NETWORK DEVICES (key TTN devices) ────────────────────────
INSERT INTO `network_devices` (`site_id`, `hostname`, `make`, `model`, `device_type`, `is_physical`, `function`, `os`, `ip_address`, `mac_address`, `port_speed`, `web_ports`, `asl_nodes_json`, `asl_port`, `is_ttn_managed`, `is_active`) VALUES
-- TTN hub server
(1, 'TTN',       NULL, 'PVE2',        'allstar_server', 0, 'AllStar Server', 'Debian 12', '172.20.0.5', NULL, NULL, '80,443,9090,5200', '["65392"]', 4571, 1, 1),
-- TN AllStar server (KitchenArmor)
(8, 'TN',        NULL, 'KitchenArmor','allstar_server', 1, 'AllStar Server', 'Debian 12', '172.20.1.7', '00:e2:69:5a:15:37', '1GB', '80,443,9090,5200', '["1950","450330","450331","450332","450333"]', 4570, 1, 1),
-- HF node
(8, 'nodehf',    NULL, 'Panasonic CF-19','allstar_server',1,'AllStar Server','Debian 12', '172.20.1.6', '04-20-9A-45-BC-0C', NULL, NULL, '["1992","1998"]', 4572, 1, 1),
-- Shack AllStar
(8, 'BWWShack',  NULL, 'KitchenArmor','allstar_server', 1, 'AllStar Server', 'Debian 12', '172.20.1.8', NULL, '1GB', NULL, '["1850","1851","1853","1996","1997"]', 4573, 1, 1),
-- Proxmox host
(8, 'PVE2',      NULL, NULL,          'server',         1, 'Host Server',    'PVE',       '172.20.0.10','00:25:90:2a:ee:82','10GB',NULL, NULL, NULL, 1, 1),
-- NTP
(8, 'TIC',       'TimeMachines','TM2000B','ntp_clock',   1, 'NTP Clock',      NULL,        '172.20.1.3', '14:7f:0f:7e:92:aa',NULL,'123', NULL, NULL, 1, 1),
(8, 'TOC',       'Masterclock','GMR5000-HSO-3','ntp_clock',1,'NTP Clock/Timing',NULL,      '172.20.1.3', NULL, NULL, '123', NULL, NULL, 1, 1),
-- DMR
(8, 'pisbww',    NULL, 'Pi-Star by RepBldr','dmr_server',1,'PiStar Multimode','PI-OS',    '172.20.1.12','2A-70-69-51-49-70',NULL,NULL, NULL, NULL, 0, 1),
-- APRS
(8, 'aprsdigi',  NULL, NULL,          'aprs',           1, 'APRS Digipeater', NULL,        '172.20.1.11',NULL, NULL, NULL, NULL, NULL, 0, 1),
-- SDR
(8, 'BWWHF',     'FlexRadio',NULL,    'sdr_radio',      1, 'HF SDR Radio',   'FlexFirmware','172.20.1.55','00-1C-2D-05-2A-AC',NULL,NULL,NULL, NULL, 0, 1),
-- Camera server
(8, 'BlueIris',  NULL, NULL,          'camera_server',  0, 'Camera Server',  'Windows 11','172.20.0.4', 'BC-24-11-9C-1A-29',NULL,NULL, NULL, NULL, 0, 1),
-- Firewall
(8, 'TTNW',      NULL, 'ER8411',      'firewall',       1, 'Firewall',        NULL,        '172.20.0.1', NULL, '10GB', NULL, NULL, NULL, 1, 1),
-- Chattanooga
(6, 'ChattW',    'TPLink','ER605',    'firewall',       1, 'Firewall',        NULL,        '172.23.0.1', 'E4-FA-C4-40-02-A6',NULL,NULL, NULL, NULL, 1, 1),
(6, 'Chatt',     NULL, 'KitchenArmor','allstar_server', 1, 'AllStar Server', 'Debian 12', '172.23.1.6', 'B8-27-EB-13-E5-F9','1GB',NULL,'["627860","627861"]',4571,1,1);

SELECT 'Seed data inserted successfully.' AS result;
SELECT 'operators' AS tbl, COUNT(*) AS cnt FROM operators
UNION ALL SELECT 'sites', COUNT(*) FROM sites
UNION ALL SELECT 'systems', COUNT(*) FROM systems
UNION ALL SELECT 'sys_asl', COUNT(*) FROM sys_asl
UNION ALL SELECT 'site_crew', COUNT(*) FROM site_crew
UNION ALL SELECT 'pages', COUNT(*) FROM pages
UNION ALL SELECT 'site_settings', COUNT(*) FROM site_settings
UNION ALL SELECT 'roadmap_items', COUNT(*) FROM roadmap_items
UNION ALL SELECT 'network_devices', COUNT(*) FROM network_devices;
