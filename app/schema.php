<?php
/**
 * 数据库结构（MySQL 优先，SQLite 兼容）
 * —— 车牌以完整明文存储（便于检索与展示），手机号/Webhook/密钥等敏感值加密存储
 */
declare(strict_types=1);

const MC_SCHEMA_VERSION = '1.0.0';

function schema_tables(): array
{
    return [
        'vehicles' => "CREATE TABLE IF NOT EXISTS vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_token VARCHAR(64) NOT NULL UNIQUE,
            owner_token VARCHAR(64) NOT NULL UNIQUE,
            plate_number VARCHAR(32) NOT NULL,
            plate_number_hash CHAR(64) NOT NULL UNIQUE,
            owner_phone_enc TEXT NULL,
            wechat_work_webhook_enc TEXT NULL,
            wechat_work_enabled TINYINT(1) NOT NULL DEFAULT 0,
            wechat_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
            privacy_call_enabled TINYINT(1) NOT NULL DEFAULT 0,
            owner_pin_hash VARCHAR(128) NULL,
            wechat_openid_enc TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'qr_codes' => "CREATE TABLE IF NOT EXISTS qr_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code_token VARCHAR(64) NOT NULL UNIQUE,
            batch_no VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'unbound',
            vehicle_id INT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            bound_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'global_config' => "CREATE TABLE IF NOT EXISTS global_config (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NULL,
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'admins' => "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(128) NOT NULL,
            salt VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'admin_sessions' => "CREATE TABLE IF NOT EXISTS admin_sessions (
            token VARCHAR(128) PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'notification_logs' => "CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NULL,
            channel VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            error_summary VARCHAR(512) NOT NULL DEFAULT '',
            visitor_ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'call_logs' => "CREATE TABLE IF NOT EXISTS call_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NULL,
            channel VARCHAR(32) NOT NULL DEFAULT '',
            caller_number_enc TEXT NULL,
            caller_last4 VARCHAR(8) NOT NULL DEFAULT '',
            callee_number_enc TEXT NULL,
            callee_last4 VARCHAR(8) NOT NULL DEFAULT '',
            virtual_number_enc TEXT NULL,
            virtual_last4 VARCHAR(8) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'success',
            error_summary VARCHAR(512) NOT NULL DEFAULT '',
            visitor_ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'phone_verify_codes' => "CREATE TABLE IF NOT EXISTS phone_verify_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            phone_hash VARCHAR(64) NOT NULL DEFAULT '',
            code_hash VARCHAR(128) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            consumed_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'ads' => "CREATE TABLE IF NOT EXISTS ads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            position VARCHAR(32) NOT NULL,
            title VARCHAR(128) NOT NULL DEFAULT '',
            image_url VARCHAR(512) NOT NULL,
            link_url VARCHAR(512) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'rate_limits' => "CREATE TABLE IF NOT EXISTS rate_limits (
            k VARCHAR(128) PRIMARY KEY,
            hits INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'schema_meta' => "CREATE TABLE IF NOT EXISTS schema_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function schema_indexes(): array
{
    return [
        'CREATE INDEX idx_vehicles_created ON vehicles(created_at)',
        'CREATE INDEX idx_vehicles_plate ON vehicles(plate_number)',
        'CREATE INDEX idx_qr_status ON qr_codes(status)',
        'CREATE INDEX idx_qr_batch ON qr_codes(batch_no)',
        'CREATE INDEX idx_qr_vehicle ON qr_codes(vehicle_id)',
        'CREATE INDEX idx_notify_vehicle ON notification_logs(vehicle_id)',
        'CREATE INDEX idx_notify_created ON notification_logs(created_at)',
        'CREATE INDEX idx_notify_visitor ON notification_logs(visitor_ip_hash, created_at)',
        'CREATE INDEX idx_call_vehicle ON call_logs(vehicle_id)',
        'CREATE INDEX idx_call_created ON call_logs(created_at)',
        'CREATE INDEX idx_call_channel ON call_logs(channel)',
        'CREATE INDEX idx_call_status ON call_logs(status)',
        'CREATE INDEX idx_call_last4 ON call_logs(caller_last4, callee_last4, virtual_last4)',
        'CREATE INDEX idx_pvc_vehicle ON phone_verify_codes(vehicle_id)',
        'CREATE INDEX idx_ads_position ON ads(position)',
    ];
}

/** MySQL DDL → SQLite */
function schema_translate(string $sql, string $driver): string
{
    if ($driver !== 'sqlite') return $sql;
    $sql = str_ireplace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = str_ireplace('DATETIME', 'TEXT', $sql);
    $sql = str_ireplace('TINYINT(1)', 'INTEGER', $sql);
    $sql = str_ireplace('CHAR(64)', 'TEXT', $sql);
    // 一次性干掉 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 整段（含 COLLATE / COMMENT 等尾巴）
    $sql = preg_replace('/\s*ENGINE\s*=[\s\S]*$/i', '', $sql) ?? $sql;
    // MySQL 的反引号转义在 SQLite 也可用，但去掉更保险
    $sql = str_replace('`', '', $sql);
    return trim($sql);
}

/** 建表 + 建索引（幂等，已存在则跳过） */
function schema_install(?PDO $pdo = null): void
{
    $pdo = $pdo ?? DB::pdo();
    $driver = DB::driver();
    foreach (schema_tables() as $name => $ddl) {
        $pdo->exec(schema_translate($ddl, $driver));
    }
    foreach (schema_indexes() as $idx) {
        $sql = $idx;
        // MySQL 不支持 IF NOT EXISTS；SQLite 加上以避免重复安装报错
        if ($driver === 'sqlite') {
            $sql = preg_replace('/^CREATE INDEX /i', 'CREATE INDEX IF NOT EXISTS ', $sql) ?? $sql;
        }
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 索引已存在（MySQL）→ 忽略
        }
    }
    $now = db_now();
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('INSERT INTO schema_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    } else {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO schema_meta (k, v) VALUES (?, ?)');
    }
    $stmt->execute(['version', MC_SCHEMA_VERSION]);
    $stmt->execute(['installed_at', $now]);
}

function schema_version(): string
{
    try {
        return (string)DB::val('SELECT v FROM schema_meta WHERE k = ?', ['version'], '');
    } catch (Throwable $e) {
        return '';
    }
}

function schema_ready(): bool
{
    try {
        return DB::val("SELECT COUNT(*) FROM vehicles", [], 0) !== null;
    } catch (Throwable $e) {
        return false;
    }
}
