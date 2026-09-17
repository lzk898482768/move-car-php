<?php
/**
 * API 路由与业务实现（接口契约与原 Cloudflare 版保持一致，便于前端直接复用）
 */
declare(strict_types=1);

/* ============================ 工具 ============================ */

function get_vehicle_by_token(string $token): ?array
{
    return DB::one('SELECT * FROM vehicles WHERE vehicle_token = ?', [$token]);
}

function get_vehicle_by_owner_token(string $token): ?array
{
    return DB::one('SELECT * FROM vehicles WHERE owner_token = ?', [$token]);
}

function vehicle_plate_number(array $v): string
{
    return (string)$v['plate_number'];
}

function admin_vehicle_view(array $v): array
{
    $phone = '';
    try { if (!empty($v['owner_phone_enc'])) $phone = decrypt_text($v['owner_phone_enc']); } catch (Throwable $e) {}
    $openid = '';
    try { if (!empty($v['wechat_openid_enc'])) $openid = decrypt_text($v['wechat_openid_enc']); } catch (Throwable $e) {}
    return [
        'id' => (int)$v['id'],
        'plateNumber' => $v['plate_number'],
        'maskedPlate' => $v['plate_number'],
        'ownerPhone' => $phone,
        'wechatOpenid' => $openid,
        'notifyAllEnabled' => (bool)((int)$v['wechat_work_enabled'] && (int)$v['wechat_enabled']),
        'wechatWorkEnabled' => (bool)(int)$v['wechat_work_enabled'],
        'wechatEnabled' => (bool)(int)$v['wechat_enabled'],
        'smsEnabled' => (bool)(int)$v['sms_enabled'],
        'privacyCallEnabled' => (bool)(int)$v['privacy_call_enabled'],
        'hasPin' => (bool)$v['owner_pin_hash'],
        'hasPhone' => !empty($v['owner_phone_enc']),
        'vehicleToken' => $v['vehicle_token'],
        'createdAt' => iso_out($v['created_at']),
        'updatedAt' => iso_out($v['updated_at']),
    ];
}

function validate_vehicle_input(array $in, array $opt = []): ?array
{
    $partial = !empty($opt['partial']);
    $requirePhone = !empty($opt['requirePhone']);
    $hasStoredPhone = !empty($opt['hasStoredPhone']);

    if ($partial) {
        $needsPhone = !empty($in['smsEnabled']) || !empty($in['privacyCallEnabled']);
        if (($needsPhone && !$hasStoredPhone) || !empty($in['ownerPhone'])) {
            if (!is_phone((string)($in['ownerPhone'] ?? ''))) {
                return ['error' => 'invalid_phone', 'message' => '请填写有效手机号，或关闭短信/隐私号通知。'];
            }
        }
        if (!empty($in['wechatWorkWebhook']) && !is_http_url((string)$in['wechatWorkWebhook'])) {
            return ['error' => 'invalid_wechat_work_webhook', 'message' => '企业微信机器人 Webhook 必须是 http 或 https 地址。'];
        }
        return null;
    }

    $plate = normalize_plate((string)($in['plateNumber'] ?? ''));
    if (!is_plate($plate)) return ['error' => 'invalid_plate', 'message' => '请填写有效车牌号。'];
    if (!empty($in['ownerPin']) && !is_pin((string)$in['ownerPin'])) {
        return ['error' => 'invalid_pin', 'message' => '管理密码需为 4-12 位数字。'];
    }
    if ($requirePhone && !is_phone((string)($in['ownerPhone'] ?? ''))) {
        return ['error' => 'invalid_phone', 'message' => '请填写有效手机号（录入车牌时必填，用于短信 / 隐私号通知与换号验证）。'];
    }
    if (!empty($in['wechatWorkWebhook']) && !is_http_url((string)$in['wechatWorkWebhook'])) {
        return ['error' => 'invalid_wechat_work_webhook', 'message' => '企业微信机器人 Webhook 必须是 http 或 https 地址。'];
    }
    $needsPhone = !empty($in['smsEnabled']) || !empty($in['privacyCallEnabled']);
    if (($needsPhone && !$hasStoredPhone) || !empty($in['ownerPhone'])) {
        if (!is_phone((string)($in['ownerPhone'] ?? ''))) {
            return ['error' => 'invalid_phone', 'message' => '请填写有效手机号，或关闭短信/隐私号通知。'];
        }
    }
    return null;
}

