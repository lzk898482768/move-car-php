<?php
/**
 * 预生成二维码：码串生成 + 本地 PNG 输出（不再依赖外部二维码服务）
 */
declare(strict_types=1);

function new_code_token(): string
{
    return 'qr_' . bin2hex(random_bytes(10));
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $scriptDir = rtrim($scriptDir, '/.');
    return $scheme . '://' . $host . $scriptDir;
}

/** 二维码扫码地址（与前端 buildQrUrl 保持一致） */
function build_qr_url(string $codeToken): string
{
    return base_url() . '/move.html?c=' . rawurlencode($codeToken);
}

/** 直接输出二维码 PNG */
function qr_png(string $text, int $size = 240): void
{
    $pixel = max(2, min(20, (int)round($size / 33)));
    require_once MC_ROOT . '/app/lib/phpqrcode.php';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    QRcode::png($text, false, QR_ECLEVEL_M, $pixel, 2);
    exit;
}
