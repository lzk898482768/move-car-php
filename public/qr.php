<?php
/**
 * 二维码图片：/qr.php?text=xxx&size=240
 * 默认输出 SVG（纯 PHP 编码，不依赖 GD 扩展，宝塔 PHP 8.2 无 GD 也能用）
 * 可选 &format=png 输出 PNG（需 GD；无 GD 时返回 500 并提示改用 SVG）
 * 可选 &download=1 作为附件下载
 */
declare(strict_types=1);

// 防 zlib/gzip 把 SVG 截断成二进制被浏览器当成损坏图片
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');

// 吞掉 bootstrap 阶段可能输出的 BOM / Deprecated 警告，保证最终响应纯净
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();
require_once __DIR__ . '/../app/bootstrap.php';

$text = trim((string)($_GET['text'] ?? ''));
$size = (int)($_GET['size'] ?? 240);
$size = max(80, min(1200, $size));
$format = strtolower((string)($_GET['format'] ?? 'svg'));
$download = ((string)($_GET['download'] ?? '')) !== '';

// 输入校验 —— 现在还没发 header，可以重置缓冲区
ob_end_clean();
ob_start();

if ($text === '') {
    @ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '缺少 text 参数';
    exit;
}
if (function_exists('mb_strlen') && mb_strlen($text) > 500) {
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500);
}

// 释放当前 buffer 之前必须确保下方不再写出警告字符
@ob_end_clean();

if ($format === 'png') {
    // qr_png() 内部自己发送 header + PNG 字节流
    if (qr_png($text, $size)) exit;
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '当前 PHP 未安装 GD 扩展，无法输出 PNG。请去掉 &format=png 使用默认 SVG 格式（矢量、更清晰）';
    exit;
}

try {
    $svg = qr_svg($text, $size);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '二维码生成失败：' . $e->getMessage();
    exit;
}

// 严防前置字节（BOM / 空白 / 警告）
$svg = ltrim($svg, "\xEF\xBB\xBF \t\r\n");

header('Content-Type: image/svg+xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($svg));
if ($download) {
    header('Content-Disposition: attachment; filename="qrcode.svg"');
}
echo $svg;
