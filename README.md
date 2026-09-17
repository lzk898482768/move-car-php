# 扫码挪车 · PHP 版（宝塔面板一键部署）

车主贴一张码，扫码的人不用加微信、不用留号码，即可**一键通知车主挪车**。
本仓库是原 Cloudflare Workers 版（[move-car-mvp](https://github.com/lzk898482768/move-car-mvp)）的 **PHP 重写版**：完整继承原有全部功能与接口契约，并针对国内服务器场景做了增强。

> 为什么换成 PHP：Cloudflare 在国内访问不稳定、延迟高；PHP 版跑在任意一台国内 VPS / 宝塔面板上，首屏本地直出，无跨境链路，响应更快、可控性更强。

---

## 一、相比原版增强了什么

| 能力 | 说明 |
| --- | --- |
| **双数据库引擎** | MySQL（生产推荐）与 SQLite（零配置试用）二选一，安装向导可视化切换 |
| **可视化安装向导** | 访问 `/install.php` 填表即完成建库、建表、生成密钥、创建超管，无需手工执行 SQL |
| **本地二维码生成** | 内置 phpqrcode，`/qr.php?text=...&size=240` 本地出图，**不再依赖 api.qrserver.com**，内网/离线也能出码 |
| **超管运行看板** | 新增 `/api/admin/overview`：挪车码总数、二维码待绑/已绑、今日通知成功/失败、今日拨号、PHP/DB 运行时版本 |
| **通知日志** | 新增 `notification_logs` 表，每次通知（含失败原因）留痕，后台「查车主电话」可看到最近 5 条 |
| **接口级限流** | `rate_limits` 表：创建、通知、找回、登录分别限流，防刷 |
| **CSV 批量导入/导出** | 车牌批量导入（CSV/JSON）、车辆与拨号日志一键导出 CSV（带 UTF-8 BOM，Excel 不乱码） |
| **完整车牌展示** | 全站不再打码，管理员/车主看到的都是完整车牌 |
| **更强的日志** | 拨号日志支持按车牌、通道、状态、时间区间、号码后 4 位组合筛选 + 批量删除 |

---

## 二、完整继承的功能

- **4 种通知方式（车主侧）**：直拨（默认）/ 隐私拨号 / 短信通知 / 一键通知（企微 + 公众号模板消息双发）
- **通道状态模型**：`开关启用 && 参数齐全` 才算「已开通」；后台会显示「缺参数」提示，前台只显示已开通的通道
- **微信通知**：公众号**模板消息**（需 AppID / AppSecret / 模板ID / 收件人 OpenID），access_token 带缓存
- **短信三服务商**：腾讯云（TC3-HMAC-SHA256）、阿里云（RPC + HMAC-SHA1）、自定义 Webhook
- **隐私号**：自定义 Webhook / 腾讯云 / 阿里云号码保护
- **预生成二维码**：后台批量出码 → 打印 → 车主扫码绑定 → 绑定后任何人再扫都进入挪车界面
- **车牌唯一 + 手机号必填**：重复录入返回完整车牌并提示找回入口
- **车主后台**：管理密码登录、改手机号（短信/验证码）、重生成码、开关注释通道
- **超管后台**：标签页分区（车牌 / 二维码 / 通知渠道 / 查电话 / 拨号日志 / 广告位 / 账号）
- **广告位**：home_top / move_top / move_bottom / owner_top 四个位置
- **多管理员账号**：支持新增/删除管理员、修改自己密码

---

## 三、环境要求

| 项 | 要求 |
| --- | --- |
| PHP | **7.4 ~ 8.3**（推荐 8.0+） |
| 必需扩展 | `pdo` + `pdo_mysql`（MySQL）或 `pdo_sqlite`+`sqlite3`（SQLite）、`mbstring`、`openssl`、`json` |
| 生成二维码 | `gd`（缺失则二维码接口不可用） |
| 发送通知 | `curl` |
| 数据库 | MySQL 5.6+ / MariaDB 10+，或 SQLite 3 |
| Web 服务器 | Nginx（推荐）/ Apache |

---

## 四、目录结构

```
move-car-php/
├─ public/                 ← 网站运行目录（务必设为根目录）
│  ├─ index.php            统一入口（页面 + API 路由）
│  ├─ install.php          安装向导
│  ├─ qr.php               本地二维码图片
│  ├─ .htaccess            Apache 伪静态（自动生效）
│  ├─ index.html           首页 / 创建挪车码
│  ├─ move.html            访客挪车页
│  ├─ owner.html           车主后台
│  ├─ admin.html           超管后台
│  ├─ app.js / api.js / plate-input.js / styles.css / config.js
├─ app/
│  ├─ bootstrap.php        启动：配置加载、自动建库、密钥
│  ├─ schema.php           表结构（MySQL / SQLite 双引擎）
│  ├─ router.php           API 路由表
│  ├─ api.php              公开 / 车主 / 二维码接口
│  ├─ api_admin.php        超管接口
│  ├─ config.example.php   配置模板
│  └─ lib/                 util / crypto / db / settings / notify / auth / qr
├─ storage/                ← 需可写：SQLite 库、缓存、日志
├─ docs/
│  ├─ 宝塔部署.md           详细部署流程（必读）
│  └─ nginx.conf           Nginx 伪静态规则
└─ smoke.sh                本地一键冒烟脚本
```

---

## 五、快速开始

### 5.1 本地试跑（SQLite，30 秒）

```bash
git clone https://github.com/lzk898482768/move-car-php.git
cd move-car-php
php -S 127.0.0.1:8088 -t public public/index.php
```

浏览器打开 <http://127.0.0.1:8088/install.php>，数据库类型选 **SQLite**，设置超管账号密码，提交即完成。

再跑一次自动化冒烟（覆盖安装、创建、访客、二维码、车主、日志、后台 15 个环节）：

```bash
MC_BASE=http://127.0.0.1:8088 bash smoke.sh
```

### 5.2 服务器（宝塔面板）

见 **[docs/宝塔部署.md](docs/宝塔部署.md)**，含建站、PHP 版本与扩展、运行目录、伪静态、SSL、权限、安全加固与故障排查。

---

## 六、API 一览（与原版契约一致）

公开：`GET /api/health`、`GET /api/channels`、`GET /api/ads`、`POST /api/vehicles`、`GET /api/vehicles/:token/public`、`POST /api/vehicles/:token/notify`、`POST /api/vehicles/:token/call-log`、`GET|POST /api/qr/:code[/bind]`

车主：`POST /api/owner/recover`、`GET|PATCH|PUT|DELETE /api/owner/:ownerToken/vehicle`、`POST /api/owner/:ownerToken/vehicle/regenerate-token`、`POST /api/owner/:ownerToken/phone/send-code`

超管：`POST /api/admin/login`、`GET|PUT /api/admin/config`、`GET /api/admin/overview`（新）、`/api/admin/accounts`、`/api/admin/vehicles`（含 import/export/owner-token）、`/api/admin/qr-codes`（含 batch/bulk-delete）、`/api/admin/call-logs`（含 export/bulk-delete）、`/api/admin/ads`、`GET /api/admin/lookup`、`POST /api/admin/password`

鉴权：`Authorization: Bearer <token>` 或 `X-Admin-Token: <token>`。

---

## 七、安全说明

- 手机号、微信 OpenID、企微 Webhook、短信密钥等敏感字段在库中以 **AES-256-GCM** 密文存储，密钥由安装向导随机生成并写入 `app/config.php`
- 车牌以明文存储（便于检索与完整展示），同时存 sha256 用于唯一性校验
- **务必把网站运行目录设为 `public`**，否则 `app/`（含密钥配置）与 `storage/` 可被直接下载
- `app/config.php` 已在 `.gitignore` 中，切勿提交到仓库
- 通知、创建、找回、登录均有 IP 级限流；失败重试会被延迟惩罚

---

## 八、常见问题

**Q：二维码扫出来是空白？** 检查 PHP 是否安装 `gd` 扩展。
**Q：页面提示「系统尚未安装」？** 访问 `/install.php`；若已安装过，确认 `app/config.php` 存在且 `storage/` 可写。
**Q：安装时报数据库连接失败？** MySQL 需先在宝塔「数据库」里建库，再用**数据库账号密码**（不是面板密码）填写。
**Q：改了后台通道设置前台没变化？** 所有 API 响应均为 `no-store`，回到前台会自动刷新；若仍缓存，强制刷新一次浏览器。

---

## 九、许可

本项目代码 MIT。内置二维码库 `app/lib/phpqrcode.php` 为 LGPL v3（[t0k4rt/phpqrcode](https://github.com/t0k4rt/phpqrcode)），许可证见 `app/lib/phpqrcode-LICENSE.txt`。
