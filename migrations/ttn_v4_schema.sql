-- ============================================================
-- TTN Database Schema v4 — Complete Rebuild
-- Tennessee Technological Community · ttn.radio
-- MariaDB 11.4 · obdswlpx_ttn
--
-- INSTRUCTIONS:
-- 1. Back up existing DB first via phpMyAdmin Export
-- 2. Run this entire file in phpMyAdmin SQL tab
-- 3. Then run ttn_v4_seed.sql for initial data
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ── DROP OLD TABLES ──────────────────────────────────────────
DROP TABLE IF EXISTS `dmr_talkgroups`;
DROP TABLE IF EXISTS `sys_dmr`;
DROP TABLE IF EXISTS `sys_echolink`;
DROP TABLE IF EXISTS `sys_asl`;
DROP TABLE IF EXISTS `sys_modes`;
DROP TABLE IF EXISTS `sys_telemetry`;
DROP TABLE IF EXISTS `systems`;
DROP TABLE IF EXISTS `sera_records`;
DROP TABLE IF EXISTS `site_rf_inventory`;
DROP TABLE IF EXISTS `site_telemetry`;
DROP TABLE IF EXISTS `site_crew`;
DROP TABLE IF EXISTS `asl_servers`;
DROP TABLE IF EXISTS `sites`;
DROP TABLE IF EXISTS `operator_radio_ids`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `operators`;
DROP TABLE IF EXISTS `buildlog`;
DROP TABLE IF EXISTS `assets`;
DROP TABLE IF EXISTS `roadmap_items`;
DROP TABLE IF EXISTS `pages`;
DROP TABLE IF EXISTS `site_settings`;
DROP TABLE IF EXISTS `network_devices`;
DROP TABLE IF EXISTS `network_subnets`;
-- Legacy tables
DROP TABLE IF EXISTS `nodes`;
DROP TABLE IF EXISTS `node_frequencies`;
DROP TABLE IF EXISTS `node_asl`;

