<?php
/**
 * 配置示例：复制为 app/config.php 后修改，或直接访问 /install.php 由向导生成
 */
return [
    // 数据库：mysql（推荐）或 sqlite（零配置）
    'db' => [
        'driver' => 'mysql',           // mysql | sqlite
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'move_car',
        'user' => 'move_car',
        'pass' => '请填写数据库密码',
        'charset' => 'utf8mb4',
        // driver = sqlite 时生效（需保证 storage 目录可写）
        'path' => __DIR__ . '/../storage/database.sqlite',
    ],

    // 字段加密密钥（手机号 / Webhook / 云厂商密钥）。安装向导自动生成 base64(32字节)
    'encryption_key' => '',

    // 访客 IP 哈希盐（限流用）
    'ip_salt' => '',

    'timezone' => 'Asia/Shanghai',
    'debug' => false,
];