/** 新建车辆（车主创建 / 二维码绑定 / 管理员新增 / 导入 共用） */
function insert_vehicle(array $in): array
{
    $plate = normalize_plate((string)$in['plateNumber']);
    $vehicleToken = new_token('veh_');
    $ownerToken = new_token('own_');
    $now = db_now();
    $bundle = !empty($in['notifyAllEnabled']) || !empty($in['wechatEnabled']) || !empty($in['wechatWorkEnabled']);
    $phone = is_phone((string)($in['ownerPhone'] ?? '')) ? normalize_phone((string)$in['ownerPhone']) : '';
    $id = DB::insert(
        'INSERT INTO vehicles (vehicle_token, owner_token, plate_number, plate_number_hash, owner_phone_enc,
            wechat_work_webhook_enc, wechat_work_enabled, wechat_enabled, sms_enabled, privacy_call_enabled,
            owner_pin_hash, wechat_openid_enc, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $vehicleToken,
            $ownerToken,
            $plate,
            sha256_hex($plate),
            $phone !== '' ? encrypt_text($phone) : null,
            !empty($in['wechatWorkWebhook']) ? encrypt_text((string)$in['wechatWorkWebhook']) : null,
            $bundle ? 1 : 0,
            $bundle ? 1 : 0,
            !empty($in['smsEnabled']) ? 1 : 0,
            !empty($in['privacyCallEnabled']) ? 1 : 0,
            !empty($in['ownerPin']) ? sha256_hex('pin:' . $in['ownerPin']) : null,
            !empty($in['wechatOpenid']) ? encrypt_text(trim((string)$in['wechatOpenid'])) : null,
            $now,
            $now,
        ]
    );
    return ['id' => $id, 'vehicleToken' => $vehicleToken, 'ownerToken' => $ownerToken, 'maskedPlate' => $plate];
}

function release_qr_for_vehicle(int $vehicleId): void
{
    DB::exec("UPDATE qr_codes SET status='unbound', vehicle_id=NULL, bound_at=NULL, updated_at=? WHERE vehicle_id=?", [db_now(), $vehicleId]);
}

function record_call_log(array $vehicle, string $channel, array $data): void
{
    DB::exec(
        'INSERT INTO call_logs (vehicle_id, channel, caller_number_enc, caller_last4, callee_number_enc, callee_last4,
            virtual_number_enc, virtual_last4, status, error_summary, visitor_ip_hash, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            (int)$vehicle['id'],
            $channel,
            !empty($data['callerNumber']) ? encrypt_text((string)$data['callerNumber']) : null,
            last4((string)($data['callerNumber'] ?? '')),
            !empty($data['calleeNumber']) ? encrypt_text((string)$data['calleeNumber']) : null,
            last4((string)($data['calleeNumber'] ?? '')),
            !empty($data['virtualNumber']) ? encrypt_text((string)$data['virtualNumber']) : null,
            last4((string)($data['virtualNumber'] ?? '')),
            (string)($data['status'] ?? 'success'),
            str_slice((string)($data['errorSummary'] ?? '')),
            (string)($data['visitorIpHash'] ?? ''),
            db_now(),
        ]
    );
}

function call_log_view(array $row): array
{
    $dec = function ($v) {
        if (!$v) return '';
        try { return decrypt_text($v); } catch (Throwable $e) { return ''; }
    };
    return [
        'id' => (int)$row['id'],
        'vehicleId' => (int)$row['vehicle_id'],
        'plateNumber' => (string)($row['plate_number'] ?? ''),
        'maskedPlate' => (string)($row['plate_number'] ?? ''),
        'channel' => $row['channel'],
        'callerNumber' => $dec($row['caller_number_enc'] ?? ''),
        'callerLast4' => (string)($row['caller_last4'] ?? ''),
        'calleeNumber' => $dec($row['callee_number_enc'] ?? ''),
        'calleeLast4' => (string)($row['callee_last4'] ?? ''),
        'virtualNumber' => $dec($row['virtual_number_enc'] ?? ''),
        'virtualLast4' => (string)($row['virtual_last4'] ?? ''),
        'status' => $row['status'],
        'errorSummary' => (string)($row['error_summary'] ?? ''),
        'createdAt' => iso_out($row['created_at']),
    ];
}

