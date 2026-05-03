-- TTN Connection Log Table
-- Run in phpMyAdmin

CREATE TABLE IF NOT EXISTS `conn_log` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `system_id`      INT UNSIGNED NOT NULL,
    `connected_node` VARCHAR(10)  NOT NULL,
    `callsign`       VARCHAR(20)  NOT NULL DEFAULT '',
    `location`       VARCHAR(120) NOT NULL DEFAULT '',
    `direction`      VARCHAR(10)  NOT NULL DEFAULT '',
    `connected_at`   DATETIME     NOT NULL,
    `disconnected_at`DATETIME     NULL,
    PRIMARY KEY (`id`),
    KEY `fk_cl_sys`   (`system_id`),
    KEY `idx_cl_node` (`connected_node`),
    KEY `idx_cl_time` (`connected_at`),
    CONSTRAINT `fk_cl_sys` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add telemetry_secret to site_settings
-- Generate a random secret and put it here — must match /etc/ttn-logger.conf on node servers
INSERT INTO `site_settings` (`setting_key`, `setting_val`)
VALUES ('telemetry_secret', 'CHANGE_ME_RANDOM_SECRET_HERE')
ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val);

SELECT 'conn_log table created.' AS result;
SELECT setting_key, setting_val FROM site_settings WHERE setting_key = 'telemetry_secret';