-- ── OPERATORS ────────────────────────────────────────────────
CREATE TABLE `operators` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `callsign`      VARCHAR(12)     NOT NULL,
    `display_name`  VARCHAR(80)     NOT NULL DEFAULT '',
    `bio`           TEXT            NULL,
    `qrz_url`       VARCHAR(120)    NULL,
    `photo_url`     VARCHAR(255)    NULL,
    `city`          VARCHAR(80)     NULL,
    `state`         VARCHAR(2)      NULL,
    `is_public`     TINYINT(1)      NOT NULL DEFAULT 1,
    `sort_order`    TINYINT(4)      NOT NULL DEFAULT 0,
    `email`         VARCHAR(120)    NULL,
    `phone`         VARCHAR(20)     NULL,
    `password_hash` VARCHAR(255)    NOT NULL DEFAULT '',
    `role`          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `last_login`    TIMESTAMP       NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_callsign` (`callsign`),
    UNIQUE KEY `uq_email` (`email`),
    KEY `idx_role` (`role`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── OPERATOR RADIO IDs ───────────────────────────────────────
CREATE TABLE `operator_radio_ids` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `operator_id` INT UNSIGNED NOT NULL,
    `mode`        ENUM('DMR','P25','NXDN','other') NOT NULL DEFAULT 'DMR',
    `radio_id`    VARCHAR(20)  NOT NULL,
    `notes`       VARCHAR(80)  NULL,
    PRIMARY KEY (`id`),
    KEY `fk_rid_op` (`operator_id`),
    CONSTRAINT `fk_rid_op` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PASSWORD RESETS ──────────────────────────────────────────
CREATE TABLE `password_resets` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `operator_id` INT UNSIGNED NOT NULL,
    `token`       VARCHAR(64)  NOT NULL,
    `expires_at`  DATETIME     NOT NULL,
    `used`        TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `fk_pr_op` (`operator_id`),
    CONSTRAINT `fk_pr_op` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SITES ────────────────────────────────────────────────────
-- Physical locations (towers, rooftops, shacks)
CREATE TABLE `sites` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(80)     NOT NULL,
    `city`             VARCHAR(80)     NULL,
    `state`            VARCHAR(2)      NULL DEFAULT 'TN',
    `county`           VARCHAR(60)     NULL,
    `lat`              DECIMAL(9,6)    NULL,
    `lng`              DECIMAL(9,6)    NULL,
    `elevation_ft`     SMALLINT        NULL COMMENT 'GAMSL',
    `tower_height_ft`  SMALLINT        NULL COMMENT 'AHAG',
    `tower_type`       VARCHAR(40)     NULL COMMENT 'Rohn 25, self-support, etc',
    `power_primary`    VARCHAR(40)     NULL COMMENT 'Solar, Grid, Generator',
    `power_backup`     VARCHAR(40)     NULL,
    `battery_ah`       SMALLINT        NULL,
    `solar_watts`      SMALLINT        NULL,
    `camera_ftp_path`  VARCHAR(200)    NULL,
    `weather_station`  VARCHAR(60)     NULL,
    `photo_url`        VARCHAR(255)    NULL,
    `coverage_url`     VARCHAR(255)    NULL,
    `notes`            TEXT            NULL,
    `status`           ENUM('live','building','planned','offline') NOT NULL DEFAULT 'planned',
    `phase`            TINYINT         NOT NULL DEFAULT 1,
    `is_public`        TINYINT(1)      NOT NULL DEFAULT 1,
    `site_api_key`     VARCHAR(64)     NULL COMMENT 'For IoT telemetry POST auth',
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SITE CREW ────────────────────────────────────────────────
-- Who works at which site, with what role and permissions
CREATE TABLE `site_crew` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `operator_id`            INT UNSIGNED NOT NULL,
    `site_id`                INT UNSIGNED NOT NULL,
    `role`                   ENUM('site_manager','operator','builder','alternate','observer') NOT NULL DEFAULT 'operator',
    -- Permissions
    `can_edit_site`          TINYINT(1) NOT NULL DEFAULT 0,
    `can_edit_systems`       TINYINT(1) NOT NULL DEFAULT 0,
    `can_post_buildlog`      TINYINT(1) NOT NULL DEFAULT 1,
    `can_manage_assets`      TINYINT(1) NOT NULL DEFAULT 0,
    `can_nominate_crew`      TINYINT(1) NOT NULL DEFAULT 0,
    -- Notifications
    `notify_buildlog`        TINYINT(1) NOT NULL DEFAULT 1,
    `notify_scheduled_work`  TINYINT(1) NOT NULL DEFAULT 1,
    `notify_telemetry_alarm` TINYINT(1) NOT NULL DEFAULT 0,
    `notify_system_status`   TINYINT(1) NOT NULL DEFAULT 0,
    `notify_email`           TINYINT(1) NOT NULL DEFAULT 1,
    `notify_portal`          TINYINT(1) NOT NULL DEFAULT 1,
    -- Approval workflow
    `approved`               TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by`            INT UNSIGNED NULL,
    `approved_at`            DATETIME NULL,
    `nominated_by`           INT UNSIGNED NULL,
    `nomination_note`        VARCHAR(200) NULL,
    `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_op_site` (`operator_id`, `site_id`),
    KEY `fk_crew_op` (`operator_id`),
    KEY `fk_crew_site` (`site_id`),
    CONSTRAINT `fk_crew_op`   FOREIGN KEY (`operator_id`) REFERENCES `operators` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crew_site` FOREIGN KEY (`site_id`)     REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ASL SERVERS ──────────────────────────────────────────────
-- AllStar server/PC/Pi at a site
CREATE TABLE `asl_servers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`     INT UNSIGNED NOT NULL,
    `hostname`    VARCHAR(80)  NOT NULL COMMENT 'e.g. tn.w4bww.net',
    `ip_address`  VARCHAR(45)  NULL,
    `asl_version` VARCHAR(20)  NULL COMMENT 'AllStarLink3, ASL2, etc',
    `os`          VARCHAR(40)  NULL,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`       TEXT         NULL,
    PRIMARY KEY (`id`),
    KEY `fk_asls_site` (`site_id`),
    CONSTRAINT `fk_asls_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYSTEMS ──────────────────────────────────────────────────
-- TX/RX pairs at a site (repeater, remote base, link, hub, beacon)
CREATE TABLE `systems` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`          INT UNSIGNED    NOT NULL,
    `callsign`         VARCHAR(12)     NOT NULL COMMENT 'FCC trustee callsign',
    `system_type`      ENUM('repeater','remote_base','link','hub','beacon','other') NOT NULL DEFAULT 'repeater',
    `status`           ENUM('live','building','planned','offline') NOT NULL DEFAULT 'planned',
    `label`            VARCHAR(80)     NULL COMMENT 'e.g. Primary 6m Repeater',
    `freq_tx`          DECIMAL(8,4)    NULL,
    `freq_rx`          DECIMAL(8,4)    NULL,
    `band`             VARCHAR(10)     NULL COMMENT '6m, 2m, 10m, 70cm, etc',
    `access_type`      ENUM('CTCSS','DCS','none','carrier') NOT NULL DEFAULT 'CTCSS',
    `access_code`      VARCHAR(10)     NULL COMMENT 'PL tone or DCS code',
    -- RF specs
    `antenna_make`     VARCHAR(60)     NULL,
    `antenna_model`    VARCHAR(60)     NULL,
    `antenna_gain_db`  DECIMAL(4,1)    NULL,
    `antenna_pattern`  VARCHAR(20)     NULL DEFAULT 'omni',
    `feedline_make`    VARCHAR(60)     NULL,
    `feedline_length_ft` SMALLINT     NULL,
    `feedline_loss_db` DECIMAL(4,2)    NULL,
    `tx_power_watts`   SMALLINT        NULL,
    `duplexer_loss_db` DECIMAL(4,2)    NULL,
    `erp_watts`        SMALLINT        NULL,
    -- Coordination
    `sera_id`          VARCHAR(20)     NULL,
    `intermod_notes`   TEXT            NULL,
    `photo_url`        VARCHAR(255)    NULL,
    `notes`            TEXT            NULL,
    `sort_order`       TINYINT         NOT NULL DEFAULT 0,
    `is_public`        TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sys_site` (`site_id`),
    KEY `idx_sys_status` (`status`),
    KEY `idx_sys_public` (`is_public`),
    CONSTRAINT `fk_sys_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYS MODES ────────────────────────────────────────────────