function send_raw_sms(array $g, string $phone, string $text): void
{
    $vendor = (string)($g['sms_vendor'] ?? 'tencent');
    if (!sms_vendor_ready($g)) throw new RuntimeException('短信通道未配置');
    $target = to_e164($phone, $g['default_phone_country_code'] ?? '+86');
    if ($vendor === 'tencent') {
        $result = tencent_api($g, 'sms', 'sms.tencentcloudapi.com', '2021-01-11', 'SendSms', (string)($g['tencent_sms_region'] ?: 'ap-guangzhou'), [
            'SmsSdkAppId' => $g['tencent_sms_app_id'],
            'SignName' => $g['tencent_sms_sign_name'],
            'TemplateId' => $g['tencent_sms_template_id'],
            'TemplateParamSet' => [preg_match('/\d{6}/', $text, $m) ? $m[0] : ''],
            'PhoneNumberSet' => [$target],
        ]);
        $st = $result['SendStatusSet'][0] ?? null;
        if ($st && ($st['Code'] ?? '') !== 'Ok') throw new RuntimeException($st['Message'] ?? $st['Code']);
        return;
    }
    if ($vendor === 'aliyun') {
        $result = aliyun_api($g, 'dysmsapi.aliyuncs.com', [
            'Action' => 'SendSms',
            'RegionId' => (string)($g['aliyun_sms_region'] ?: 'cn-hangzhou'),
            'PhoneNumbers' => ltrim($target, '+'),
            'SignName' => $g['aliyun_sms_sign_name'],
            'TemplateCode' => $g['aliyun_sms_template_code'],
            'TemplateParam' => json_encode(['code' => preg_match('/\d{6}/', $text, $m) ? $m[0] : ''], JSON_UNESCAPED_UNICODE),
        ]);
        if (!empty($result['Code']) && $result['Code'] !== 'OK') throw new RuntimeException($result['Message'] ?? $result['Code']);
        return;
    }
    $headers = ['Content-Type: application/json'];
    if (!empty($g['sms_custom_token'])) $headers[] = 'Authorization: Bearer ' . $g['sms_custom_token'];
    [$code, $resp] = http_post_raw((string)$g['sms_custom_webhook'], json_encode([
        'phone' => $phone, 'text' => $text, 'purpose' => 'phone_change_verify', 'vendor' => 'custom',
    ], JSON_UNESCAPED_UNICODE), $headers);
    if ($code < 200 || $code >= 300) throw new RuntimeException('自定义短信 Webhook 失败：HTTP ' . $code);
}

/* ============================ 公开接口 ============================ */

function api_health(): void
{
    $g = load_global();
    json_out([
        'status' => 'ok',
        'runtime' => 'php',
        'php' => PHP_VERSION,
        'db' => DB::driver(),
        'encryption' => (bool)mc_config('encryption_key', false),
        'sms' => channel_opened($g, 'sms'),
        'privacy_call' => channel_opened($g, 'privacy_call'),
        'wechat_work' => channel_opened($g, 'wechat_work'),
        'wechat' => channel_opened($g, 'wechat'),
        'direct_call' => channel_opened($g, 'direct_call'),
        'smsVendor' => $g['sms_vendor'] ?? 'tencent',
        'privacyVendor' => $g['privacy_vendor'] ?? 'custom',
        'version' => MC_SCHEMA_VERSION,
    ]);
}

function api_public_channels(): void
{
    $g = load_global();
    $opened = platform_channels($g);
    json_out([
        'channels' => array_reduce(channel_groups(), fn($acc, $c) => $acc + [$c['key'] => channel_opened($g, $c['key'])], []),
        'available' => $opened,
        'groups' => array_map(fn($c) => ['key' => $c['key'], 'label' => $c['label'], 'icon' => $c['icon']], channel_groups()),
    ]);
}

function api_public_ads(): void
{
    $pos = input_str('position');
    $sql = 'SELECT * FROM ads WHERE enabled = 1';
    $binds = [];
    if ($pos !== '') { $sql .= ' AND position = ?'; $binds[] = $pos; }
    $sql .= ' ORDER BY sort_order ASC, id DESC';
    json_out(['ads' => DB::all($sql, $binds)]);
}

