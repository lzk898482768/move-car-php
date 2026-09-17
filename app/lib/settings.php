<?php
/**
 * 全局配置 + 通知通道状态模型（与原 Worker 版行为一致）
 */
declare(strict_types=1);

const NOTIFY_COOLDOWN_SECONDS = 120;
const RECOVER_MAX_ATTEMPTS = 5;
const RECOVER_WINDOW_SECONDS = 600;
const ADMIN_SESSION_DAYS = 7;
const CALL_LOG_LIMIT = 200;

function sms_vendor_options(): array
{
    return [
        ['value' => 'tencent', 'label' => '腾讯云短信'],
        ['value' => 'aliyun', 'label' => '阿里云短信'],
        ['value' => 'custom', 'label' => '自定义短信 Webhook'],
    ];
}

function privacy_vendor_options(): array
{
    return [
        ['value' => 'custom', 'label' => '自定义隐私号 Webhook'],
        ['value' => 'tencent', 'label' => '腾讯云号码保护'],
        ['value' => 'aliyun', 'label' => '阿里云号码隐私保护'],
    ];
}

function channel_groups(): array
{
    return [
        ['key' => 'wechat_work', 'label' => '企业微信机器人', 'icon' => '💬'],
        ['key' => 'wechat', 'label' => '微信通知（公众号模板消息）', 'icon' => '📨'],
        ['key' => 'sms', 'label' => '短信通知', 'icon' => '📱'],
        ['key' => 'privacy_call', 'label' => '隐私拨号', 'icon' => '☎️'],
        ['key' => 'direct_call', 'label' => '直拨（默认）', 'icon' => '📞'],
    ];
}

function ad_positions(): array
{
    return [
        ['key' => 'home_top', 'label' => '首页 · 顶部横幅'],
        ['key' => 'move_top', 'label' => '访客页 · 车牌卡下方'],
        ['key' => 'move_bottom', 'label' => '访客页 · 底部推荐位'],
        ['key' => 'owner_top', 'label' => '车主后台 · 顶部'],
    ];
}

function ad_position_keys(): array
{
    return array_column(ad_positions(), 'key');
}

