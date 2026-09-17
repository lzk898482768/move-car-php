<?php
/**
 * 通用工具函数：请求 / 响应 / 校验 / 时间 / 限流 / CSV
 */
declare(strict_types=1);

/* ---------------- 响应 ---------------- */

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $error, string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['error' => $error, 'message' => $message], $extra), $status);
}

function json_message(string $message, array $extra = [], int $status = 200): void
{
    json_out(array_merge(['message' => $message], $extra), $status);
}

/* ---------------- 请求 ---------------- */

function request_json(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // 兼容表单提交
        $data = $_POST ?: [];
    }
    return $cache = $data;
}

function input_get(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function input_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? null;
    return $v === null ? $default : trim((string)$v);
}

function body_get(string $key, $default = null)
{
    $d = request_json();
    return array_key_exists($key, $d) ? $d[$key] : $default;
}

function body_str(string $key, string $default = ''): string
{
    $v = body_get($key);
    return $v === null || $v === false ? $default : trim((string)$v);
}

function body_bool(string $key, ?bool $default = null): ?bool
{
    $v = body_get($key);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return ((float)$v) > 0;
    $s = strtolower(trim((string)$v));
    return !in_array($s, ['false', '0', 'off', 'no', ''], true);
}

/* ---------------- 校验 ---------------- */

function is_plate(string $v): bool
{
    return (bool)preg_match('/^[\x{4e00}-\x{9fa5}A-Z0-9]{5,10}$/u', trim($v));
}

function is_phone(string $v): bool
{
    return (bool)preg_match('/^\+?\d[\d\s-]{6,19}$/', trim($v));
}

function is_pin(string $v): bool
{
    return (bool)preg_match('/^\d{4,12}$/', trim($v));
}

function is_http_url(string $v): bool
{
    $v = trim($v);
    if ($v === '') return false;
    return (bool)preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL) !== false;
}

function normalize_plate(string $v): string
{
    $v = preg_replace('/\s+/u', '', trim($v)) ?? '';
    return mb_strtoupper($v, 'UTF-8');
}

function normalize_phone(string $v): string
{
    return preg_replace('/[\s-]/', '', trim($v)) ?? '';
}

function mask_phone(string $p): string
{
    if (mb_strlen($p) <= 5) return '****';
    return mb_substr($p, 0, 3) . '****' . mb_substr($p, -2);
}

/** 手机号转 E.164（+86 前缀） */
function to_e164(string $phone, string $cc = '+86'): string
{
    $p = normalize_phone($phone);
    if ($p === '') return '';
    if (str_starts_with($p, '+')) return $p;
    $cc = $cc ?: '+86';
    $digits = ltrim($cc, '+');
    if (str_starts_with($p, $digits)) return '+' . $p;
    return $cc . ltrim($p, '0');
}

function last4(string $v): string
{
    $v = preg_replace('/\D/', '', $v) ?? '';
    return $v === '' ? '' : substr($v, -4);
}

/* ---------------- 时间 ---------------- */

function db_now(): string
{
    return date('Y-m-d H:i:s');
}

/** 数据库时间 → ISO8601（前端按本地时区解析） */
function iso_out(?string $t): string
{
    if (!$t) return '';
    $t = str_replace('T', ' ', $t);
    $t = preg_replace('/([+-]\d{2}:?\d{2}|Z)$/', '', $t) ?? $t;
    $t = trim($t);
    if ($t === '') return '';
    return str_replace(' ', 'T', $t) . date('P');
}

/** 筛选参数（YYYY-MM-DD 或 ISO）→ 数据库时间 */
function to_db_time(string $v, bool $endOfDay = false): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $endOfDay ? $v . ' 23:59:59' : $v . ' 00:00:00';
    }
    $t = str_replace('T', ' ', $v);
    $t = preg_replace('/([+-]\d{2}:?\d{2}|Z)$/', '', $t) ?? $t;
    return substr(trim($t), 0, 19);
}

/* ---------------- 随机 / 安全 ---------------- */

function new_token(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(16));
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim(explode(',', (string)$_SERVER[$k])[0]);
            if ($v !== '') return $v;
        }
    }
    return '0.0.0.0';
}

function visitor_ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . mc_config('ip_salt', 'move-car'));
}

function str_slice(string $v, int $len = 300): string
{
    return mb_substr($v, 0, $len);
}

/* ---------------- 限流（数据库计数） ---------------- */

function rate_limit_check(string $key, int $max, int $windowSeconds): bool
{
    try {
        $now = time();
        $row = DB::one('SELECT hits, expires_at FROM rate_limits WHERE k = ?', [$key]);
        if ($row && strtotime((string)$row['expires_at']) > $now) {
            if ((int)$row['hits'] >= $max) return false;
            DB::exec('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$key]);
            return true;
        }
        // 计数窗口过期：重置（MySQL / SQLite 通用写法）
        DB::exec('DELETE FROM rate_limits WHERE k = ?', [$key]);
        DB::exec('INSERT INTO rate_limits (k, hits, expires_at) VALUES (?, 1, ?)', [$key, date('Y-m-d H:i:s', $now + $windowSeconds)]);
        return true;
    } catch (Throwable $e) {
        return true; // 限流表异常不阻断业务
    }
}

/* ---------------- CSV ---------------- */

function csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* ---------------- HTTP 客户端 ---------------- */

function http_json(string $method, string $url, array $body = [], array $headers = [], int $timeout = 8): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $headers = array_merge(['Accept: application/json'], $headers);
    if ($body !== [] || in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $payload;
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('HTTP 请求失败：' . $err);
    $data = json_decode((string)$resp, true);
    return [$code, is_array($data) ? $data : ['raw' => (string)$resp]];
}

/** 通用 POST（表单/自定义 Content-Type） */
function http_post_raw(string $url, string $payload, array $headers = [], int $timeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('HTTP 请求失败：' . $err);
    return [$code, (string)$resp];
}

/* ---------------- 日志 ---------------- */

function mc_log(string $level, string $message, array $ctx = []): void
{
    $dir = MC_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = date('Y-m-d H:i:s') . " [{$level}] " . $message;
    if ($ctx) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/app-' . date('Ym') . '.log', $line . PHP_EOL, FILE_APPEND);
}