function api_create_vehicle(): void
{
    $in = request_json();
    if ($err = validate_vehicle_input($in, ['requirePhone' => true])) json_error($err['error'], $err['message'], 400);

    $plate = normalize_plate((string)$in['plateNumber']);
    $exists = DB::one('SELECT id, plate_number, owner_pin_hash FROM vehicles WHERE plate_number_hash = ?', [sha256_hex($plate)]);
    if ($exists) {
        json_out([
            'error' => 'plate_exists',
            'message' => '该车牌已录入过，一个车牌只能录入一次。请用「车牌 + 管理密码」找回管理入口。',
            'maskedPlate' => $exists['plate_number'],
            'canRecover' => (bool)$exists['owner_pin_hash'],
        ], 409);
    }

    $g = load_global();
    $bundleOn = !empty($in['notifyAllEnabled']) || !empty($in['wechatEnabled']) || !empty($in['wechatWorkEnabled']);
    $wanted = [];
    if ($bundleOn) { $wanted[] = 'wechat_work'; $wanted[] = 'wechat'; }
    if (!empty($in['smsEnabled'])) $wanted[] = 'sms';
    if (!empty($in['privacyCallEnabled'])) $wanted[] = 'privacy_call';
    $usable = array_filter($wanted, fn($c) => channel_opened($g, $c));
    $directAvailable = is_on($g['direct_call_enabled_global'] ?? true);
    if ($wanted && !$usable) json_error('no_available_channel', '所选通知方式平台尚未开通，请到超级管理员后台开通后再试。', 400);
    if (!$wanted && !$directAvailable) json_error('no_available_channel', '平台尚未开通任何通知方式，请联系管理员开通后再试。', 400);

    $created = insert_vehicle($in);
    json_out(['vehicleToken' => $created['vehicleToken'], 'ownerToken' => $created['ownerToken'], 'maskedPlate' => $created['maskedPlate']], 201);
}

function api_public_vehicle(string $token): void
{
    $g = load_global();
    $v = get_vehicle_by_token($token);
    if (!$v) json_error('not_found', '车辆不存在。', 404);
    $channels = available_channels($v, $g);
    $callMode = resolve_call_mode($v, $g, $channels);
    $directCall = null;
    if ($callMode === 'direct') {
        try { $directCall = ['enabled' => true, 'phone' => decrypt_text((string)$v['owner_phone_enc'])]; } catch (Throwable $e) { $directCall = null; }
    }
    json_out([
        'maskedPlate' => $v['plate_number'],
        'plateNumber' => $v['plate_number'],
        'availableChannels' => $channels,
        'callMode' => $callMode,
        'directCall' => $directCall,
        'directCallEnabled' => is_on($g['direct_call_enabled_global'] ?? true),
    ]);
}

function api_notify(string $token): void
{
    $in = request_json();
    $g = load_global();
    $v = get_vehicle_by_token($token);
    if (!$v) json_error('not_found', '车辆不存在。', 404);
    $channel = (string)($in['channel'] ?? '') ?: default_notify_channel($v, $g);
    if (!$channel || !in_array($channel, available_channels($v, $g), true)) {
        json_error('channel_unavailable', '该通知方式尚未配置。', 400);
    }

    $visitorHash = visitor_ip_hash();
    $cutoff = date('Y-m-d H:i:s', time() - NOTIFY_COOLDOWN_SECONDS);
    $recent = DB::one('SELECT id FROM notification_logs WHERE vehicle_id = ? AND visitor_ip_hash = ? AND created_at > ? ORDER BY id DESC LIMIT 1',
        [(int)$v['id'], $visitorHash, $cutoff]);
    if ($recent) json_error('rate_limited', '已提醒车主，请勿频繁操作。', 429);

    $status = 'sent';
    $errorSummary = '';
    $virtualNumber = '';
    try {
        $out = dispatch_notify($v, $g, $channel, $in);
        $virtualNumber = $out['virtualNumber'] ?? '';
        // 一键通知部分通道失败也记入错误日志，便于排查渠道故障
        if (!empty($out['errors'])) {
            $status = 'failed';
            $errorSummary = str_slice('部分通道失败：' . implode('；', $out['errors']));
        }
    } catch (Throwable $e) {
        $status = 'failed';
        $errorSummary = str_slice($e->getMessage());
    }

    if ($channel === 'privacy_call') {
        $callee = '';
        try { if ($v['owner_phone_enc']) $callee = decrypt_text($v['owner_phone_enc']); } catch (Throwable $e) {}
        record_call_log($v, 'privacy_call', [
            'callerNumber' => (string)($in['callerNumber'] ?? ''),
            'calleeNumber' => $callee,
            'virtualNumber' => $virtualNumber,
            'status' => $status === 'sent' ? 'success' : 'failed',
            'errorSummary' => $errorSummary,
            'visitorIpHash' => $visitorHash,
        ]);
    }

    DB::exec('INSERT INTO notification_logs (vehicle_id, channel, status, error_summary, visitor_ip_hash, created_at) VALUES (?,?,?,?,?,?)',
        [(int)$v['id'], $channel, $status, $errorSummary, $visitorHash, db_now()]);

    if ($status === 'failed') json_error('notify_failed', $errorSummary ?: '通知发送失败。', 502);
    json_out(['message' => '已通知车主，请耐心等待。', 'channel' => $channel, 'virtualNumber' => $virtualNumber]);
}

