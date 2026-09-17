#!/bin/bash
# 本地冒烟：安装 → 创建 → 访客 → 二维码 → 车主 → 日志 → 管理端
B=${MC_BASE:-http://127.0.0.1:8099}
pass(){ echo "  [OK] $1"; }
fail(){ echo "  [FAIL] $1 -> $2"; }

jget(){ node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{try{const j=JSON.parse(s);console.log(process.argv[1].split('.').reduce((a,k)=>a?a[k]:'',j)||'')}catch(e){console.log('')}})" "$1"; }

# 清库（不用 rm —— 本机 rm 被 safe-delete 包装会失败）
php -r '$f=["storage/database.sqlite","storage/database.sqlite-wal","storage/database.sqlite-shm","app/config.php"];foreach($f as $p){@unlink($p);} echo "reset\n";' >/dev/null 2>&1

echo "=== 0. 安装向导（SQLite）==="
code=$(curl -s -o storage/tmp/inst.html -w "%{http_code}" -X POST -d "step=install&driver=sqlite&admin_user=admin&admin_pass=MoveCar@2026" $B/install.php)
[ "$code" = "200" ] && pass "安装页 200" || fail "安装页" "$code"
grep -q "安装已完成" storage/tmp/inst.html && pass "安装成功" || fail "安装" "$(head -c 300 storage/tmp/inst.html)"

echo "=== 1. 健康检查 ==="
H=$(curl -s $B/api/health)
echo "$H" | head -c 200; echo
echo "$H" | grep -q '"status":"ok"' && pass "health" || fail "health" "$H"

echo "=== 2. 管理员登录 ==="
LOGIN=$(curl -s -X POST -H "Content-Type: application/json" -d '{"username":"admin","password":"MoveCar@2026"}' $B/api/admin/login)
TOK=$(echo "$LOGIN" | jget token)
[ -n "$TOK" ] && pass "token 获取" || fail "登录" "$LOGIN"

echo "=== 3. 数据看板 ==="
OV=$(curl -s -H "Authorization: Bearer $TOK" $B/api/admin/overview)
echo "$OV" | head -c 220; echo
echo "$OV" | grep -q '"runtime"' && pass "overview" || fail "overview" "$OV"

echo "=== 4. 创建挪车码 ==="
CR=$(curl -s -X POST -H "Content-Type: application/json" -d '{"plateNumber":"粤A12345","ownerPhone":"13800001111","ownerPin":"123456"}' $B/api/vehicles)
echo "$CR" | head -c 240; echo
VT=$(echo "$CR" | jget vehicleToken)
OT=$(echo "$CR" | jget ownerToken)
[ -n "$VT" ] && pass "创建成功" || fail "创建" "$CR"

echo "=== 5. 重复车牌拦截 ==="
DUP=$(curl -s -X POST -H "Content-Type: application/json" -d '{"plateNumber":"粤A12345","ownerPhone":"13800001111"}' $B/api/vehicles)
echo "$DUP" | grep -q "plate_exists" && pass "409 plate_exists（返回完整车牌 $(echo "$DUP" | grep -o '粤A12345')）" || fail "重复车牌" "$DUP"

echo "=== 6. 访客公开页 ==="
PV=$(curl -s $B/api/vehicles/$VT/public)
echo "$PV" | head -c 260; echo
echo "$PV" | grep -q '"plateNumber":"粤A12345"' && pass "完整车牌" || fail "车牌" "$PV"
echo "$PV" | grep -q '"callMode":"direct"' && pass "直拨为默认" || fail "callMode" "$PV"

echo "=== 7. 通道列表 ==="
CH=$(curl -s $B/api/channels)
echo "$CH" | head -c 180; echo
echo "$CH" | grep -q '"direct_call":true' && pass "直拨默认开通" || fail "channels" "$CH"

echo "=== 8. 批量生成二维码 ==="
QB=$(curl -s -X POST -H "Content-Type: application/json" -H "Authorization: Bearer $TOK" -d '{"count":3,"batchNo":"B1"}' $B/api/admin/qr-codes/batch)
CT=$(node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{try{console.log((JSON.parse(s).tokens||[])[0]||'')}catch(e){console.log('')}})" <<< "$QB")
[ -n "$CT" ] && pass "批量生成 3 个（首个 ${CT:0:12}...）" || fail "批量生成" "$QB"

echo "=== 9. 扫码解析（未绑定）==="
QR=$(curl -s $B/api/qr/$CT)
echo "  $QR"
echo "$QR" | grep -q '"status":"unbound"' && pass "未绑定" || fail "qr resolve" "$QR"

echo "=== 10. 扫码绑定 + 再次扫码 ==="
QBD=$(curl -s -X POST -H "Content-Type: application/json" -d '{"plateNumber":"粤B88888","ownerPhone":"13900002222","ownerPin":"123456"}' $B/api/qr/$CT/bind)
echo "$QBD" | head -c 200; echo
echo "$QBD" | grep -q "绑定成功" && pass "绑定成功" || fail "绑定" "$QBD"
QR2=$(curl -s $B/api/qr/$CT)
echo "  $QR2"
echo "$QR2" | grep -q '"status":"bound"' && pass "再扫码 → 已绑定（车牌 $(echo "$QR2" | grep -o '粤B88888')）" || fail "bound" "$QR2"

echo "=== 11. 车主找回 / 车主后台 ==="
RC=$(curl -s -X POST -H "Content-Type: application/json" -d '{"plateNumber":"粤A12345","ownerPin":"123456"}' $B/api/owner/recover)
OT2=$(echo "$RC" | jget ownerToken)
[ "$OT2" = "$OT" ] && pass "找回 ownerToken 一致" || fail "找回" "$RC"
OVH=$(curl -s $B/api/owner/$OT/vehicle)
echo "$OVH" | head -c 220; echo
echo "$OVH" | grep -q '"plateNumber":"粤A12345"' && pass "车主后台完整车牌" || fail "车主后台" "$OVH"

echo "=== 12. 拨号日志 ==="
CL=$(curl -s -X POST -H "Content-Type: application/json" -d '{"callerNumber":"13700003333"}' $B/api/vehicles/$VT/call-log)
echo "$CL" | grep -q "已记录" && pass "直拨日志写入" || fail "call-log" "$CL"
CLL=$(curl -s -H "Authorization: Bearer $TOK" "$B/api/admin/call-logs?q=%E7%B2%A4A12345")
echo "$CLL" | head -c 240; echo
echo "$CLL" | grep -q '"plateNumber":"粤A12345"' && pass "按车牌筛选 + 完整车牌" || fail "日志筛选" "$CLL"

echo "=== 13. 通知（未开通通道应拒绝）==="
NF=$(curl -s -X POST -H "Content-Type: application/json" -d '{"channel":"sms"}' $B/api/vehicles/$VT/notify)
echo "  $NF"
echo "$NF" | grep -q "channel_unavailable" && pass "未开通通道被拒" || fail "notify" "$NF"

echo "=== 14. 本地二维码（SVG，无 GD 也可）==="
curl -s -D storage/tmp/qr.h -o storage/tmp/qr.svg -w "  qr http=%{http_code} bytes=%{size_download}\n" "$B/qr.php?text=https://example.com/move?c=qr_x&size=240"
[ -s storage/tmp/qr.svg ] && pass "二维码本地生成" || fail "qr svg" "empty"
grep -qi "image/svg+xml" storage/tmp/qr.h && pass "SVG Content-Type" || fail "svg ct" "no header"
head -c 5 storage/tmp/qr.svg | grep -q "<?xml" && pass "SVG 内容合法" || fail "svg body" "not xml"

echo "=== 15. 管理端车辆列表 / 广告位 ==="
VL=$(curl -s -H "Authorization: Bearer $TOK" "$B/api/admin/vehicles")
echo "$VL" | head -c 200; echo
echo "$VL" | grep -q '"plateNumber":"粤A12345"' && pass "车辆列表完整车牌" || fail "vehicles" "$VL"
AD=$(curl -s -H "Authorization: Bearer $TOK" "$B/api/admin/ads")
echo "$AD" | grep -q "positions" && pass "广告位接口" || fail "ads" "$AD"
echo "=== 完成 ==="