/** 全局配置项元数据（secret=true 的值加密存储） */
function global_settings_meta(): array
{
    return [
        ['key' => 'wechat_enabled_global', 'secret' => false, 'label' => '启用企业微信通知', 'def' => 'true', 'group' => 'wechat_work', 'type' => 'bool'],
        ['key' => 'wechat_work_webhook', 'secret' => true, 'label' => '企业微信默认 Webhook', 'group' => 'wechat_work'],

        ['key' => 'wechat_notify_enabled_global', 'secret' => false, 'label' => '启用微信通知', 'def' => 'true', 'group' => 'wechat', 'type' => 'bool'],
        ['key' => 'wechat_mp_appid', 'secret' => false, 'label' => '公众号 AppID', 'group' => 'wechat'],
        ['key' => 'wechat_mp_secret', 'secret' => true, 'label' => '公众号 AppSecret', 'group' => 'wechat'],
        ['key' => 'wechat_mp_template_id', 'secret' => false, 'label' => '模板消息 ID（模板ID）', 'group' => 'wechat'],
        ['key' => 'wechat_mp_openid', 'secret' => false, 'label' => '默认接收 OpenID（车主未单独填写时使用）', 'group' => 'wechat'],
        ['key' => 'wechat_mp_template_title', 'secret' => false, 'label' => '模板首行文案（可选）', 'def' => '您的爱车收到挪车提醒', 'group' => 'wechat'],
        ['key' => 'wechat_mp_template_remark', 'secret' => false, 'label' => '模板备注文案（可选）', 'def' => '请尽快前往挪车，感谢配合。', 'group' => 'wechat'],
        ['key' => 'wechat_mp_url', 'secret' => false, 'label' => '模板点击跳转链接（可选）', 'group' => 'wechat'],

        ['key' => 'sms_enabled_global', 'secret' => false, 'label' => '启用短信通知', 'def' => 'true', 'group' => 'sms', 'type' => 'bool'],
        ['key' => 'sms_vendor', 'secret' => false, 'label' => '短信服务商', 'def' => 'tencent', 'group' => 'sms', 'type' => 'select', 'options' => sms_vendor_options()],
        ['key' => 'tencent_secret_id', 'secret' => true, 'label' => '腾讯云 SecretId', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'tencent_secret_key', 'secret' => true, 'label' => '腾讯云 SecretKey', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'tencent_sms_app_id', 'secret' => false, 'label' => '短信 SmsSdkAppId', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'tencent_sms_sign_name', 'secret' => false, 'label' => '短信签名', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'tencent_sms_template_id', 'secret' => false, 'label' => '短信模板 ID', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'tencent_sms_region', 'secret' => false, 'label' => '短信地域', 'def' => 'ap-guangzhou', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['tencent']]],
        ['key' => 'aliyun_access_key_id', 'secret' => true, 'label' => '阿里云 AccessKeyId', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['aliyun']]],
        ['key' => 'aliyun_access_key_secret', 'secret' => true, 'label' => '阿里云 AccessKeySecret', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['aliyun']]],
        ['key' => 'aliyun_sms_sign_name', 'secret' => false, 'label' => '短信签名', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['aliyun']]],
        ['key' => 'aliyun_sms_template_code', 'secret' => false, 'label' => '短信模板 CODE', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['aliyun']]],
        ['key' => 'aliyun_sms_region', 'secret' => false, 'label' => '短信地域', 'def' => 'cn-hangzhou', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['aliyun']]],
        ['key' => 'sms_custom_webhook', 'secret' => true, 'label' => '短信 Webhook 地址', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['custom']]],
        ['key' => 'sms_custom_token', 'secret' => true, 'label' => '短信 Webhook Token（可选）', 'group' => 'sms', 'showIf' => ['key' => 'sms_vendor', 'in' => ['custom']]],

        ['key' => 'privacy_enabled_global', 'secret' => false, 'label' => '启用隐私号呼叫', 'def' => 'true', 'group' => 'privacy_call', 'type' => 'bool'],
        ['key' => 'privacy_vendor', 'secret' => false, 'label' => '隐私号服务商', 'def' => 'custom', 'group' => 'privacy_call', 'type' => 'select', 'options' => privacy_vendor_options()],
        ['key' => 'privacy_call_webhook_url', 'secret' => true, 'label' => '隐私号 Webhook 地址', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['custom']]],
        ['key' => 'privacy_call_webhook_token', 'secret' => true, 'label' => '隐私号 Webhook Token（可选）', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['custom']]],
        ['key' => 'privacy_tencent_action', 'secret' => false, 'label' => '腾讯云号码保护 Action', 'def' => 'BindNumber', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['tencent']]],
        ['key' => 'privacy_tencent_version', 'secret' => false, 'label' => '腾讯云号码保护 API 版本', 'def' => '2021-02-22', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['tencent']]],
        ['key' => 'privacy_tencent_pool_key', 'secret' => false, 'label' => '号码池 Key（PoolKey，可选）', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['tencent']]],
        ['key' => 'privacy_aliyun_action', 'secret' => false, 'label' => '阿里云号码保护 Action', 'def' => 'BindAxb', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['aliyun']]],
        ['key' => 'privacy_aliyun_pool_key', 'secret' => false, 'label' => '号码池 Key（PoolKey，可选）', 'group' => 'privacy_call', 'showIf' => ['key' => 'privacy_vendor', 'in' => ['aliyun']]],

        ['key' => 'direct_call_enabled_global', 'secret' => false, 'label' => '隐私号未开通时允许直拨', 'def' => 'true', 'group' => 'direct_call', 'type' => 'bool'],

        ['key' => 'site_name', 'secret' => false, 'label' => '站点名称', 'def' => '扫码挪车', 'group' => 'other'],
        ['key' => 'default_phone_country_code', 'secret' => false, 'label' => '默认手机区号', 'def' => '+86', 'group' => 'other'],
    ];
}

function setting_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (global_settings_meta() as $s) $map[$s['key']] = $s;
    }
    return $map;
}

