<?php
/**
 * 超级管理员接口（车牌 / 二维码 / 通知渠道 / 拨号日志 / 广告位 / 账号 / 数据看板）
 */
declare(strict_types=1);

function api_admin_login(): void
{
    $in = request_json();
    $res = admin_login((string)($in['username'] ?? ''), (string)($in['password'] ?? ''));
    json_out($res);
}

function api_admin_logout(): void
{
    admin_logout();
    json_message('已退出登录。');
}

function api_admin_config_get(): void
{
    $g = load_global();
    $settings = [];
    foreach (global_settings_meta() as $s) {
        $settings[] = [
            'key' => $s['key'],
            'label' => $s['label'],
            'secret' => !empty($s['secret']),
            'group' => $s['group'] ?? 'other',
            'type' => $s['type'] ?? 'text',
            'options' => $s['options'] ?? null,
            'showIf' => $s['showIf'] ?? null,
            'value' => !empty($s['secret']) ? (!empty($g[$s['key']]) ? '••••••' : '') : ($g[$s['key']] ?? ($s['def'] ?? '')),
        ];
    }
    $keys = array_column(channel_groups(), 'key');
    json_out([
        'settings' => $settings,
        'groups' => channel_groups(),
        'channelStatus' => array_reduce($keys, fn($a, $k) => $a + [$k => channel_opened($g, $k)], []),
        'channelEnabled' => array_reduce($keys, fn($a, $k) => $a + [$k => channel_enabled($g, $k)], []),
        'channelMissing' => array_reduce($keys, fn($a, $k) => $a + [$k => channel_missing_params($g, $k)], []),
    ]);
}

function api_admin_config_put(): void
{
    $in = request_json();
    $updated = save_global($in);
    json_message('全局配置已保存。', ['updated' => $updated]);
}