function api_visitor_call_log(string $token): void
{
    $in = request_json();
    $v = get_vehicle_by_token($token);
    if (!$v) json_error('not_found', '车辆不存在。', 404);
    if (empty($v['owner_phone_enc'])) json_error('no_phone', '车主未登记手机号。', 400);
    $callee = decrypt_text($v['owner_phone_enc']);
    record_call_log($v, 'direct_call', [
        'callerNumber' => (string)($in['callerNumber'] ?? ''),
        'calleeNumber' => $callee,
        'status' => 'success',
        'visitorIpHash' => visitor_ip_hash(),
    ]);
    json_message('已记录直拨日志。');
}

/* ============================ 二维码（公开） ============================ */

function api_qr_resolve(string $code): void
{
    $row = DB::one('SELECT * FROM qr_codes WHERE code_token = ?', [$code]);
    if (!$row) json_error('qr_not_found', '二维码无效或已被删除。', 404);
    if ($row['status'] === 'disabled') json_error('qr_disabled', '该二维码已被停用。', 410);
    if ($row['status'] === 'bound' && $row['vehicle_id']) {
        $v = DB::one('SELECT vehicle_token, plate_number FROM vehicles WHERE id = ?', [(int)$row['vehicle_id']]);
        if ($v) json_out(['status' => 'bound', 'vehicleToken' => $v['vehicle_token'], 'maskedPlate' => $v['plate_number']]);
        json_out(['status' => 'unbound']);
    }
    json_out(['status' => 'unbound']);
}

function api_qr_bind(string $code): void
{
    $row = DB::one('SELECT * FROM qr_codes WHERE code_token = ?', [$code]);
    if (!$row) json_error('qr_not_found', '二维码无效或已被删除。', 404);
    if ($row['status'] === 'disabled') json_error('qr_disabled', '该二维码已被停用。', 410);
    if ($row['status'] === 'bound') json_error('qr_bound', '该二维码已绑定车辆，直接扫码即可使用。', 409);

    $in = request_json();
    if ($err = validate_vehicle_input($in, ['requirePhone' => true])) json_error($err['error'], $err['message'], 400);
    $plate = normalize_plate((string)$in['plateNumber']);
    $exists = DB::one('SELECT id, plate_number, owner_pin_hash FROM vehicles WHERE plate_number_hash = ?', [sha256_hex($plate)]);
    if ($exists) {
        json_out([
            'error' => 'plate_exists',
            'message' => '该车牌已录入过，一个车牌只能录入一次。请用「车牌 + 管理密码」找回管理入口。',
            'maskedPlate' => $exists['plate_number'],
            'canRecover' => (bool)$exists['owner_pin_hash'],
        ], 409);
    }

    $g = load_global();
    $bundleOn = !empty($in['notifyAllEnabled']) || !empty($in['wechatEnabled']) || !empty($in['wechatWorkEnabled']);
    $wanted = [];
    if ($bundleOn) { $wanted[] = 'wechat_work'; $wanted[] = 'wechat'; }
    if (!empty($in['smsEnabled'])) $wanted[] = 'sms';
    if (!empty($in['privacyCallEnabled'])) $wanted[] = 'privacy_call';
    $usable = array_filter($wanted, fn($c) => channel_opened($g, $c));
    if ($wanted && !$usable) json_error('no_available_channel', '所选通知方式平台尚未开通，请联系管理员开通后再试。', 400);
    if (!$wanted && !is_on($g['direct_call_enabled_global'] ?? true)) {
        json_error('no_available_channel', '平台尚未开通任何通知方式，请联系管理员开通后再试。', 400);
    }

    $created = insert_vehicle(array_merge($in, ['plateNumber' => $plate]));
    $now = db_now();
    DB::exec("UPDATE qr_codes SET status='bound', vehicle_id=?, bound_at=?, updated_at=? WHERE id=?", [$created['id'], $now, $now, (int)$row['id']]);
    json_out(array_merge(['message' => '绑定成功，此二维码已生效。'], $created), 201);
}

