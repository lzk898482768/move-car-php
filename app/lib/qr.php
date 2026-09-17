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

/**
 * 取二维码点阵（纯 PHP 编码，不依赖 GD）
 *
 * @return string[] 每行一个字符串，字符 '1' 表示黑点
 */
function qr_matrix(string $text, int $margin = 2): array
{
    // 库加载时会输出 Deprecated 警告，必须一并吞掉，否则会污染 SVG/PNG 输出
    ob_start();
    require_once MC_ROOT . '/app/lib/phpqrcode.php';
    $enc = QRencode::factory(QR_ECLEVEL_M, 1, $margin);
    $enc->size = 1;
    $enc->margin = $margin;
    $tab = $enc->encode($text);
    ob_end_clean();
    if (!is_array($tab) || $tab === []) {
        throw new RuntimeException('二维码编码失败');
    }
    return $tab;
}

/**
 * 渲染 SVG 二维码：矢量、不依赖 GD 扩展
 * 宝塔 PHP 8.2 未安装 GD 时也能正常出码，打印/放大不会失真
 */
function qr_svg(string $text, int $size = 240, int $margin = 2): string
{
    $tab = qr_matrix($text, $margin);
    $h = count($tab);
    $w = strlen((string)$tab[0]);
    $modules = $w + 2 * $margin;
    $pixel = max(1, intdiv(max(1, $size), $modules));
    $dim = $modules * $pixel;

    $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim
        . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">';
    $svg .= '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>';

    $path = '';
    for ($i = 0; $i < $h; $i++) {
        $row = (string)$tab[$i];
        for ($j = 0; $j < $w; $j++) {
            if (($row[$j] ?? '0') !== '1') continue;
            $x = ($j + $margin) * $pixel;
            $y = ($i + $margin) * $pixel;
            $path .= 'M' . $x . ' ' . $y . 'h' . $pixel . 'v' . $pixel . 'h-' . $pixel . 'z';
        }
    }
    $svg .= '<path fill="#000000" d="' . $path . '"/></svg>';
    return $svg;
}

/** GD 扩展是否可用（宝塔部分 PHP 版本未编译 GD） */
function qr_gd_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/**
 * 直接输出二维码 PNG（需要 GD；不可用时返回 false 交给调用方降级）
 */
function qr_png(string $text, int $size = 240): bool
{
    if (!qr_gd_available()) return false;
    $pixel = max(2, min(20, (int)round($size / 33)));
    require_once MC_ROOT . '/app/lib/phpqrcode.php';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    QRcode::png($text, false, QR_ECLEVEL_M, $pixel, 2);
    return true;
}
