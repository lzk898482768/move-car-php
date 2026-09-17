<?php
/**
 * 统一入口：页面路由 + API 分发
 * Nginx/Apache 请把所有请求重写到本文件（见 docs/）
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once MC_ROOT . '/app/api.php';
require_once MC_ROOT . '/app/api_admin.php';
require_once MC_ROOT . '/app/router.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// 支持部署在二级目录：去掉脚本所在目录前缀
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
$scriptDir = rtrim($scriptDir, '/');
if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir)) ?: '/';
}
if ($path === '') $path = '/';

// 内置服务器（php -S）下静态文件直接返回
if (PHP_SAPI === 'cli-server') {
    $file = realpath(__DIR__ . $path);
    if ($path !== '/' && $file && is_file($file) && str_starts_with($file, __DIR__)) {
        return false;
    }
}

// 跨域预检
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

// 未安装 → 引导安装
if (!mc_configured()) {
    if (str_starts_with($path, '/api/')) json_error('not_installed', '系统尚未安装，请访问 /install.php 完成安装。', 503);
    header('Location: ' . $scriptDir . '/install.php');
    exit;
}

// API
if (str_starts_with($path, '/api/')) {
    route_api($method, $path);
    exit;
}

// 页面
$pages = [
    '/' => 'index.html',
    '/index' => 'index.html',
    '/move' => 'move.html',
    '/owner' => 'owner.html',
    '/admin' => 'admin.html',
];
if (isset($pages[$path])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(MC_PUBLIC . '/' . $pages[$path]);
    exit;
}

// 兼容直接访问 /xxx.html
$candidate = MC_PUBLIC . $path;
if (is_file($candidate) && str_ends_with($path, '.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($candidate);
    exit;
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>404</title><h1>页面不存在</h1><p><a href="' . htmlspecialchars($scriptDir . '/') . '">返回首页</a></p>';
