<?php
/**
 * 应用引导：配置加载 / 错误处理 / 依赖装载
 */
declare(strict_types=1);

define('MC_ROOT', dirname(__DIR__));
define('MC_PUBLIC', MC_ROOT . '/public');
define('MC_START', microtime(true));

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@mkdir(MC_ROOT . '/storage/logs', 0755, true);
@mkdir(MC_ROOT . '/storage/cache', 0755, true);
ini_set('error_log', MC_ROOT . '/storage/logs/php-error.log');

// mbstring 兜底：部分面板（含宝塔 PHP 8.2）未安装 mbstring 时，用单字节实现替代，避免致命错误
if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding($e = null) { return 'UTF-8'; }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int { return strlen($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null, ?string $enc = null): string { return $len === null ? substr($s, $start) : substr($s, $start, $len); }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s, ?string $enc = null): string { return strtoupper($s); }
}
if (!function_exists('mb_split')) {
    function mb_split(string $pattern, string $string): array { return preg_split($pattern, $string) ?: []; }
}
mb_internal_encoding('UTF-8');

/** 读取配置（app/config.php，由安装向导生成） */
function mc_config(?string $key = null, $default = null)
{
    static $cfg = null;
    static $mtime = -1;
    $file = MC_ROOT . '/app/config.php';
    $mt = file_exists($file) ? (int)filemtime($file) : 0;
    // 安装向导写入配置后自动重新加载
    if ($cfg === null || $mt !== $mtime) {
        $loaded = file_exists($file) ? require $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
        $mtime = $mt;
    }
    if ($key === null) return $cfg;
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function mc_configured(): bool
{
    return file_exists(MC_ROOT . '/app/config.php');
}

date_default_timezone_set((string)mc_config('timezone', 'Asia/Shanghai'));

/** 业务异常：直接输出指定 HTTP 状态的 JSON */
class ApiError extends RuntimeException
{
    public string $error;
    public int $httpStatus;

    public function __construct(string $error, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->error = $error;
        $this->httpStatus = $httpStatus;
    }
}

set_exception_handler(function (Throwable $e) {
    $isApi = str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    $message = $e->getMessage();
    if ($e instanceof ApiError) {
        if ($isApi) json_out(['error' => $e->error, 'message' => $message], $e->httpStatus);
        http_response_code($e->httpStatus);
        echo '<h1>' . htmlspecialchars($message) . '</h1>';
        exit;
    }
    mc_log('error', $message, ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if ($isApi) {
        json_out(['error' => 'server_error', 'message' => mc_config('debug', false) ? $message : '服务异常，请稍后再试。'], 500);
    }
    http_response_code(500);
    echo '<h1>服务异常</h1><p>' . htmlspecialchars(mc_config('debug', false) ? $message : '请稍后再试') . '</p>';
    exit;
});

require_once MC_ROOT . '/app/lib/util.php';
require_once MC_ROOT . '/app/lib/crypto.php';
require_once MC_ROOT . '/app/lib/db.php';
require_once MC_ROOT . '/app/schema.php';
require_once MC_ROOT . '/app/lib/settings.php';
require_once MC_ROOT . '/app/lib/notify.php';
require_once MC_ROOT . '/app/lib/auth.php';
require_once MC_ROOT . '/app/lib/qr.php';