-- Modes per system (FM, DMR, M17 — multiple per system)
CREATE TABLE `sys_modes` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`     INT UNSIGNED NOT NULL,
    `mode`          ENUM('FM','DMR','M17','Fusion','DStar','P25','NXDN','Hub','other') NOT NULL DEFAULT 'FM',
    `bandwidth_khz` DECIMAL(5,1) NULL,
    `fcc_emission`  VARCHAR(20)  NULL COMMENT '16K0F3E, 7K60FXD, etc',
    `is_primary`    TINYINT(1)   NOT NULL DEFAULT 0,
    `notes`         VARCHAR(120) NULL,
    PRIMARY KEY (`id`),
    KEY `fk_mode_sys` (`system_id`),
    CONSTRAINT `fk_mode_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYS ASL ──────────────────────────────────────────────────
-- AllStar node numbers per system
CREATE TABLE `sys_asl` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`   INT UNSIGNED NOT NULL,
    `server_id`   INT UNSIGNED NULL,
    `asl_number`  VARCHAR(10)  NOT NULL,
    `is_hub`      TINYINT(1)   NOT NULL DEFAULT 0,
    `label`       VARCHAR(60)  NULL,
    PRIMARY KEY (`id`),
    KEY `fk_asl_sys` (`system_id`),
    KEY `fk_asl_srv` (`server_id`),
    CONSTRAINT `fk_asl_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_asl_srv` FOREIGN KEY (`server_id`) REFERENCES `asl_servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYS ECHOLINK ─────────────────────────────────────────────
CREATE TABLE `sys_echolink` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`   INT UNSIGNED NOT NULL,
    `el_callsign` VARCHAR(12)  NOT NULL COMMENT 'e.g. W4BWW-L',
    `el_number`   VARCHAR(10)  NOT NULL,
    `label`       VARCHAR(60)  NULL,
    PRIMARY KEY (`id`),
    KEY `fk_el_sys` (`system_id`),
    CONSTRAINT `fk_el_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYS DMR ──────────────────────────────────────────────────
CREATE TABLE `sys_dmr` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`     INT UNSIGNED NOT NULL,
    `color_code`    TINYINT      NOT NULL DEFAULT 1,
    `network`       VARCHAR(40)  NULL COMMENT 'BrandMeister, DMR-MARC, etc',
    `repeater_id`   VARCHAR(10)  NULL,
    `master_server` VARCHAR(80)  NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dmr_sys` (`system_id`),
    CONSTRAINT `fk_dmr_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DMR TALKGROUPS ───────────────────────────────────────────
CREATE TABLE `dmr_talkgroups` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sys_dmr_id`  INT UNSIGNED NOT NULL,
    `tg_number`   INT UNSIGNED NOT NULL,
    `tg_name`     VARCHAR(60)  NULL,
    `timeslot`    TINYINT      NOT NULL DEFAULT 1,
    `is_static`   TINYINT(1)   NOT NULL DEFAULT 1,
    `network`     VARCHAR(40)  NULL,
    PRIMARY KEY (`id`),
    KEY `fk_tg_dmr` (`sys_dmr_id`),
    CONSTRAINT `fk_tg_dmr` FOREIGN KEY (`sys_dmr_id`) REFERENCES `sys_dmr` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SERA RECORDS ─────────────────────────────────────────────