/** 读取全局配置（带文件缓存，保存后自动失效） */
function load_global(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;

    $cacheFile = MC_ROOT . '/storage/cache/global.php';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 30) {
        $cached = @include $cacheFile;
        if (is_array($cached)) return $mem = $cached;
    }

    $meta = global_settings_meta();
    $out = [];
    foreach ($meta as $s) {
        if (array_key_exists('def', $s)) $out[$s['key']] = $s['def'];
        else $out[$s['key']] = '';
    }
    try {
        $rows = DB::all('SELECT k, v, is_secret FROM global_config');
        foreach ($rows as $r) {
            $v = (string)($r['v'] ?? '');
            if ((int)($r['is_secret'] ?? 0) === 1 && $v !== '') {
                try { $v = decrypt_text($v); } catch (Throwable $e) { $v = ''; }
            }
            $out[$r['k']] = $v;
        }
    } catch (Throwable $e) {
        // 表未建好时返回默认值
    }

    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0755, true);
    @file_put_contents($cacheFile, '<?php return ' . var_export($out, true) . ';');
    return $mem = $out;
}

function clear_global_cache(): void
{
    $cacheFile = MC_ROOT . '/storage/cache/global.php';
    if (is_file($cacheFile)) @unlink($cacheFile);
}

/** 保存全局配置（只接受已声明的 key，secret 值加密） */
function save_global(array $input): array
{
    $map = setting_map();
    $updated = [];
    $now = db_now();
    foreach ($input as $key => $value) {
        if (!isset($map[$key])) continue;
        $meta = $map[$key];
        $str = $value === null || $value === false ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
        $stored = !empty($meta['secret']) ? encrypt_text($str) : $str;
        DB::exec(
            DB::driver() === 'mysql'
                ? 'INSERT INTO global_config (k, v, is_secret, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), is_secret = VALUES(is_secret), updated_at = VALUES(updated_at)'
                : 'INSERT OR REPLACE INTO global_config (k, v, is_secret, updated_at) VALUES (?, ?, ?, ?)',
            [$key, $stored, !empty($meta['secret']) ? 1 : 0, $now]
        );
        $updated[] = $key;
    }
    clear_global_cache();
    return $updated;
}

/* ---------------- 通道状态模型 ---------------- */

function is_on($value, bool $def = true): bool
{
    $v = strtolower(trim((string)($value ?? ($def ? 'true' : 'false'))));
    return !in_array($v, ['false', '0', 'off', 'no', ''], true);
}

function sms_vendor_ready(array $g): bool
{
    $v = $g['sms_vendor'] ?? 'tencent';
    if ($v === 'tencent') {
        return !empty($g['tencent_secret_id']) && !empty($g['tencent_secret_key']) && !empty($g['tencent_sms_app_id'])
            && !empty($g['tencent_sms_sign_name']) && !empty($g['tencent_sms_template_id']);
    }
    if ($v === 'aliyun') {
        return !empty($g['aliyun_access_key_id']) && !empty($g['aliyun_access_key_secret'])
            && !empty($g['aliyun_sms_sign_name']) && !empty($g['aliyun_sms_template_code']);
    }
    if ($v === 'custom') return !empty($g['sms_custom_webhook']);
    return false;
}

function privacy_vendor_ready(array $g): bool
{
    $v = $g['privacy_vendor'] ?? 'custom';
    if ($v === 'custom') return !empty($g['privacy_call_webhook_url']);
    if ($v === 'tencent') return !empty($g['tencent_secret_id']) && !empty($g['tencent_secret_key']);
    if ($v === 'aliyun') return !empty($g['aliyun_access_key_id']) && !empty($g['aliyun_access_key_secret']);
    return false;
}

function wechat_mp_ready(array $g): bool
{
    return !empty($g['wechat_mp_appid']) && !empty($g['wechat_mp_secret']) && !empty($g['wechat_mp_template_id']);
}

function channel_enabled(array $g, string $ch): bool
{
    return match ($ch) {
        'wechat_work' => is_on($g['wechat_enabled_global'] ?? true),
        'wechat' => is_on($g['wechat_notify_enabled_global'] ?? true),
        'sms' => is_on($g['sms_enabled_global'] ?? true),
        'privacy_call' => is_on($g['privacy_enabled_global'] ?? true),
        'direct_call' => is_on($g['direct_call_enabled_global'] ?? true),
        default => false,
    };
}