/* ============================ 车主 ============================ */

function api_owner_recover(): void
{
    $in = request_json();
    $plate = normalize_plate((string)($in['plateNumber'] ?? ''));
    if (!is_plate($plate)) json_error('invalid_plate', '请填写有效车牌号。', 400);
    if (empty($in['ownerPin'])) json_error('missing_pin', '请填写管理密码。', 400);

    $key = 'recover:' . visitor_ip_hash() . ':' . sha256_hex($plate);
    if (!rate_limit_check($key, RECOVER_MAX_ATTEMPTS, RECOVER_WINDOW_SECONDS)) {
        json_error('rate_limited', '尝试次数过多，请稍后再试。', 429);
    }
    $v = DB::one('SELECT * FROM vehicles WHERE plate_number_hash = ? AND owner_pin_hash = ?',
        [sha256_hex($plate), sha256_hex('pin:' . $in['ownerPin'])]);
    if (!$v) json_error('not_found', '未找到匹配的车辆，请确认车牌与管理密码。', 404);
    json_out(['ownerToken' => $v['owner_token'], 'maskedPlate' => $v['plate_number']]);
}

function api_owner_vehicle(string $ownerToken): void
{
    $g = load_global();
    $v = get_vehicle_by_owner_token($ownerToken);
    if (!$v) json_error('not_found', '管理链接无效。', 404);
    $logs = DB::all('SELECT channel, status, error_summary, created_at FROM notification_logs WHERE vehicle_id = ? ORDER BY id DESC LIMIT 10', [(int)$v['id']]);
    $phoneMasked = '';
    try { if ($v['owner_phone_enc']) $phoneMasked = mask_phone(decrypt_text($v['owner_phone_enc'])); } catch (Throwable $e) {}
    $openid = '';
    try { if ($v['wechat_openid_enc']) $openid = decrypt_text($v['wechat_openid_enc']); } catch (Throwable $e) {}

    json_out([
        'vehicleToken' => $v['vehicle_token'],
        'maskedPlate' => $v['plate_number'],
        'plateNumber' => $v['plate_number'],
        'notifyAllEnabled' => (bool)((int)$v['wechat_work_enabled'] && (int)$v['wechat_enabled']),
        'wechatWorkEnabled' => (bool)(int)$v['wechat_work_enabled'],
        'wechatEnabled' => (bool)(int)$v['wechat_enabled'],
        'smsEnabled' => (bool)(int)$v['sms_enabled'],
        'privacyCallEnabled' => (bool)(int)$v['privacy_call_enabled'],
        'directCallEnabled' => !(int)$v['privacy_call_enabled'] && channel_opened($g, 'direct_call'),
        'hasPin' => (bool)$v['owner_pin_hash'],
        'ownerPhoneMasked' => $phoneMasked,
        'hasPhone' => !empty($v['owner_phone_enc']),
        'wechatOpenid' => $openid,
        'hasWechatOpenid' => !empty($v['wechat_openid_enc']),
        'platformChannels' => platform_channels($g),
        'channelMeta' => array_map(fn($c) => [
            'key' => $c['key'], 'label' => $c['label'], 'icon' => $c['icon'], 'opened' => channel_opened($g, $c['key']),
        ], channel_groups()),
        'global' => [
            'sms' => channel_opened($g, 'sms'),
            'wechat' => channel_opened($g, 'wechat_work'),
            'wechatNotify' => channel_opened($g, 'wechat'),
            'privacy' => channel_opened($g, 'privacy_call'),
            'directCall' => channel_opened($g, 'direct_call'),
        ],
        'recentNotifications' => array_map(fn($l) => [
            'channel' => $l['channel'], 'status' => $l['status'], 'errorSummary' => $l['error_summary'], 'createdAt' => iso_out($l['created_at']),
        ], $logs),
    ]);
}

