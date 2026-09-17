<?php
/**
 * API 路由表（契约与原 Cloudflare 版一致）
 */
declare(strict_types=1);

function route_api(string $method, string $path): void
{
    $p = '([^/]+)';
    $routes = [
        // 健康检查 / 公开
        ['GET', '#^/api/health$#', 'api_health'],
        ['GET', '#^/api/channels$#', 'api_public_channels'],
        ['GET', '#^/api/ads$#', 'api_public_ads'],

        // 车辆
        ['POST', '#^/api/vehicles$#', 'api_create_vehicle'],
        ['GET', "#^/api/vehicles/{$p}/public$#", 'api_public_vehicle'],
        ['POST', "#^/api/vehicles/{$p}/notify$#", 'api_notify'],
        ['POST', "#^/api/vehicles/{$p}/call-log$#", 'api_visitor_call_log'],

        // 二维码（绑定必须排在解析之前）
        ['POST', "#^/api/qr/{$p}/bind$#", 'api_qr_bind'],
        ['GET', "#^/api/qr/{$p}$#", 'api_qr_resolve'],

        // 车主
        ['POST', '#^/api/owner/recover$#', 'api_owner_recover'],
        ['GET', "#^/api/owner/{$p}/vehicle$#", 'api_owner_vehicle'],
        ['PATCH', "#^/api/owner/{$p}/vehicle$#", 'api_owner_patch'],
        ['PUT', "#^/api/owner/{$p}/vehicle$#", 'api_owner_patch'],
        ['DELETE', "#^/api/owner/{$p}/vehicle$#", 'api_owner_delete'],
        ['POST', "#^/api/owner/{$p}/vehicle/regenerate-token$#", 'api_owner_regenerate_token'],
        ['POST', "#^/api/owner/{$p}/phone/send-code$#", 'api_owner_send_code'],

        // 管理员（未登录接口）
        ['POST', '#^/api/admin/login$#', 'api_admin_login'],

        // 管理员（需登录）
        ['POST', '#^/api/admin/logout$#', 'api_admin_logout', true],
        ['GET', '#^/api/admin/config$#', 'api_admin_config_get', true],
        ['PUT', '#^/api/admin/config$#', 'api_admin_config_put', true],
        ['GET', '#^/api/admin/overview$#', 'api_admin_overview', true],
        ['GET', '#^/api/admin/accounts$#', 'api_admin_accounts', true],
        ['POST', '#^/api/admin/accounts$#', 'api_admin_account_create', true],
        ['DELETE', "#^/api/admin/accounts/{$p}$#", 'api_admin_account_delete', true],
        ['GET', '#^/api/admin/lookup$#', 'api_admin_lookup', true],
        ['POST', '#^/api/admin/password$#', 'api_admin_password', true],

        // 挪车记录（访客发起的挪车通知）
        ['GET', '#^/api/admin/notifications$#', 'api_admin_notifications', true],
        ['GET', '#^/api/admin/notifications/export$#', 'api_admin_notifications_export', true],

        // 广告位
        ['GET', '#^/api/admin/ads$#', 'api_admin_ads_list', true],
        ['POST', '#^/api/admin/ads$#', 'api_admin_ads_create', true],
        ['PUT', '#^/api/admin/ads/(\d+)$#', 'api_admin_ads_update', true],
        ['DELETE', '#^/api/admin/ads/(\d+)$#', 'api_admin_ads_delete', true],

        // 车辆管理
        ['GET', '#^/api/admin/vehicles/export$#', 'api_admin_vehicle_export', true],
        ['POST', '#^/api/admin/vehicles/import$#', 'api_admin_vehicle_import', true],
        ['GET', '#^/api/admin/vehicles$#', 'api_admin_vehicles_list', true],
        ['POST', '#^/api/admin/vehicles$#', 'api_admin_vehicle_create', true],
        ['GET', '#^/api/admin/vehicles/(\d+)/owner-token$#', 'api_admin_vehicle_owner_token', true],
        ['PUT', '#^/api/admin/vehicles/(\d+)$#', 'api_admin_vehicle_update', true],
        ['DELETE', '#^/api/admin/vehicles/(\d+)$#', 'api_admin_vehicle_delete', true],
        ['POST', '#^/api/admin/vehicles/bulk-delete$#', 'api_admin_vehicles_bulk_delete', true],
        ['POST', '#^/api/admin/vehicles/bulk-export$#', 'api_admin_vehicles_bulk_export', true],

        // 预生成二维码
        ['POST', '#^/api/admin/qr-codes/batch$#', 'api_admin_qr_batch', true],
        ['POST', '#^/api/admin/qr-codes/bulk$#', 'api_admin_qr_bulk', true],
        ['POST', '#^/api/admin/qr-codes/bulk-delete$#', 'api_admin_qr_bulk_delete', true],
        ['GET', '#^/api/admin/qr-codes$#', 'api_admin_qr_list', true],
        ['PATCH', '#^/api/admin/qr-codes/(\d+)$#', 'api_admin_qr_update', true],
        ['DELETE', '#^/api/admin/qr-codes/(\d+)$#', 'api_admin_qr_delete', true],

        // 拨号日志
        ['GET', '#^/api/admin/call-logs/export$#', 'api_admin_call_logs_export', true],
        ['POST', '#^/api/admin/call-logs/bulk-delete$#', 'api_admin_call_logs_bulk_delete', true],
        ['GET', '#^/api/admin/call-logs$#', 'api_admin_call_logs', true],
    ];

    foreach ($routes as [$m, $re, $handler, $guard]) {
        if ($m !== $method) continue;
        if (!preg_match($re, $path, $mm)) continue;
        if (!empty($guard)) require_admin();
        $args = array_slice($mm, 1);
        // 数字参数转 int
        $args = array_map(fn($a) => (is_numeric($a) && (int)$a == $a && (string)(int)$a === $a) ? (int)$a : $a, $args);
        $handler(...$args);
        return;
    }

    if (str_starts_with($path, '/api/')) json_error('not_found', '接口不存在：' . $method . ' ' . $path, 404);
}