CREATE TABLE `sera_records` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`       INT UNSIGNED NOT NULL,
    `sera_id`         VARCHAR(20)  NOT NULL,
    `sera_guid`       VARCHAR(40)  NULL,
    `coordinated_at`  DATE         NULL,
    `trustee_call`    VARCHAR(12)  NULL,
    `alt_contact`     VARCHAR(80)  NULL COMMENT 'Name callsign email phone',
    `notes`           TEXT         NULL,
    PRIMARY KEY (`id`),
    KEY `fk_sera_sys` (`system_id`),
    CONSTRAINT `fk_sera_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SITE RF INVENTORY ────────────────────────────────────────
-- All RF sources at a site for intermod calculation
CREATE TABLE `site_rf_inventory` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`     INT UNSIGNED NOT NULL,
    `callsign`    VARCHAR(12)  NULL,
    `freq_mhz`    DECIMAL(9,4) NOT NULL,
    `power_watts` SMALLINT     NULL,
    `ant_gain_db` DECIMAL(4,1) NULL,
    `description` VARCHAR(120) NULL,
    `is_ttn`      TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `fk_rfi_site` (`site_id`),
    CONSTRAINT `fk_rfi_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SITE TELEMETRY ───────────────────────────────────────────
-- IoT sensor readings per site
CREATE TABLE `site_telemetry` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `recorded_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `battery_v`       DECIMAL(5,2)    NULL,
    `solar_w`         DECIMAL(7,2)    NULL,
    `load_a`          DECIMAL(5,2)    NULL,
    `temp_f`          DECIMAL(5,1)    NULL,
    `humidity_pct`    DECIMAL(5,1)    NULL,
    `camera_url`      VARCHAR(255)    NULL,
    `weather_station` VARCHAR(60)     NULL,
    `raw_json`        TEXT            NULL,
    PRIMARY KEY (`id`),
    KEY `fk_telem_site` (`site_id`),
    KEY `idx_telem_time` (`recorded_at`),
    CONSTRAINT `fk_telem_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SYS TELEMETRY ────────────────────────────────────────────
-- Per-system live status
CREATE TABLE `sys_telemetry` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`       INT UNSIGNED NOT NULL,
    `recorded_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_online`       TINYINT(1)   NOT NULL DEFAULT 0,
    `last_keyed_at`   DATETIME     NULL,
    `connected_nodes` SMALLINT     NULL,
    `recording_url`   VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `fk_stelem_sys` (`system_id`),
    CONSTRAINT `fk_stelem_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── BUILD LOG ────────────────────────────────────────────────
CREATE TABLE `buildlog` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`     INT UNSIGNED NULL,
    `operator_id` INT UNSIGNED NOT NULL,
    `entry_date`  DATE         NOT NULL,
    `entry_type`  VARCHAR(30)  NOT NULL DEFAULT 'update'
                  COMMENT 'update, install, repair, survey, planning, milestone, other',
    `title`       VARCHAR(200) NOT NULL,
    `body`        TEXT         NULL,
    `is_public`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_bl_site` (`site_id`),
    KEY `fk_bl_op`   (`operator_id`),
    KEY `idx_bl_date` (`entry_date`),
    CONSTRAINT `fk_bl_site` FOREIGN KEY (`site_id`)     REFERENCES `sites` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_bl_op`   FOREIGN KEY (`operator_id`) REFERENCES `operators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ASSETS ───────────────────────────────────────────────────
