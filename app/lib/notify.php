<?php
/**
 * 通知通道：企业微信机器人 / 公众号模板消息 / 短信（腾讯·阿里·自定义）/ 隐私拨号（自定义·腾讯·阿里）
 */
declare(strict_types=1);

function notify_error(string $m): never
{
    throw new RuntimeException($m);
}

/* ---------------- 企业微信机器人 ---------------- */

function send_wechat_work(array $vehicle, array $g): void
{
    $webhook = !empty($vehicle['wechat_work_webhook_enc']) ? decrypt_text($vehicle['wechat_work_webhook_enc']) : '';
    if (!$webhook) $webhook = (string)($g['wechat_work_webhook'] ?? '');
    if (!$webhook) notify_error('企业微信未配置');
    $body = ['msgtype' => 'text', 'text' => ['content' => '扫码挪车提醒：车辆 ' . $vehicle['plate_number'] . ' 收到挪车提醒，请及时处理。']];
    [$code, $data] = http_json('POST', $webhook, $body);
    if ($code < 200 || $code >= 300) notify_error('企业微信通知失败：HTTP ' . $code);
    if (!empty($data['errcode']) && (int)$data['errcode'] !== 0) {
        notify_error('企业微信通知失败：' . ($data['errmsg'] ?? $data['errcode']));
    }
}

/* ---------------- 微信公众号模板消息 ---------------- */

function wechat_access_token(array $g): string
{
    if (!wechat_mp_ready($g)) notify_error('微信通知（公众号模板消息）未配置，请到超管后台补全 AppID / AppSecret / 模板ID');
    $file = MC_ROOT . '/storage/cache/wechat_token.php';
    if (is_file($file)) {
        $cached = @include $file;
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 300) return (string)$cached['token'];
    }
    $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . rawurlencode($g['wechat_mp_appid'])
        . '&secret=' . rawurlencode($g['wechat_mp_secret']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true) ?: [];
    if (empty($data['access_token'])) notify_error('获取微信 access_token 失败：' . ($data['errmsg'] ?? '未知错误'));
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, '<?php return ' . var_export([
        'token' => $data['access_token'],
        'expires_at' => time() + (int)($data['expires_in'] ?? 7200),
    ], true) . ';');
    return (string)$data['access_token'];
}

function send_wechat_template(array $vehicle, array $g): void
{
    $openid = '';
    if (!empty($vehicle['wechat_openid_enc'])) {
        try { $openid = decrypt_text($vehicle['wechat_openid_enc']); } catch (Throwable $e) { $openid = ''; }
    }
    if (!$openid) $openid = (string)($g['wechat_mp_openid'] ?? '');
    if (!$openid) notify_error('微信通知缺少接收 OpenID（车主未填写且超管未配置默认 OpenID）');

    $token = wechat_access_token($g);
    $payload = [
        'touser' => $openid,
        'template_id' => $g['wechat_mp_template_id'],
        'data' => [
            'first' => ['value' => $g['wechat_mp_template_title'] ?: '您的爱车收到挪车提醒'],
            'keyword1' => ['value' => $vehicle['plate_number']],
            'keyword2' => ['value' => date('Y年m月d日 H:i')],
            'remark' => ['value' => $g['wechat_mp_template_remark'] ?: '请尽快前往挪车，感谢配合。'],
        ],
    ];
    if (!empty($g['wechat_mp_url'])) $payload['url'] = $g['wechat_mp_url'];

    $url = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . rawurlencode($token);
    [$code, $out] = http_json('POST', $url, $payload);
    if (!empty($out['errcode']) && (int)$out['errcode'] !== 0) {
        notify_error('微信模板消息发送失败：' . ($out['errmsg'] ?? $out['errcode']));
    }
}

/* ---------------- 腾讯云 API（TC3-HMAC-SHA256） ---------------- */

function tencent_api(array $g, string $service, string $host, string $version, string $action, string $region, array $payload): array
{
    $secretId = (string)($g['tencent_secret_id'] ?? '');
    $secretKey = (string)($g['tencent_secret_key'] ?? '');
    if (!$secretId || !$secretKey) notify_error('缺少腾讯云密钥');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $canonical = "POST\n/\n\ncontent-type:application/json\nhost:{$host}\n\ncontent-type;host\n" . hash('sha256', $body);
    $scope = "{$date}/{$service}/tc3_request";
    $toSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('SHA256', $date, 'TC3' . $secretKey, true);
    $kService = hash_hmac('SHA256', $service, $kDate, true);
    $kSigning = hash_hmac('SHA256', 'tc3_request', $kService, true);
    $signature = hash_hmac('SHA256', $toSign, $kSigning);
    $auth = "TC3-HMAC-SHA256 Credential={$secretId}/{$scope}, SignedHeaders=content-type;host, Signature={$signature}";
    [$code, $resp] = http_post_raw('https://' . $host, $body, [
        'Content-Type: application/json',
        'Host: ' . $host,
        'Authorization: ' . $auth,
        'X-TC-Action: ' . $action,
        'X-TC-Version: ' . $version,
        'X-TC-Timestamp: ' . $timestamp,
        'X-TC-Region: ' . $region,
    ]);
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['raw' => $resp];
}