function api_owner_patch(string $ownerToken): void
{
    $v = get_vehicle_by_owner_token($ownerToken);
    if (!$v) json_error('not_found', '管理链接无效。', 404);
    $in = request_json();
    if ($err = validate_vehicle_input($in, ['partial' => true, 'hasStoredPhone' => !empty($v['owner_phone_enc'])])) {
        json_error($err['error'], $err['message'], 400);
    }
    $sets = [];
    $binds = [];

    if (array_key_exists('notifyAllEnabled', $in)) {
        $val = !empty($in['notifyAllEnabled']) ? 1 : 0;
        $sets[] = 'wechat_work_enabled = ?'; $binds[] = $val;
        $sets[] = 'wechat_enabled = ?'; $binds[] = $val;
    }
    if (array_key_exists('wechatWorkEnabled', $in)) { $sets[] = 'wechat_work_enabled = ?'; $binds[] = !empty($in['wechatWorkEnabled']) ? 1 : 0; }
    if (array_key_exists('wechatEnabled', $in)) { $sets[] = 'wechat_enabled = ?'; $binds[] = !empty($in['wechatEnabled']) ? 1 : 0; }
    if (array_key_exists('wechatOpenid', $in)) {
        $sets[] = 'wechat_openid_enc = ?';
        $binds[] = !empty($in['wechatOpenid']) ? encrypt_text(trim((string)$in['wechatOpenid'])) : null;
    }
    if (array_key_exists('wechatWorkWebhook', $in)) {
        $sets[] = 'wechat_work_webhook_enc = ?';
        $binds[] = !empty($in['wechatWorkWebhook']) ? encrypt_text((string)$in['wechatWorkWebhook']) : null;
    }
    if (array_key_exists('ownerPhone', $in)) {
        $next = is_phone((string)$in['ownerPhone']) ? normalize_phone((string)$in['ownerPhone']) : '';
        $current = '';
        try { if ($v['owner_phone_enc']) $current = decrypt_text($v['owner_phone_enc']); } catch (Throwable $e) {}
        if ($next !== $current && $current !== '') {
            $g = load_global();
            $pv = is_array($in['phoneVerify'] ?? null) ? $in['phoneVerify'] : [];
            $method = (string)($pv['method'] ?? '');
            if ($method === 'pin') {
                if (!$v['owner_pin_hash']) json_error('pin_unavailable', '未设置管理密码，请改用短信验证码。', 400);
                if (!is_pin((string)($pv['pin'] ?? ''))) json_error('invalid_pin', '请输入正确的管理密码。', 400);
                if (!hash_equals((string)$v['owner_pin_hash'], sha256_hex('pin:' . $pv['pin']))) json_error('pin_mismatch', '管理密码不正确。', 403);
            } elseif ($method === 'sms') {
                if (!is_on($g['sms_enabled_global'] ?? true) || !sms_vendor_ready($g)) {
                    json_error('sms_unavailable', '平台未开通短信通道，请改用管理密码验证。', 400);
                }
                if (!preg_match('/^\d{6}$/', (string)($pv['code'] ?? ''))) json_error('invalid_code', '请输入 6 位短信验证码。', 400);
                $row = DB::one('SELECT id, attempts FROM phone_verify_codes WHERE vehicle_id = ? AND code_hash = ? AND consumed_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
                    [(int)$v['id'], sha256_hex('code:' . $pv['code']), db_now()]);
                if (!$row) json_error('code_invalid', '验证码无效或已过期。', 403);
                if ((int)$row['attempts'] >= 5) json_error('code_locked', '验证码尝试次数过多，请重新获取。', 429);
                DB::exec('UPDATE phone_verify_codes SET consumed_at = ? WHERE id = ?', [db_now(), (int)$row['id']]);
            } else {
                json_error('verification_required', '更换手机号需要验证：请提交短信验证码（phoneVerify.method=sms）或管理密码（phoneVerify.method=pin）。', 403);
            }
        }
        $sets[] = 'owner_phone_enc = ?';
        $binds[] = $next !== '' ? encrypt_text($next) : null;
    }
    if (array_key_exists('smsEnabled', $in)) { $sets[] = 'sms_enabled = ?'; $binds[] = !empty($in['smsEnabled']) ? 1 : 0; }
    if (array_key_exists('privacyCallEnabled', $in)) { $sets[] = 'privacy_call_enabled = ?'; $binds[] = !empty($in['privacyCallEnabled']) ? 1 : 0; }
    if (!empty($in['ownerPin'])) { $sets[] = 'owner_pin_hash = ?'; $binds[] = sha256_hex('pin:' . $in['ownerPin']); }

    if (!$sets) json_message('没有需要更新的字段。');
    $sets[] = 'updated_at = ?';
    $binds[] = db_now();
    $binds[] = (int)$v['id'];
    DB::exec('UPDATE vehicles SET ' . implode(', ', $sets) . ' WHERE id = ?', $binds);
    json_message('配置已更新。');
}

