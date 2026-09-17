<?php
/**
 * 超级管理员：会话 / 登录 / 鉴权
 */
declare(strict_types=1);

function admin_token_from_request(): string
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth !== '' && stripos($auth, 'bearer ') === 0) return trim(substr($auth, 7));
    if (!empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) return trim((string)$_SERVER['HTTP_X_ADMIN_TOKEN']);
    if (isset($_GET['adminToken'])) return trim((string)$_GET['adminToken']);
    return '';
}

function admin_pwd_hash(string $salt, string $password): string
{
    return hash('sha256', $salt . ':' . $password);
}

function current_admin(): ?string
{
    $token = admin_token_from_request();
    if ($token === '') return null;
    $row = DB::one('SELECT username, expires_at FROM admin_sessions WHERE token = ?', [$token]);
    if (!$row) return null;
    if (strtotime((string)$row['expires_at']) < time()) {
        DB::exec('DELETE FROM admin_sessions WHERE token = ?', [$token]);
        return null;
    }
    return (string)$row['username'];
}

function require_admin(): string
{
    $u = current_admin();
    if ($u === null) throw new ApiError('unauthorized', '管理员身份无效或已过期，请重新登录。', 401);
    return $u;
}

function admin_login(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') throw new ApiError('invalid_input', '请输入账号与密码。', 400);
    $key = 'login:' . sha256_hex($username . '|' . client_ip());
    if (!rate_limit_check($key, 10, 600)) throw new ApiError('rate_limited', '尝试次数过多，请稍后再试。', 429);

    $row = DB::one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$row || !hash_equals((string)$row['password_hash'], admin_pwd_hash((string)$row['salt'], $password))) {
        throw new ApiError('unauthorized', '账号或密码错误。', 401);
    }
    $token = new_token('adm_');
    $now = db_now();
    DB::exec('INSERT INTO admin_sessions (token, username, expires_at, created_at) VALUES (?, ?, ?, ?)', [
        $token, $username, date('Y-m-d H:i:s', time() + ADMIN_SESSION_DAYS * 86400), $now,
    ]);
    return ['token' => $token, 'username' => $username, 'expiresAt' => iso_out(date('Y-m-d H:i:s', time() + ADMIN_SESSION_DAYS * 86400))];
}

function admin_logout(): void
{
    $token = admin_token_from_request();
    if ($token !== '') DB::exec('DELETE FROM admin_sessions WHERE token = ?', [$token]);
}

function admin_change_password(string $username, string $current, string $next): void
{
    if (strlen($next) < 8) throw new ApiError('weak_password', '新密码至少 8 位。', 400);
    $row = DB::one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$row) throw new ApiError('not_found', '账号不存在。', 404);
    if (!hash_equals((string)$row['password_hash'], admin_pwd_hash((string)$row['salt'], $current))) {
        throw new ApiError('invalid_password', '当前密码不正确。', 400);
    }
    $salt = bin2hex(random_bytes(8));
    DB::exec('UPDATE admins SET password_hash = ?, salt = ? WHERE username = ?', [admin_pwd_hash($salt, $next), $salt, $username]);
    DB::exec('DELETE FROM admin_sessions WHERE username = ?', [$username]);
}
