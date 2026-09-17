<?php
/**
 * 二维码图片：/qr.php?text=xxx&size=240
 * 默认输出 SVG（纯 PHP 编码，不依赖 GD 扩展，宝塔 PHP 8.2 无 GD 也能用）
 * 可选 &format=png 输出 PNG（需 GD；无 GD 时返回 500 并提示改用 SVG）
 * 可选 &download=1 作为附件下载
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$text = trim((string)($_GET['text'] ?? ''));
$size = (int)($_GET['size'] ?? 240);
$size = max(80, min(1200, $size));
$format = strtolower((string)($_GET['format'] ?? 'svg'));
$download = ((string)($_GET['download'] ?? '')) !== '';

if ($text === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缺少 text 参数';
    exit;
}
if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);

if ($format === 'png') {
    if (!qr_png($text, $size)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '当前 PHP 未安装 GD 扩展，无法输出 PNG。请去掉 &format=png 使用默认 SVG 格式（矢量、更清晰）';
    }
    exit;
}

try {
    $svg = qr_svg($text, $size);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '二维码生成失败：' . $e->getMessage();
    exit;
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');
if ($download) {
    header('Content-Disposition: attachment; filename="qrcode.svg"');
}
echo $svg;
