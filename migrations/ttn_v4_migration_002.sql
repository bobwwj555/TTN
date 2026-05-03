-- ══════════════════════════════════════════════════════════════
-- TTN Migration 002 (fixed)
-- Tennessee Technological Community · ttn.radio
-- ══════════════════════════════════════════════════════════════


-- ── EXTEND sys_asl ────────────────────────────────────────────
ALTER TABLE `sys_asl`
    ADD COLUMN `callsign`          VARCHAR(12)  NULL
        COMMENT 'Node callsign — TTN registry, not from lsnodes'
        AFTER `asl_number`,

    ADD COLUMN `node_type`         ENUM('backbone','hub','remote_base','private','link','unknown')
        NOT NULL DEFAULT 'backbone'
        AFTER `callsign`,

    ADD COLUMN `visibility`        ENUM('public','ttn_private','operator_private')
        NOT NULL DEFAULT 'public'
        AFTER `node_type`,

    ADD COLUMN `owner_operator_id` INT UNSIGNED NULL
        COMMENT 'operators.id — who manages this node (null = TTN managed)'
        AFTER `visibility`,

    ADD COLUMN `freq_tx`           VARCHAR(10)  NULL
        AFTER `owner_operator_id`,

    ADD COLUMN `access_code`       VARCHAR(10)  NULL
        AFTER `freq_tx`,

    ADD COLUMN `location_note`     VARCHAR(120) NULL
        AFTER `access_code`,

    ADD COLUMN `notes`             TEXT         NULL
        AFTER `location_note`,

    ADD COLUMN `is_active`         TINYINT(1)   NOT NULL DEFAULT 1
        AFTER `notes`,

    ADD KEY `idx_asl_visibility` (`visibility`),
    ADD KEY `idx_asl_active`     (`is_active`),
    ADD CONSTRAINT `fk_asl_owner`
        FOREIGN KEY (`owner_operator_id`)
        REFERENCES `operators` (`id`)
        ON DELETE SET NULL;


-- ── EXTEND asl_servers — new columns only ────────────────────
ALTER TABLE `asl_servers`
    ADD COLUMN `has_isp`                  TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = no public ISP yet, skip live polling'
        AFTER `ami_timeout`,

    ADD COLUMN `ttn_logger_installed`     TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = ttn-logger.php deployed and cron running'
        AFTER `has_isp`,

    ADD COLUMN `ttn_status_installed`     TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = ttn-status.php deployed'
        AFTER `ttn_logger_installed`,

    ADD COLUMN `last_seen`                TIMESTAMP  NULL
        COMMENT 'Last successful telemetry POST from this server'
        AFTER `ttn_status_installed`;


-- ── VERIFY ───────────────────────────────────────────────────
SELECT 'Migration 002 complete' AS result;
SHOW COLUMNS FROM sys_asl;
SHOW COLUMNS FROM asl_servers;
