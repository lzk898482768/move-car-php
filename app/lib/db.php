<?php
/**
 * PDO 数据库封装（MySQL / SQLite 双引擎）
 */
declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $cfg = mc_config('db', []);
        $driver = strtolower((string)($cfg['driver'] ?? 'mysql'));
        self::$driver = in_array($driver, ['mysql', 'sqlite'], true) ? $driver : 'mysql';

        if (self::$driver === 'sqlite') {
            $path = (string)($cfg['path'] ?? (MC_ROOT . '/storage/database.sqlite'));
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
        } else {
            $host = (string)($cfg['host'] ?? '127.0.0.1');
            $port = (int)($cfg['port'] ?? 3306);
            $name = (string)($cfg['name'] ?? 'move_car');
            $user = (string)($cfg['user'] ?? 'root');
            $pass = (string)($cfg['pass'] ?? '');
            $charset = (string)($cfg['charset'] ?? 'utf8mb4');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        self::connect();
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        return self::connect();
    }

    /** @return PDOStatement */
    public static function q(string $sql, array $binds = [])
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute(array_values($binds));
        return $stmt;
    }

    public static function all(string $sql, array $binds = []): array
    {
        return self::q($sql, $binds)->fetchAll();
    }

    public static function one(string $sql, array $binds = []): ?array
    {
        $row = self::q($sql, $binds)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $binds = [], $default = null)
    {
        $row = self::q($sql, $binds)->fetch(PDO::FETCH_NUM);
        return $row === false ? $default : $row[0];
    }

    public static function exec(string $sql, array $binds = []): int
    {
        return self::q($sql, $binds)->rowCount();
    }

    public static function insert(string $sql, array $binds = []): int
    {
        self::q($sql, $binds);
        return (int)self::connect()->lastInsertId();
    }

    public static function tx(callable $fn)
    {
        $pdo = self::connect();
        $pdo->beginTransaction();
        try {
            $res = $fn();
            $pdo->commit();
            return $res;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** 占位符：IN (?,?,?) */
    public static function placeholders(int $n): string
    {
        return implode(',', array_fill(0, max($n, 1), '?'));
    }
}