/* ---------------- 阿里云 API（RPC + HMAC-SHA1） ---------------- */

function aliyun_api(array $g, string $host, array $params): array
{
    $ak = (string)($g['aliyun_access_key_id'] ?? '');
    $sk = (string)($g['aliyun_access_key_secret'] ?? '');
    if (!$ak || !$sk) notify_error('缺少阿里云密钥');
    $common = [
        'AccessKeyId' => $ak,
        'Format' => 'JSON',
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureNonce' => bin2hex(random_bytes(8)),
        'SignatureVersion' => '1.0',
        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'Version' => '2017-05-25',
    ];
    $all = array_merge($common, $params);
    ksort($all);
    $pairs = [];
    foreach ($all as $k => $v) {
        $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    $canonical = implode('&', $pairs);
    $canonical = str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $canonical);
    $toSign = 'POST&%2F&' . rawurlencode($canonical);
    $sig = base64_encode(hash_hmac('sha1', $toSign, $sk . '&', true));
    $body = $canonical . '&Signature=' . rawurlencode($sig);
    [$code, $resp] = http_post_raw('https://' . $host . '/', $body, ['Content-Type: application/x-www-form-urlencoded']);
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['raw' => $resp];
}

/* ---------------- 短信 ---------------- */

function send_sms(array $vehicle, array $g): void
{
    if (empty($vehicle['owner_phone_enc'])) notify_error('车主未登记手机号');
    $phone = to_e164(decrypt_text($vehicle['owner_phone_enc']), $g['default_phone_country_code'] ?? '+86');
    $vendor = (string)($g['sms_vendor'] ?? 'tencent');
    if ($vendor === 'tencent') {
        if (!sms_vendor_ready($g)) notify_error('短信通道未配置（缺少腾讯云短信参数）');
        $result = tencent_api($g, 'sms', 'sms.tencentcloudapi.com', '2021-01-11', 'SendSms', (string)($g['tencent_sms_region'] ?: 'ap-guangzhou'), [
            'SmsSdkAppId' => $g['tencent_sms_app_id'],
            'SignName' => $g['tencent_sms_sign_name'],
            'TemplateId' => $g['tencent_sms_template_id'],
            'TemplateParamSet' => [$vehicle['plate_number']],
            'PhoneNumberSet' => [$phone],
        ]);
        $status = $result['SendStatusSet'][0] ?? null;
        if ($status && ($status['Code'] ?? '') !== 'Ok') notify_error($status['Message'] ?? $status['Code']);
        return;
    }
    if ($vendor === 'aliyun') {
        if (!sms_vendor_ready($g)) notify_error('短信通道未配置（缺少阿里云短信参数）');
        $result = aliyun_api($g, 'dysmsapi.aliyuncs.com', [
            'Action' => 'SendSms',
            'RegionId' => (string)($g['aliyun_sms_region'] ?: 'cn-hangzhou'),
            'PhoneNumbers' => ltrim($phone, '+'),
            'SignName' => $g['aliyun_sms_sign_name'],
            'TemplateCode' => $g['aliyun_sms_template_code'],
            'TemplateParam' => json_encode(['code' => $vehicle['plate_number'], 'plate' => $vehicle['plate_number']], JSON_UNESCAPED_UNICODE),
        ]);
        if (!empty($result['Code']) && $result['Code'] !== 'OK') notify_error($result['Message'] ?? $result['Code']);
        return;
    }
    // 自定义 Webhook
    if (empty($g['sms_custom_webhook'])) notify_error('短信通道未配置（缺少自定义短信 Webhook）');
    $headers = ['Content-Type: application/json'];
    if (!empty($g['sms_custom_token'])) $headers[] = 'Authorization: Bearer ' . $g['sms_custom_token'];
    [$code, $resp] = http_post_raw($g['sms_custom_webhook'], json_encode([
        'phone' => $phone,
        'plateNumber' => $vehicle['plate_number'],
        'maskedPlate' => $vehicle['plate_number'],
        'templateVar' => $vehicle['plate_number'],
        'purpose' => 'move_car_notify',
        'vendor' => 'custom',
    ], JSON_UNESCAPED_UNICODE), $headers);
    if ($code < 200 || $code >= 300) notify_error('自定义短信 Webhook 失败：HTTP ' . $code);
    $data = json_decode($resp, true);
    if (is_array($data) && !empty($data['error'])) notify_error('自定义短信 Webhook 失败：' . $data['error']);
}

