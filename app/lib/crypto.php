<?php
/**
 * 字段级加密：AES-256-GCM（手机号、Webhook、OpenID、云厂商密钥等敏感值）
 * 密文格式：base64( iv[12] + tag[16] + ciphertext )
 */
declare(strict_types=1);

function crypto_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $raw = (string)mc_config('encryption_key', '');
    if ($raw === '') throw new RuntimeException('未配置加密密钥，请先完成安装。');
    $bin = strlen($raw) === 44 && str_ends_with($raw, '=') ? base64_decode($raw, true) : $raw;
    if (!$bin || strlen($bin) < 16) $bin = hash('sha256', $raw, true);
    if (strlen($bin) !== 32) $bin = hash('sha256', $bin, true);
    return $key = $bin;
}

function encrypt_text(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('加密失败');
    return base64_encode($iv . $tag . $ct);
}

function decrypt_text(string $packed): string
{
    if ($packed === '') return '';
    $raw = base64_decode($packed, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $out = openssl_decrypt($ct, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? '' : $out;
}

function sha256_hex(string $v): string
{
    return hash('sha256', $v);
}
