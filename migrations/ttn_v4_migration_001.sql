-- ══════════════════════════════════════════════════════════════
-- TTN Migration 001
-- Tennessee Technological Community · ttn.radio
--
-- Run in phpMyAdmin on obdswlpx_ttn
-- Safe to run — additive only, nothing existing is removed
-- ══════════════════════════════════════════════════════════════


-- ── 1. ADD site_admin TO ROLE ENUM ───────────────────────────
-- Inserts site_admin between operator and admin
-- All existing role values unchanged
ALTER TABLE `operators`
    MODIFY COLUMN `role`
    ENUM('viewer','operator','site_admin','admin')
    NOT NULL DEFAULT 'viewer';


-- ── 2. OPERATOR SITE ACCESS ──────────────────────────────────
-- Scopes site_admin and operator roles to specific sites
-- A site_admin with no rows here has no site access
-- super_admin (Bobby) bypasses this table entirely in code
-- granted_by tracks who gave the access for audit trail
CREATE TABLE IF NOT EXISTS `operator_site_access` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `operator_id`  INT UNSIGNED NOT NULL,
    `site_id`      INT UNSIGNED NOT NULL,
    `access_level` ENUM('viewer','operator','site_admin') NOT NULL DEFAULT 'operator',
    `granted_by`   INT UNSIGNED NOT NULL COMMENT 'operator.id of who granted access',
    `granted_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`        VARCHAR(200) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_op_site` (`operator_id`, `site_id`),
    KEY `fk_osa_op`      (`operator_id`),
    KEY `fk_osa_site`    (`site_id`),
    KEY `fk_osa_granted` (`granted_by`),
    CONSTRAINT `fk_osa_op`      FOREIGN KEY (`operator_id`) REFERENCES `operators` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_osa_site`    FOREIGN KEY (`site_id`)     REFERENCES `sites`     (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_osa_granted` FOREIGN KEY (`granted_by`)  REFERENCES `operators` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Scopes operator/site_admin access to specific sites';


-- ── 3. SYSTEM INTERFACES ─────────────────────────────────────
-- Per-system interface links — supermon, allmon3, allscan, etc.
-- Each site operator manages their own entries
-- is_public controls whether link shows on public site detail page
CREATE TABLE IF NOT EXISTS `sys_interfaces` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `system_id`      INT UNSIGNED  NOT NULL,
    `label`          VARCHAR(60)   NOT NULL COMMENT 'Display name e.g. Supermon, AllScan',
    `url`            VARCHAR(255)  NOT NULL,
    `interface_type` ENUM('supermon','allmon3','allscan','allscanx','stream','camera','custom')
                     NOT NULL DEFAULT 'custom',
    `is_public`      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1 = show on public detail page',
    `sort_order`     TINYINT(4)    NOT NULL DEFAULT 0,
    `notes`          VARCHAR(200)  NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_si_sys` (`system_id`),
    KEY `idx_si_public` (`is_public`),
    CONSTRAINT `fk_si_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Interface links per system — supermon, allmon3, allscan etc';


-- ── 4. RECORDINGS ────────────────────────────────────────────
-- Index of audio recordings — file lives on node or hub
-- Node stores buffer locally, hub pulls copy on schedule
-- pulled_to_hub = 1 means safe copy exists on hub.ttn.radio
-- hub_path is the path on the hub server once pulled
CREATE TABLE IF NOT EXISTS `recordings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `system_id`     INT UNSIGNED  NOT NULL,
    `server_id`     INT UNSIGNED  NOT NULL COMMENT 'asl_servers.id — which node recorded it',
    `filename`      VARCHAR(200)  NOT NULL,
    `file_url`      VARCHAR(255)  NOT NULL COMMENT 'URL on originating node',
    `recorded_at`   DATETIME      NOT NULL,
    `duration_sec`  SMALLINT      NULL,
    `file_size_kb`  INT           NULL,
    `is_public`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = accessible on public listen page',
    `pulled_to_hub` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = safe copy on hub.ttn.radio',
    `hub_path`      VARCHAR(255)  NULL COMMENT 'Path on hub.ttn.radio once pulled',
    `pull_attempts` TINYINT       NOT NULL DEFAULT 0 COMMENT 'Track failed pull attempts',
    `notes`         VARCHAR(200)  NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_rec_sys`    (`system_id`),
    KEY `fk_rec_srv`    (`server_id`),
    KEY `idx_rec_time`  (`recorded_at`),
    KEY `idx_rec_hub`   (`pulled_to_hub`),
    KEY `idx_rec_pub`   (`is_public`),
    CONSTRAINT `fk_rec_sys` FOREIGN KEY (`system_id`) REFERENCES `systems`     (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rec_srv` FOREIGN KEY (`server_id`) REFERENCES `asl_servers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recording index — audio lives on node or hub, this is the manifest';


-- ── VERIFY ───────────────────────────────────────────────────
SELECT 'Migration 001 complete' AS result;
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'operators'
    AND COLUMN_NAME = 'role';
SHOW TABLES LIKE '%operator_site%';
SHOW TABLES LIKE '%sys_interfaces%';
SHOW TABLES LIKE '%recordings%';