/* ---------------- 隐私拨号 ---------------- */

function pick_virtual_number($data): string
{
    if (!is_array($data)) return '';
    $keys = ['virtualNumber', 'virtual_number', 'bindNumber', 'bind_number', 'middleNumber', 'xNumber', 'x_number', 'numberX', 'NumberX', 'secretNo', 'SecretNo', 'number', 'Number'];
    foreach ($keys as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) return trim($data[$k]);
    }
    return '';
}

function start_privacy_call(array $vehicle, array $g, array $input = []): string
{
    if (empty($vehicle['owner_phone_enc'])) notify_error('车主未登记手机号');
    $callee = decrypt_text($vehicle['owner_phone_enc']);
    $caller = normalize_phone((string)($input['callerNumber'] ?? ''));
    $vendor = (string)($g['privacy_vendor'] ?? 'custom');
    if (!privacy_vendor_ready($g)) notify_error('隐私号通道未配置（服务商：' . $vendor . '，请到超级管理员后台补全参数）');

    if ($vendor === 'tencent') {
        $payload = ['PhoneA' => to_e164($caller), 'PhoneB' => to_e164($callee)];
        if (!empty($g['privacy_tencent_pool_key'])) $payload['PoolKey'] = $g['privacy_tencent_pool_key'];
        $data = tencent_api($g, 'ccc', 'ccc.tencentcloudapi.com', (string)($g['privacy_tencent_version'] ?: '2021-02-22'), (string)($g['privacy_tencent_action'] ?: 'BindNumber'), 'ap-guangzhou', $payload);
        return pick_virtual_number($data['Response'] ?? $data);
    }
    if ($vendor === 'aliyun') {
        $params = ['Action' => (string)($g['privacy_aliyun_action'] ?: 'BindAxb'), 'PhoneA' => $caller, 'PhoneB' => $callee];
        if (!empty($g['privacy_aliyun_pool_key'])) $params['PoolKey'] = $g['privacy_aliyun_pool_key'];
        $data = aliyun_api($g, 'dyplsapi.aliyuncs.com', $params);
        return pick_virtual_number($data);
    }
    // 自定义 Webhook
    $headers = ['Content-Type: application/json'];
    if (!empty($g['privacy_call_webhook_token'])) $headers[] = 'Authorization: Bearer ' . $g['privacy_call_webhook_token'];
    [$code, $resp] = http_post_raw((string)$g['privacy_call_webhook_url'], json_encode([
        'phone' => $callee,
        'callerNumber' => $caller,
        'plateNumber' => $vehicle['plate_number'],
        'maskedPlate' => $vehicle['plate_number'],
        'vendor' => 'custom',
        'purpose' => 'move_car_privacy_call',
    ], JSON_UNESCAPED_UNICODE), $headers);
    if ($code < 200 || $code >= 300) notify_error('隐私号 Webhook 失败：HTTP ' . $code);
    $data = json_decode($resp, true);
    return pick_virtual_number($data);
}

/* ---------------- 统一分发 ---------------- */

function dispatch_notify(array $vehicle, array $g, string $channel, array $input = []): array
{
    if ($channel === 'notify_all') {
        $errors = [];
        $ok = 0;
        try { send_wechat_work($vehicle, $g); $ok++; } catch (Throwable $e) { $errors[] = '企微：' . $e->getMessage(); }
        try { send_wechat_template($vehicle, $g); $ok++; } catch (Throwable $e) { $errors[] = '公众号：' . $e->getMessage(); }
        if ($ok === 0) notify_error($errors[0] ?? '一键通知发送失败');
        return ['sent' => $ok, 'errors' => $errors];
    }
    if ($channel === 'wechat') send_wechat_template($vehicle, $g);
    elseif ($channel === 'wechat_work') send_wechat_work($vehicle, $g);
    elseif ($channel === 'sms') send_sms($vehicle, $g);
    elseif ($channel === 'privacy_call') return ['virtualNumber' => start_privacy_call($vehicle, $g, $input)];
    else notify_error('未知通知渠道');
    return [];
}