CREATE TABLE `assets` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED NULL,
    `category`       VARCHAR(40)  NOT NULL DEFAULT 'misc'
                     COMMENT 'radio, antenna, feedline, power, tower, computer, test_equipment, misc',
    `make`           VARCHAR(60)  NULL,
    `model`          VARCHAR(60)  NULL,
    `serial_number`  VARCHAR(60)  NULL,
    `description`    VARCHAR(200) NOT NULL DEFAULT '',
    `condition`      VARCHAR(20)  NOT NULL DEFAULT 'good'
                     COMMENT 'new, good, fair, needs_repair, retired',
    `location_note`  VARCHAR(120) NULL,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`          TEXT         NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_asset_site` (`site_id`),
    CONSTRAINT `fk_asset_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ROADMAP ITEMS ────────────────────────────────────────────
CREATE TABLE `roadmap_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phase`       TINYINT      NOT NULL DEFAULT 1,
    `sort_order`  TINYINT      NOT NULL DEFAULT 0,
    `title`       VARCHAR(200) NOT NULL,
    `description` TEXT         NULL,
    `item_type`   VARCHAR(20)  NOT NULL DEFAULT 'milestone'
                  COMMENT 'milestone, task, goal, note',
    `status`      VARCHAR(20)  NOT NULL DEFAULT 'planned'
                  COMMENT 'planned, in_progress, done, cancelled',
    PRIMARY KEY (`id`),
    KEY `idx_rm_phase` (`phase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PAGES ────────────────────────────────────────────────────
CREATE TABLE `pages` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `slug`         VARCHAR(80)   NOT NULL,
    `title`        VARCHAR(200)  NOT NULL,
    `nav_label`    VARCHAR(60)   NULL,
    `nav_group`    VARCHAR(40)   NULL COMMENT 'about, network, operators',
    `body`         LONGTEXT      NULL,
    `is_published` TINYINT(1)    NOT NULL DEFAULT 0,
    `sort_order`   TINYINT       NOT NULL DEFAULT 0,
    `updated_by`   INT UNSIGNED  NULL,
    `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_published` (`is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SITE SETTINGS ────────────────────────────────────────────
CREATE TABLE `site_settings` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(60)   NOT NULL
                  COMMENT 'Only lowercase letters, numbers, underscores',
    `setting_val` TEXT          NOT NULL DEFAULT '',
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── NETWORK SUBNETS ──────────────────────────────────────────
CREATE TABLE `network_subnets` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED NOT NULL,
    `subnet`         VARCHAR(18)  NOT NULL,
    `cidr`           TINYINT      NOT NULL DEFAULT 24,
    `network_type`   ENUM('lan','vlan','aredn','tunnel','iot','cameras','management','other') NOT NULL DEFAULT 'lan',
    `label`          VARCHAR(80)  NOT NULL,
    `vlan_id`        SMALLINT     NULL,
    `gateway`        VARCHAR(15)  NULL,
    `is_ttn_managed` TINYINT(1)   NOT NULL DEFAULT 0,
    `notes`          TEXT         NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_subnet_site` (`site_id`),
    CONSTRAINT `fk_subnet_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── NETWORK DEVICES ──────────────────────────────────────────
CREATE TABLE `network_devices` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED NOT NULL,
    `subnet_id`      INT UNSIGNED NULL,
    `hostname`       VARCHAR(80)  NOT NULL,
    `display_name`   VARCHAR(120) NULL,
    `make`           VARCHAR(60)  NULL,
    `model`          VARCHAR(80)  NULL,
    `device_type`    ENUM(
                         'firewall','switch','access_point',
                         'server','virtual_machine',
                         'allstar_server','aredn_node',
                         'dns_server','ldap_server','chat_server',
                         'camera','camera_server','camera_hub',
                         'ntp_clock','sdr_radio','dmr_server',
                         'aprs','scanner','controller','other'
                     ) NOT NULL DEFAULT 'other',
    `is_physical`    TINYINT(1)   NOT NULL DEFAULT 1,
    `parent_host_id` INT UNSIGNED NULL COMMENT 'For VMs — points to physical host',
    `function`       VARCHAR(120) NULL,
    `os`             VARCHAR(60)  NULL,
    `public_ip`      VARCHAR(45)  NULL,
    `ip_address`     VARCHAR(45)  NULL,
    `mac_address`    VARCHAR(17)  NULL,
    `port_speed`     VARCHAR(20)  NULL,
    `web_ports`      VARCHAR(80)  NULL,
    `asl_nodes_json` TEXT         NULL COMMENT 'JSON array of ASL node numbers',
    `asl_port`       SMALLINT     NULL,
    `is_ttn_managed` TINYINT(1)   NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`          TEXT         NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hostname` (`hostname`),
    KEY `fk_dev_site`   (`site_id`),
    KEY `fk_dev_subnet` (`subnet_id`),
    KEY `fk_dev_parent` (`parent_host_id`),
    CONSTRAINT `fk_dev_site`   FOREIGN KEY (`site_id`)   REFERENCES `sites` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dev_subnet` FOREIGN KEY (`subnet_id`) REFERENCES `network_subnets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Schema created successfully.' AS result;