function api_owner_delete(string $ownerToken): void
{
    $v = get_vehicle_by_owner_token($ownerToken);
    if (!$v) json_error('not_found', '管理链接无效。', 404);
    DB::exec('DELETE FROM notification_logs WHERE vehicle_id = ?', [(int)$v['id']]);
    DB::exec('DELETE FROM call_logs WHERE vehicle_id = ?', [(int)$v['id']]);
    DB::exec('DELETE FROM vehicles WHERE id = ?', [(int)$v['id']]);
    release_qr_for_vehicle((int)$v['id']);
    json_message('绑定已删除。');
}

function api_owner_regenerate_token(string $ownerToken): void
{
    $v = get_vehicle_by_owner_token($ownerToken);
    if (!$v) json_error('not_found', '管理链接无效。', 404);
    $token = new_token('veh_');
    DB::exec('UPDATE vehicles SET vehicle_token = ?, updated_at = ? WHERE id = ?', [$token, db_now(), (int)$v['id']]);
    json_out(['vehicleToken' => $token, 'maskedPlate' => $v['plate_number']]);
}

function api_owner_send_code(string $ownerToken): void
{
    $g = load_global();
    $v = get_vehicle_by_owner_token($ownerToken);
    if (!$v) json_error('not_found', '管理链接无效。', 404);
    if (empty($v['owner_phone_enc'])) json_error('no_phone', '当前未登记手机号，无需验证可直接设置。', 400);
    if (!is_on($g['sms_enabled_global'] ?? true) || !sms_vendor_ready($g)) {
        json_error('sms_unavailable', '平台未开通短信通道，请使用管理密码验证。', 400);
    }
    $recent = DB::one('SELECT id FROM phone_verify_codes WHERE vehicle_id = ? AND created_at > ? ORDER BY id DESC LIMIT 1',
        [(int)$v['id'], date('Y-m-d H:i:s', time() - 60)]);
    if ($recent) json_error('too_soon', '验证码已发送，请稍候再试（60 秒）。', 429);

    $code = (string)random_int(100000, 999999);
    DB::exec('INSERT INTO phone_verify_codes (vehicle_id, code_hash, expires_at, attempts, created_at) VALUES (?,?,?,0,?)',
        [(int)$v['id'], sha256_hex('code:' . $code), date('Y-m-d H:i:s', time() + 600), db_now()]);
    $phone = decrypt_text($v['owner_phone_enc']);
    try {
        send_raw_sms($g, $phone, '【扫码挪车】您正在更换绑定手机号，验证码 ' . $code . '（10 分钟内有效）。');
    } catch (Throwable $e) {
        json_error('sms_failed', '验证码短信发送失败：' . $e->getMessage(), 502);
    }
    json_out(['message' => '验证码已发送至当前手机号（' . mask_phone($phone) . '）。', 'expiresIn' => 600]);
}
