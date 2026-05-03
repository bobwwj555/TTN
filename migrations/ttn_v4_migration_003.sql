-- ══════════════════════════════════════════════════════════════
-- TTN Migration 003 (fixed)
-- Tennessee Technological Community · ttn.radio
-- ══════════════════════════════════════════════════════════════

ALTER TABLE `sera_records`
    ADD COLUMN `status`           ENUM('coordinated','pending','expired','denied','recertified')
                                  NOT NULL DEFAULT 'pending'
                                  AFTER `sera_id`,
    ADD COLUMN `freq_tx`          VARCHAR(12)  NULL AFTER `status`,
    ADD COLUMN `freq_rx`          VARCHAR(12)  NULL AFTER `freq_tx`,
    ADD COLUMN `freq_band`        VARCHAR(20)  NULL AFTER `freq_rx`,
    ADD COLUMN `access_type`      VARCHAR(20)  NULL AFTER `freq_band`,
    ADD COLUMN `access_code`      VARCHAR(10)  NULL AFTER `access_type`,
    ADD COLUMN `mode`             VARCHAR(30)  NULL AFTER `access_code`,
    ADD COLUMN `fm_mode`          VARCHAR(60)  NULL AFTER `mode`,
    ADD COLUMN `erp_watts`        SMALLINT     NULL AFTER `fm_mode`,
    ADD COLUMN `output_watts`     SMALLINT     NULL AFTER `erp_watts`,
    ADD COLUMN `antenna_gain_db`  DECIMAL(4,1) NULL AFTER `output_watts`,
    ADD COLUMN `feed_line`        VARCHAR(80)  NULL AFTER `antenna_gain_db`,
    ADD COLUMN `feed_line_ft`     SMALLINT     NULL AFTER `feed_line`,
    ADD COLUMN `duplexer_loss_db` DECIMAL(4,1) NULL AFTER `feed_line_ft`,
    ADD COLUMN `gamsl_ft`         SMALLINT     NULL AFTER `duplexer_loss_db`,
    ADD COLUMN `ahag_ft`          SMALLINT     NULL AFTER `gamsl_ft`,
    ADD COLUMN `antenna_pattern`  VARCHAR(20)  NULL AFTER `ahag_ft`,
    ADD COLUMN `lat`              DECIMAL(9,6) NULL AFTER `antenna_pattern`,
    ADD COLUMN `lng`              DECIMAL(9,6) NULL AFTER `lat`,
    ADD COLUMN `location_desc`    VARCHAR(200) NULL AFTER `lng`,
    ADD COLUMN `features`         VARCHAR(200) NULL AFTER `location_desc`,
    ADD COLUMN `expires_at`       DATE         NULL AFTER `coordinated_at`,
    ADD COLUMN `recertified_at`   DATE         NULL AFTER `expires_at`,
    ADD COLUMN `publish_journal`  TINYINT(1)   NOT NULL DEFAULT 1 AFTER `recertified_at`,
    ADD COLUMN `alt_call`         VARCHAR(12)  NULL AFTER `alt_contact`,
    ADD COLUMN `alt_phone`        VARCHAR(20)  NULL AFTER `alt_call`,
    ADD COLUMN `alt_email`        VARCHAR(80)  NULL AFTER `alt_phone`;


-- ── SEED W4BWW SERA RECORD ────────────────────────────────────
-- Check system ID first:
-- SELECT id, callsign, label, freq_tx FROM systems WHERE callsign='W4BWW' ORDER BY sort_order;

INSERT INTO `sera_records` (
    `system_id`, `sera_id`, `sera_guid`, `status`,
    `freq_tx`, `freq_rx`, `freq_band`,
    `access_type`, `access_code`, `mode`, `fm_mode`,
    `erp_watts`, `output_watts`, `antenna_gain_db`,
    `feed_line`, `feed_line_ft`, `duplexer_loss_db`,
    `gamsl_ft`, `ahag_ft`, `antenna_pattern`,
    `lat`, `lng`, `location_desc`, `features`,
    `coordinated_at`, `expires_at`,
    `trustee_call`, `alt_contact`, `alt_call`, `alt_phone`, `alt_email`,
    `publish_journal`, `notes`
)
SELECT
    s.id,
    '7188', '6c2cfb81-5e2a-5ac7-2f56-c25ab498', 'coordinated',
    '53.8700', '52.8700', '50 MHz',
    'CTCSS', '118.8', 'FM', 'Wideband FM - 25 KHz FM (16K0F3E)',
    360, 300, 3.0,
    '7/8 LDF', 80, 2.0,
    1000, 80, 'Omni',
    36.027360, -83.535111, '2266 Piedmont Rd New Market TN 37820',
    'Emergency Power,Solar Power,Linked,Remote Base,Echolink',
    '2025-11-16', '2026-11-16',
    'W4BWW', 'Daniel P Efnor WA4ADT · dpefnor@comcast.net · (901) 335-9968',
    'WA4ADT', '(901) 335-9968', 'dpefnor@comcast.net',
    1, 'Hub for TTN.radio · Moving from 53.39 due to noise. Recertify by 2026-11-16.'
FROM systems s
WHERE s.callsign = 'W4BWW' AND s.freq_tx = '53.8700'
LIMIT 1;


-- ── VERIFY ───────────────────────────────────────────────────
SELECT 'Migration 003 complete' AS result;
SELECT id, sera_id, status, freq_tx, freq_rx, erp_watts, coordinated_at, expires_at
FROM sera_records;
