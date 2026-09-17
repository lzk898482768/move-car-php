<?php
/**
 * 二维码图片：/qr.php?text=xxx&size=240 （本地生成，不依赖外部服务）
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$text = trim((string)($_GET['text'] ?? ''));
$size = (int)($_GET['size'] ?? 240);
if ($text === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缺少 text 参数';
    exit;
}
if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);
qr_png($text, $size);