function api_admin_overview(): void
{
    $g = load_global();
    $vehicles = (int)DB::val('SELECT COUNT(*) FROM vehicles', [], 0);
    $qr = DB::one('SELECT
        SUM(CASE WHEN status = "unbound" THEN 1 ELSE 0 END) AS unbound,
        SUM(CASE WHEN status = "bound" THEN 1 ELSE 0 END) AS bound,
        SUM(CASE WHEN status = "disabled" THEN 1 ELSE 0 END) AS disabled,
        COUNT(*) AS total FROM qr_codes') ?: [];
    $today = date('Y-m-d') . ' 00:00:00';
    $notifiedToday = (int)DB::val('SELECT COUNT(*) FROM notification_logs WHERE created_at >= ?', [$today], 0);
    $failedToday = (int)DB::val("SELECT COUNT(*) FROM notification_logs WHERE created_at >= ? AND status = 'failed'", [$today], 0);
    $callsToday = (int)DB::val('SELECT COUNT(*) FROM call_logs WHERE created_at >= ?', [$today], 0);
    $channels = [];
    foreach (channel_groups() as $c) $channels[$c['key']] = channel_opened($g, $c['key']);
    json_out([
        'vehicles' => $vehicles,
        'qr' => [
            'total' => (int)($qr['total'] ?? 0),
            'unbound' => (int)($qr['unbound'] ?? 0),
            'bound' => (int)($qr['bound'] ?? 0),
            'disabled' => (int)($qr['disabled'] ?? 0),
        ],
        'today' => ['notifications' => $notifiedToday, 'failed' => $failedToday, 'calls' => $callsToday],
        'channels' => $channels,
        'runtime' => ['php' => PHP_VERSION, 'db' => DB::driver(), 'version' => MC_SCHEMA_VERSION],
    ]);
}

/* ---------------- 账号 ---------------- */

function api_admin_accounts(): void
{
    $rows = DB::all('SELECT username, created_at FROM admins ORDER BY id ASC');
    json_out(['accounts' => array_map(fn($r) => ['username' => $r['username'], 'createdAt' => iso_out($r['created_at'])], $rows)]);
}

function api_admin_account_create(): void
{
    $in = request_json();
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    if ($username === '' || strlen($password) < 8) json_error('invalid_input', '账号不能为空，密码至少 8 位。', 400);
    if (DB::one('SELECT id FROM admins WHERE username = ?', [$username])) json_error('exists', '该账号已存在。', 409);
    $salt = bin2hex(random_bytes(8));
    DB::exec('INSERT INTO admins (username, password_hash, salt, created_at) VALUES (?,?,?,?)',
        [$username, admin_pwd_hash($salt, $password), $salt, db_now()]);
    json_message('账号已创建。', [], 201);
}

function api_admin_account_delete(string $username): void
{
    if ((int)DB::val('SELECT COUNT(*) FROM admins', [], 0) <= 1) json_error('last_account', '至少保留一个管理员账号。', 400);
    DB::exec('DELETE FROM admins WHERE username = ?', [$username]);
    DB::exec('DELETE FROM admin_sessions WHERE username = ?', [$username]);
    json_message('账号已删除。');
}

function api_admin_password(): void
{
    $admin = require_admin();
    $in = request_json();
    admin_change_password($admin, (string)($in['currentPassword'] ?? ''), (string)($in['newPassword'] ?? ''));
    json_message('密码已修改，请使用新密码重新登录。');
}

/* ---------------- 查车主电话 ---------------- */

function api_admin_lookup(): void
{
    $plate = normalize_plate(input_str('plate'));
    if (!is_plate($plate)) json_error('missing_plate', '请提供车牌号。', 400);
    $v = DB::one('SELECT * FROM vehicles WHERE plate_number_hash = ?', [sha256_hex($plate)]);
    if (!$v) json_out(['found' => false, 'message' => '未找到该车牌绑定的挪车码。']);
    $g = load_global();
    $phone = null;
    try { if ($v['owner_phone_enc']) $phone = decrypt_text($v['owner_phone_enc']); } catch (Throwable $e) {}
    $logs = DB::all('SELECT channel, status, created_at FROM notification_logs WHERE vehicle_id = ? ORDER BY id DESC LIMIT 5', [(int)$v['id']]);
    json_out([
        'found' => true,
        'maskedPlate' => $v['plate_number'],
        'phone' => $phone,
        'channels' => available_channels($v, $g),
        'smsEnabled' => (bool)(int)$v['sms_enabled'],
        'privacyCallEnabled' => (bool)(int)$v['privacy_call_enabled'],
        'createdAt' => iso_out($v['created_at']),
        'recentNotifications' => array_map(fn($l) => ['channel' => $l['channel'], 'status' => $l['status'], 'createdAt' => iso_out($l['created_at'])], $logs),
    ]);
}

/* ---------------- 广告位 ---------------- */

function api_admin_ads_list(): void
{
    json_out(['positions' => ad_positions(), 'ads' => DB::all('SELECT * FROM ads ORDER BY position ASC, sort_order ASC, id DESC')]);
}

function api_admin_ads_create(): void
{
    $in = request_json();
    if (!in_array($in['position'] ?? '', ad_position_keys(), true)) json_error('invalid_position', '请选择有效的广告位。', 400);
    if (!is_http_url((string)($in['imageUrl'] ?? ''))) json_error('invalid_image_url', '广告图片链接必须是 http 或 https 地址。', 400);
    $link = trim((string)($in['linkUrl'] ?? ''));
    if ($link !== '' && !is_http_url($link)) json_error('invalid_link_url', '跳转链接必须是 http 或 https 地址。', 400);
    $now = db_now();
    $id = DB::insert('INSERT INTO ads (position, title, image_url, link_url, sort_order, enabled, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)', [
        $in['position'], trim((string)($in['title'] ?? '')), $in['imageUrl'], $link,
        (int)($in['sortOrder'] ?? 0), (($in['enabled'] ?? true) === false) ? 0 : 1, $now, $now,
    ]);
    json_message('广告已创建。', ['id' => $id], 201);
}

function api_admin_ads_update(int $id): void
{
    $in = request_json();
    if (!DB::one('SELECT id FROM ads WHERE id = ?', [$id])) json_error('not_found', '广告不存在。', 404);
    $sets = [];
    $binds = [];
    if (array_key_exists('position', $in)) {
        if (!in_array($in['position'], ad_position_keys(), true)) json_error('invalid_position', '广告位不合法。', 400);
        $sets[] = 'position = ?'; $binds[] = $in['position'];
    }
    if (array_key_exists('title', $in)) { $sets[] = 'title = ?'; $binds[] = trim((string)$in['title']); }
    if (array_key_exists('imageUrl', $in)) {
        if (!is_http_url((string)$in['imageUrl'])) json_error('invalid_image_url', '广告图片链接必须是 http 或 https 地址。', 400);
        $sets[] = 'image_url = ?'; $binds[] = $in['imageUrl'];
    }
    if (array_key_exists('linkUrl', $in)) {
        $v = trim((string)$in['linkUrl']);
        if ($v !== '' && !is_http_url($v)) json_error('invalid_link_url', '跳转链接必须是 http 或 https 地址。', 400);
        $sets[] = 'link_url = ?'; $binds[] = $v;
    }
    if (array_key_exists('sortOrder', $in)) { $sets[] = 'sort_order = ?'; $binds[] = (int)$in['sortOrder']; }
    if (array_key_exists('enabled', $in)) { $sets[] = 'enabled = ?'; $binds[] = !empty($in['enabled']) ? 1 : 0; }
    if (!$sets) json_message('没有需要更新的字段。');
    $sets[] = 'updated_at = ?';
    $binds[] = db_now();
    $binds[] = $id;
    DB::exec('UPDATE ads SET ' . implode(', ', $sets) . ' WHERE id = ?', $binds);
    json_message('广告已更新。');
}

function api_admin_ads_delete(int $id): void
{
    DB::exec('DELETE FROM ads WHERE id = ?', [$id]);
    json_message('广告已删除。');
}

/* ---------------- 车辆管理 ---------------- */

function api_admin_vehicles_list(): void
{
    $q = input_str('q');
    $limit = min(max((int)input_str('limit', '200'), 1), 1000);
    $offset = max((int)input_str('offset', '0'), 0);
    $binds = [];
    $sql = 'SELECT * FROM vehicles';
    if ($q !== '') {
        $sql .= ' WHERE plate_number LIKE ? OR id = ?';
        $binds[] = '%' . normalize_plate($q) . '%';
        $binds[] = (int)$q;
    }
    $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
    $binds[] = $limit;
    $binds[] = $offset;
    $rows = DB::all($sql, $binds);
    $total = (int)DB::val('SELECT COUNT(*) FROM vehicles', [], 0);
    json_out([
        'vehicles' => array_map('admin_vehicle_view', $rows),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

function api_admin_vehicle_create(): void
{
    $in = request_json();
    $plate = normalize_plate((string)($in['plateNumber'] ?? ''));
    if (!is_plate($plate)) json_error('invalid_plate', '请填写有效车牌号。', 400);
    if (DB::one('SELECT id FROM vehicles WHERE plate_number_hash = ?', [sha256_hex($plate)])) {
        json_error('duplicate_plate', '车牌 ' . $plate . ' 已存在绑定，请直接编辑该记录。', 409);
    }
    if (!is_phone((string)($in['ownerPhone'] ?? ''))) json_error('invalid_phone', '手机号必填，且需为有效号码。', 400);
    $created = insert_vehicle($in);
    json_message('车辆已创建。', $created, 201);
}

function api_admin_vehicle_update(int $id): void
{
    $v = DB::one('SELECT * FROM vehicles WHERE id = ?', [$id]);
    if (!$v) json_error('not_found', '车辆不存在。', 404);
    $in = request_json();
    $sets = [];
    $binds = [];
    if (array_key_exists('plateNumber', $in) && normalize_plate((string)$in['plateNumber']) !== '') {
        $plate = normalize_plate((string)$in['plateNumber']);
        if (!is_plate($plate)) json_error('invalid_plate', '请填写有效车牌号。', 400);
        $dup = DB::one('SELECT id FROM vehicles WHERE plate_number_hash = ? AND id <> ?', [sha256_hex($plate), $id]);
        if ($dup) json_error('duplicate_plate', '车牌 ' . $plate . ' 已存在绑定。', 409);
        $sets[] = 'plate_number = ?'; $binds[] = $plate;
        $sets[] = 'plate_number_hash = ?'; $binds[] = sha256_hex($plate);
    }
    if (array_key_exists('ownerPhone', $in)) {
        $phone = trim((string)$in['ownerPhone']);
        $sets[] = 'owner_phone_enc = ?';
        $binds[] = $phone !== '' ? encrypt_text(normalize_phone($phone)) : null;
    }
    if (array_key_exists('wechatOpenid', $in)) {
        $sets[] = 'wechat_openid_enc = ?';
        $binds[] = !empty($in['wechatOpenid']) ? encrypt_text(trim((string)$in['wechatOpenid'])) : null;
    }
    if (array_key_exists('notifyAllEnabled', $in)) {
        $val = !empty($in['notifyAllEnabled']) ? 1 : 0;
        $sets[] = 'wechat_work_enabled = ?'; $binds[] = $val;
        $sets[] = 'wechat_enabled = ?'; $binds[] = $val;
    }
    foreach (['smsEnabled' => 'sms_enabled', 'privacyCallEnabled' => 'privacy_call_enabled', 'wechatWorkEnabled' => 'wechat_work_enabled', 'wechatEnabled' => 'wechat_enabled'] as $k => $col) {
        if (array_key_exists($k, $in)) { $sets[] = "{$col} = ?"; $binds[] = !empty($in[$k]) ? 1 : 0; }
    }
    if (!empty($in['ownerPin'])) { $sets[] = 'owner_pin_hash = ?'; $binds[] = sha256_hex('pin:' . $in['ownerPin']); }
    if (!$sets) json_message('没有需要更新的字段。');
    $sets[] = 'updated_at = ?';
    $binds[] = db_now();
    $binds[] = $id;
    DB::exec('UPDATE vehicles SET ' . implode(', ', $sets) . ' WHERE id = ?', $binds);
    json_message('车辆已更新。');
}

function api_admin_vehicle_delete(int $id): void
{
    if (!DB::one('SELECT id FROM vehicles WHERE id = ?', [$id])) json_error('not_found', '车辆不存在。', 404);
    DB::exec('DELETE FROM notification_logs WHERE vehicle_id = ?', [$id]);
    DB::exec('DELETE FROM call_logs WHERE vehicle_id = ?', [$id]);
    DB::exec('DELETE FROM vehicles WHERE id = ?', [$id]);
    release_qr_for_vehicle($id);
    json_message('车辆已删除，二维码已退回未绑定。');
}

function api_admin_vehicle_owner_token(int $id): void
{
    $v = DB::one('SELECT owner_token, plate_number FROM vehicles WHERE id = ?', [$id]);
    if (!$v) json_error('not_found', '车辆不存在。', 404);
    json_out(['ownerToken' => $v['owner_token'], 'maskedPlate' => $v['plate_number']]);
}

function api_admin_vehicle_export(): void
{
    $rows = DB::all('SELECT * FROM vehicles ORDER BY id DESC');
    $data = [];
    foreach ($rows as $v) {
        $phone = '';
        try { if ($v['owner_phone_enc']) $phone = decrypt_text($v['owner_phone_enc']); } catch (Throwable $e) {}
        $data[] = [$v['plate_number'], $phone, $v['vehicle_token'], $v['owner_token'], $v['created_at']];
    }
    csv_download('vehicles-' . date('Ymd') . '.csv', ['车牌', '手机号', '挪车令牌', '管理令牌', '创建时间'], $data);
}

function api_admin_vehicle_import(): void
{
    $in = request_json();
    $rows = $in['rows'] ?? null;
    if (!is_array($rows) && !empty($in['csv'])) {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$in['csv']) as $i => $line) {
            if ($i === 0 && str_contains($line, '车牌')) continue;
            $cols = str_getcsv($line);
            if (count($cols) < 2 || trim($cols[0]) === '') continue;
            $rows[] = ['plateNumber' => trim($cols[0]), 'ownerPhone' => trim($cols[1] ?? '')];
        }
    }
    if (!is_array($rows)) json_error('invalid_input', '请提供 rows 数组或 csv 文本。', 400);
    $created = 0;
    $skipped = 0;
    foreach ($rows as $r) {
        $plate = normalize_plate((string)($r['plateNumber'] ?? ''));
        if (!is_plate($plate) || !is_phone((string)($r['ownerPhone'] ?? ''))) { $skipped++; continue; }
        if (DB::one('SELECT id FROM vehicles WHERE plate_number_hash = ?', [sha256_hex($plate)])) { $skipped++; continue; }
        insert_vehicle($r);
        $created++;
    }
    json_message("导入完成：新增 {$created} 条，跳过 {$skipped} 条。", ['created' => $created, 'skipped' => $skipped]);
}

/* ---------------- 预生成二维码 ---------------- */

function api_admin_qr_list(): void
{
    $status = input_str('status');
    $batch = input_str('batch');
    $q = input_str('q');
    $limit = min(max((int)input_str('limit', '100'), 1), 500);
    $offset = max((int)input_str('offset', '0'), 0);

    $where = [];
    $vals = [];
    if (in_array($status, ['unbound', 'bound', 'disabled'], true)) { $where[] = 'c.status = ?'; $vals[] = $status; }
    if ($batch !== '') { $where[] = 'c.batch_no = ?'; $vals[] = $batch; }
    if ($q !== '') {
        $where[] = '(c.code_token LIKE ? OR c.batch_no LIKE ? OR c.note LIKE ? OR v.plate_number LIKE ?)';
        $vals[] = "%$q%"; $vals[] = "%$q%"; $vals[] = "%$q%"; $vals[] = "%$q%";
    }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = DB::all("SELECT c.*, v.plate_number, v.vehicle_token FROM qr_codes c LEFT JOIN vehicles v ON v.id = c.vehicle_id
        {$clause} ORDER BY c.id DESC LIMIT ? OFFSET ?", array_merge($vals, [$limit, $offset]));
    $total = (int)DB::val("SELECT COUNT(*) FROM qr_codes c LEFT JOIN vehicles v ON v.id = c.vehicle_id {$clause}", $vals, 0);
    $stats = DB::one('SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = "unbound" THEN 1 ELSE 0 END) AS unbound,
        SUM(CASE WHEN status = "bound" THEN 1 ELSE 0 END) AS bound,
        SUM(CASE WHEN status = "disabled" THEN 1 ELSE 0 END) AS disabled FROM qr_codes') ?: [];
    $batches = DB::all("SELECT batch_no, COUNT(*) AS n FROM qr_codes WHERE batch_no <> '' GROUP BY batch_no ORDER BY batch_no DESC LIMIT 50");

    json_out([
        'codes' => array_map(fn($r) => [
            'id' => (int)$r['id'],
            'codeToken' => $r['code_token'],
            'batchNo' => $r['batch_no'],
            'status' => $r['status'],
            'note' => $r['note'],
            'maskedPlate' => $r['plate_number'] ?? '',
            'vehicleToken' => $r['vehicle_token'] ?? '',
            'boundAt' => iso_out($r['bound_at'] ?? null),
            'createdAt' => iso_out($r['created_at']),
        ], $rows),
        'total' => $total,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'unbound' => (int)($stats['unbound'] ?? 0),
            'bound' => (int)($stats['bound'] ?? 0),
            'disabled' => (int)($stats['disabled'] ?? 0),
        ],
        'batches' => array_map(fn($b) => ['batchNo' => $b['batch_no'], 'count' => (int)$b['n']], $batches),
    ]);
}

function api_admin_qr_batch(): void
{
    $in = request_json();
    $count = (int)($in['count'] ?? 0);
    if ($count < 1 || $count > 500) json_error('invalid_count', '生成数量需在 1-500 之间。', 400);
    $batchNo = trim((string)($in['batchNo'] ?? '')) ?: date('Ymd-His');
    $note = trim((string)($in['note'] ?? ''));
    $now = db_now();
    $created = [];
    DB::tx(function () use ($count, $batchNo, $note, $now, &$created) {
        $stmt = DB::pdo()->prepare('INSERT INTO qr_codes (code_token, batch_no, status, note, created_at, updated_at) VALUES (?,?,?,?,?,?)');
        for ($i = 0; $i < $count; $i++) {
            $token = new_code_token();
            $stmt->execute([$token, $batchNo, 'unbound', $note, $now, $now]);
            $created[] = $token;
        }
    });
    json_message("已生成 {$count} 个二维码。", ['batchNo' => $batchNo, 'tokens' => $created], 201);
}

function api_admin_qr_update(int $id): void
{
    $in = request_json();
    $status = trim((string)($in['status'] ?? ''));
    if (!in_array($status, ['unbound', 'disabled'], true)) json_error('invalid_status', '状态只能设为启用或停用。', 400);
    $row = DB::one('SELECT * FROM qr_codes WHERE id = ?', [$id]);
    if (!$row) json_error('not_found', '二维码不存在。', 404);
    if ($row['status'] === 'bound') json_error('already_bound', '已绑定的二维码不能改状态。', 400);
    DB::exec('UPDATE qr_codes SET status = ?, updated_at = ? WHERE id = ?', [$status, db_now(), $id]);
    json_message($status === 'disabled' ? '二维码已停用。' : '二维码已启用。');
}

function api_admin_qr_delete(int $id): void
{
    $row = DB::one('SELECT * FROM qr_codes WHERE id = ?', [$id]);
    if (!$row) json_error('not_found', '二维码不存在。', 404);
    if ($row['status'] === 'bound') json_error('already_bound', '该二维码已绑定车辆，请先在「车牌管理」删除对应车牌。', 400);
    DB::exec('DELETE FROM qr_codes WHERE id = ?', [$id]);
    json_message('二维码已删除。');
}

function api_admin_qr_bulk_delete(): void
{
    $in = request_json();
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) json_error('empty_selection', '请先选择要删除的二维码。', 400);
    $ph = DB::placeholders(count($ids));
    $n = DB::exec("DELETE FROM qr_codes WHERE id IN ($ph) AND status <> 'bound'", $ids);
    json_message("已删除 {$n} 个未绑定二维码（已绑定的已自动跳过）。");
}

/**
 * 通用批量操作：action=delete | disable | enable
 * - delete: 已绑定的会跳过（提示告知）
 * - disable/enable: 同样会自动跳过已绑定的（状态不可改）
 */
function api_admin_qr_bulk(): void
{
    $in = request_json();
    $action = trim((string)($in['action'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) json_error('empty_selection', '请先选择要操作的二维码。', 400);
    if (!in_array($action, ['delete', 'disable', 'enable'], true)) {
        json_error('invalid_action', '不支持的操作类型：' . $action, 400);
    }
    $ph = DB::placeholders(count($ids));

    if ($action === 'delete') {
        $n = DB::exec("DELETE FROM qr_codes WHERE id IN ($ph) AND status <> 'bound'", $ids);
        json_message("已删除 {$n} 个未绑定二维码（已绑定的已自动跳过）。");
    }

    $target = $action === 'disable' ? 'disabled' : 'unbound';
    $verb = $action === 'disable' ? '停用' : '启用';
    // 锁定写入：批量更新且排除已绑定
    DB::tx(function () use ($ids, $ph, $target) {
        $n = DB::exec("UPDATE qr_codes SET status = ?, updated_at = ? WHERE id IN ($ph) AND status <> 'bound'", [$target, db_now(), ...$ids]);
        return $n;
    });
    // tx 闭包没有返回值，需要重新查询来获取受影响行数
    $n = (int)DB::val(
        "SELECT COUNT(*) FROM qr_codes WHERE id IN ($ph) AND status = ?",
        array_merge($ids, [$target])
    );
    // 同时统计已绑定的跳过数
    $skipped = count($ids) - $n;
    $extra = $skipped > 0 ? "（已绑定 {$skipped} 个已自动跳过）" : '';
    json_message("已{$verb} {$n} 个二维码{$extra}。");
}

/* ---------------- 拨号日志 ---------------- */

function build_call_log_filter(): array
{
    $q = input_str('q');
    $channel = input_str('channel');
    $status = input_str('status');
    $from = input_str('from');
    $to = input_str('to');
    $last4 = input_str('last4');
    $vehicleId = input_str('vehicleId');

    $where = [];
    $binds = [];
    if ($channel !== '') { $where[] = 'c.channel = ?'; $binds[] = $channel; }
    if ($status !== '') { $where[] = 'c.status = ?'; $binds[] = $status; }
    if ($vehicleId !== '') { $where[] = 'c.vehicle_id = ?'; $binds[] = (int)$vehicleId; }
    if ($from !== '') { $where[] = 'c.created_at >= ?'; $binds[] = to_db_time($from); }
    if ($to !== '') { $where[] = 'c.created_at <= ?'; $binds[] = to_db_time($to, true); }
    if ($q !== '') {
        $plate = normalize_plate($q);
        if (is_plate($plate)) {
            $where[] = '(v.plate_number LIKE ? OR v.plate_number_hash = ?)';
            $binds[] = "%$plate%";
            $binds[] = sha256_hex($plate);
        } else {
            $where[] = 'v.plate_number LIKE ?';
            $binds[] = "%$q%";
        }
    }
    if ($last4 !== '') {
        $where[] = '(c.caller_last4 = ? OR c.callee_last4 = ? OR c.virtual_last4 = ?)';
        $binds[] = $last4; $binds[] = $last4; $binds[] = $last4;
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $binds];
}

function query_call_logs(int $limit, int $offset): array
{
    [$whereSql, $binds] = build_call_log_filter();
    $rows = DB::all("SELECT c.*, v.plate_number FROM call_logs c LEFT JOIN vehicles v ON v.id = c.vehicle_id
        {$whereSql} ORDER BY c.id DESC LIMIT ? OFFSET ?", array_merge($binds, [$limit, $offset]));
    $total = (int)DB::val("SELECT COUNT(*) FROM call_logs c LEFT JOIN vehicles v ON v.id = c.vehicle_id {$whereSql}", $binds, 0);
    return [$rows, $total];
}

function api_admin_call_logs(): void
{
    $limit = min(max((int)input_str('limit', '50'), 1), CALL_LOG_LIMIT);
    $offset = max((int)input_str('offset', '0'), 0);
    [$rows, $total] = query_call_logs($limit, $offset);
    json_out(['logs' => array_map('call_log_view', $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

function api_admin_call_logs_export(): void
{
    [$rows] = query_call_logs(5000, 0);
    $data = [];
    foreach ($rows as $r) {
        $l = call_log_view($r);
        $data[] = [$l['plateNumber'], $l['channel'], $l['callerNumber'], $l['calleeNumber'], $l['virtualNumber'], $l['status'], $l['createdAt']];
    }
    csv_download('call-logs-' . date('Ymd') . '.csv', ['车牌', '通道', '主叫', '被叫', '中间号', '状态', '时间'], $data);
}

function api_admin_call_logs_bulk_delete(): void
{
    $in = request_json();
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids && ($in['all'] ?? false) !== true) json_error('no_ids', '请选择要删除的日志。', 400);
    if (!$ids) {
        [$whereSql, $binds] = build_call_log_filter();
        if ($whereSql === '') json_error('no_filter', '未提供筛选条件，拒绝清空全部日志。', 400);
        $n = DB::exec("DELETE FROM call_logs WHERE id IN (SELECT c.id FROM call_logs c LEFT JOIN vehicles v ON v.id = c.vehicle_id {$whereSql})", $binds);
        json_message("已按筛选条件删除 {$n} 条日志。", ['deleted' => $n]);
    }
    if (count($ids) > 2000) json_error('too_many', '单次最多删除 2000 条。', 400);
    $ph = DB::placeholders(count($ids));
    $n = DB::exec("DELETE FROM call_logs WHERE id IN ($ph)", $ids);
    json_message("已删除 {$n} 条日志。", ['deleted' => $n]);
}
