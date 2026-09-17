<?php
/**
 * 安装向导：生成配置 → 建库建表 → 创建管理员
 * 安装完成请删除本文件（或重命名），避免被再次访问。
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$installed = mc_configured();
$step = $_POST['step'] ?? '';
$error = '';
$done = false;

if ($installed && $step === '') {
    $done = true;
}

if ($step === 'install' && !$installed) {
    try {
        $driver = ($_POST['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $adminUser = trim((string)($_POST['admin_user'] ?? ''));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        if ($adminUser === '' || strlen($adminPass) < 8) throw new RuntimeException('管理员账号不能为空，密码至少 8 位。');

        $db = ['driver' => $driver];
        if ($driver === 'mysql') {
            $db['host'] = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
            $db['port'] = (int)($_POST['db_port'] ?: 3306);
            $db['name'] = trim((string)($_POST['db_name'] ?? 'move_car'));
            $db['user'] = trim((string)($_POST['db_user'] ?? 'root'));
            $db['pass'] = (string)($_POST['db_pass'] ?? '');
            $db['charset'] = 'utf8mb4';
            // 先连上服务器（不指定库），建库
            $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` DEFAULT CHARACTER SET utf8mb4');
        } else {
            $db['path'] = MC_ROOT . '/storage/database.sqlite';
        }

        $config = [
            'db' => $db,
            'encryption_key' => base64_encode(random_bytes(32)),
            'ip_salt' => bin2hex(random_bytes(16)),
            'timezone' => 'Asia/Shanghai',
            'debug' => false,
        ];
        file_put_contents(MC_ROOT . '/app/config.php', "<?php\n// 由安装向导生成（" . date('Y-m-d H:i:s') . "）\nreturn " . var_export($config, true) . ";\n");

        // 建表
        schema_install();
        // 创建管理员
        if (!DB::one('SELECT id FROM admins LIMIT 1')) {
            $salt = bin2hex(random_bytes(8));
            DB::exec('INSERT INTO admins (username, password_hash, salt, created_at) VALUES (?,?,?,?)', [
                $adminUser, hash('sha256', $salt . ':' . $adminPass), $salt, db_now(),
            ]);
        }
        clear_global_cache();
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        @unlink(MC_ROOT . '/app/config.php');
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>扫码挪车 · 安装向导</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Microsoft YaHei", sans-serif; background: #f0fdf4; color: #14532d; }
        .wrap { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 8px 24px rgba(22,163,74,.12); }
        h1 { margin: 0 0 6px; font-size: 22px; }
        .sub { color: #4b5563; font-size: 13px; margin-bottom: 20px; }
        label { display: block; font-size: 13px; margin: 12px 0 4px; color: #374151; }
        input, select { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; }
        button { margin-top: 20px; width: 100%; padding: 12px; border: 0; border-radius: 999px; background: #16a34a; color: #fff; font-size: 15px; cursor: pointer; }
        .err { background: #fef2f2; color: #b91c1c; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
        .ok { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 10px; font-size: 14px; }
        .hint { font-size: 12px; color: #6b7280; margin-top: 6px; }
        a { color: #16a34a; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>扫码挪车 · 安装向导</h1>
    <div class="sub">原生 PHP + MySQL/SQLite，几分钟即可完成部署</div>

    <?php if ($error): ?>
        <div class="err">安装失败：<?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
        <div class="ok">
            安装已完成 ✅<br>
            数据库：<?= h(DB::driver()) ?> · 版本 <?= h(MC_SCHEMA_VERSION) ?>
        </div>
        <p class="hint">
            下一步：<a href="./admin.html">进入超级管理后台</a>（账号为安装时填写的管理员）<br>
            安全建议：安装完成后请删除或重命名 <code>public/install.php</code>。
        </p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="step" value="install">
            <label>数据库类型</label>
            <select name="driver" id="driver" onchange="toggleDb(this.value)">
                <option value="mysql">MySQL（推荐，宝塔面板默认）</option>
                <option value="sqlite">SQLite（零配置，适合小规模自用）</option>
            </select>

            <div id="mysql-fields">
                <label>数据库主机</label>
                <input name="db_host" value="127.0.0.1" placeholder="127.0.0.1">
                <label>端口</label>
                <input name="db_port" value="3306">
                <label>数据库名</label>
                <input name="db_name" value="move_car">
                <label>数据库用户名</label>
                <input name="db_user" value="root">
                <label>数据库密码</label>
                <input name="db_pass" type="password" placeholder="宝塔面板 → 数据库 可查看">
                <div class="hint">数据库不存在时会自动创建。</div>
            </div>

            <label>超级管理员账号</label>
            <input name="admin_user" value="admin">
            <label>超级管理员密码</label>
            <input name="admin_pass" type="password" placeholder="至少 8 位">
            <button type="submit">开始安装</button>
        </form>
        <script>
            function toggleDb(v) {
                document.getElementById('mysql-fields').style.display = v === 'sqlite' ? 'none' : 'block';
            }
        </script>
    <?php endif; ?>
</div>
</body>
</html>