function channel_missing_params(array $g, string $ch): array
{
    $miss = [];
    if ($ch === 'wechat_work') {
        if (empty($g['wechat_work_webhook'])) $miss[] = '企业微信默认 Webhook';
    } elseif ($ch === 'wechat') {
        if (empty($g['wechat_mp_appid'])) $miss[] = '公众号 AppID';
        if (empty($g['wechat_mp_secret'])) $miss[] = '公众号 AppSecret';
        if (empty($g['wechat_mp_template_id'])) $miss[] = '模板消息 ID';
    } elseif ($ch === 'sms') {
        $v = $g['sms_vendor'] ?? 'tencent';
        if ($v === 'tencent') {
            if (empty($g['tencent_secret_id'])) $miss[] = '腾讯云 SecretId';
            if (empty($g['tencent_secret_key'])) $miss[] = '腾讯云 SecretKey';
            if (empty($g['tencent_sms_app_id'])) $miss[] = '短信 SmsSdkAppId';
            if (empty($g['tencent_sms_sign_name'])) $miss[] = '短信签名';
            if (empty($g['tencent_sms_template_id'])) $miss[] = '短信模板 ID';
        } elseif ($v === 'aliyun') {
            if (empty($g['aliyun_access_key_id'])) $miss[] = '阿里云 AccessKeyId';
            if (empty($g['aliyun_access_key_secret'])) $miss[] = '阿里云 AccessKeySecret';
            if (empty($g['aliyun_sms_sign_name'])) $miss[] = '短信签名';
            if (empty($g['aliyun_sms_template_code'])) $miss[] = '短信模板 CODE';
        } elseif ($v === 'custom') {
            if (empty($g['sms_custom_webhook'])) $miss[] = '短信 Webhook 地址';
        } else {
            $miss[] = '短信服务商';
        }
    } elseif ($ch === 'privacy_call') {
        $v = $g['privacy_vendor'] ?? 'custom';
        if ($v === 'custom') {
            if (empty($g['privacy_call_webhook_url'])) $miss[] = '隐私号 Webhook 地址';
        } elseif ($v === 'tencent') {
            if (empty($g['tencent_secret_id'])) $miss[] = '腾讯云 SecretId';
            if (empty($g['tencent_secret_key'])) $miss[] = '腾讯云 SecretKey';
        } elseif ($v === 'aliyun') {
            if (empty($g['aliyun_access_key_id'])) $miss[] = '阿里云 AccessKeyId';
            if (empty($g['aliyun_access_key_secret'])) $miss[] = '阿里云 AccessKeySecret';
        } else {
            $miss[] = '隐私号服务商';
        }
    }
    return $miss;
}

function channel_opened(array $g, string $ch): bool
{
    return channel_enabled($g, $ch) && channel_missing_params($g, $ch) === [];
}

function platform_channels(array $g): array
{
    return array_values(array_filter(['wechat_work', 'wechat', 'sms', 'privacy_call'], fn($c) => channel_opened($g, $c)));
}

function available_channels(array $vehicle, array $g): array
{
    $list = [];
    $workOn = channel_opened($g, 'wechat_work') && !empty($vehicle['wechat_work_enabled']);
    $wechatOn = channel_opened($g, 'wechat') && !empty($vehicle['wechat_enabled']);
    if ($workOn && $wechatOn) $list[] = 'notify_all';
    else {
        if ($workOn) $list[] = 'wechat_work';
        if ($wechatOn) $list[] = 'wechat';
    }
    if (channel_opened($g, 'sms') && !empty($vehicle['sms_enabled']) && !empty($vehicle['owner_phone_enc'])) $list[] = 'sms';
    if (channel_opened($g, 'privacy_call') && !empty($vehicle['privacy_call_enabled']) && !empty($vehicle['owner_phone_enc'])) $list[] = 'privacy_call';
    return $list;
}

function resolve_call_mode(array $vehicle, array $g, ?array $channels = null): string
{
    $channels = $channels ?? available_channels($vehicle, $g);
    if (in_array('privacy_call', $channels, true)) return 'privacy';
    if (is_on($g['direct_call_enabled_global'] ?? true) && !empty($vehicle['owner_phone_enc'])) return 'direct';
    return 'none';
}

function default_notify_channel(array $vehicle, array $g): string
{
    $channels = available_channels($vehicle, $g);
    foreach (['notify_all', 'wechat_work', 'wechat', 'sms', 'privacy_call'] as $c) {
        if (in_array($c, $channels, true)) return $c;
    }
    return '';
}
