<?php
/**
 * TTN Database Layer
 * Tennessee Technological Community · ttn.radio
 *
 * LOCATION: /home/obdswlpx/dev.ttn.radio/includes/db.php
 *
 * Provides:
 *   ttn_db()        — PDO singleton
 *   db_row()        — fetch one row
 *   db_rows()       — fetch multiple rows
 *   db_execute()    — INSERT/UPDATE/DELETE
 *   db_insert()     — INSERT with column array
 *   db_count()      — COUNT query
 *   db_transaction()— wrap in transaction
 *   s()             — get site_setting by key
 *   settings()      — load all settings into array
 */

require_once '/home/obdswlpx/ttn_config.php';

// ── PDO SINGLETON ─────────────────────────────────────────────
function ttn_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);
    }
    return $pdo;
}

// ── QUERY HELPERS ─────────────────────────────────────────────
function db_row(string $sql, array $params = []): ?array {
    $stmt = ttn_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_rows(string $sql, array $params = []): array {
    $stmt = ttn_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_execute(string $sql, array $params = []): int {
    $stmt = ttn_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $table, array $data): int {
    $cols  = array_keys($data);
    $vals  = array_values($data);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $col_list = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $sql = "INSERT INTO `$table` ($col_list) VALUES ($placeholders)";
    $stmt = ttn_db()->prepare($sql);
    $stmt->execute($vals);
    return (int)ttn_db()->lastInsertId();
}

function db_count(string $sql, array $params = []): int {
    $stmt = ttn_db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function db_transaction(callable $fn): mixed {
    $db = ttn_db();
    $db->beginTransaction();
    try {
        $result = $fn($db);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── SITE SETTINGS ─────────────────────────────────────────────
// Cache all settings in a static array — one DB hit per request
function settings(): array {
    static $cache = null;
    if ($cache === null) {
        $rows  = db_rows("SELECT setting_key, setting_val FROM site_settings");
        $cache = [];
        foreach ($rows as $r) {
            $cache[$r['setting_key']] = $r['setting_val'];
        }
    }
    return $cache;
}

// Get a single setting by key with optional default
function s(string $key, string $default = ''): string {
    $all = settings();
    return $all[$key] ?? $default;
}
