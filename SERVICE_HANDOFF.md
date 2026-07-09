# 鑫源昌智造中枢服务交接说明

更新时间：2026-07-09 14:25（Asia/Taipei）

## 当前服务

- 线上地址：`https://palantir.umb.ink`
- 服务器 IP：`62.234.53.54`
- 服务器用户：`ubuntu`
- SSH 连接命令：

```bash
ssh -i ~/.ssh/cloud_server_ed25519 ubuntu@62.234.53.54
```

如果本机开启 Clash TUN，需绑定 Wi-Fi 网卡：

```bash
ssh -o BindInterface=en0 -i ~/.ssh/cloud_server_ed25519 ubuntu@62.234.53.54
```

- 线上项目目录：`/var/www/palantir`
- Web 服务：Nginx + PHP-FPM
- PHP-FPM 服务名：`php8.4-fpm`
- 线上入口：Nginx 指向 Laravel `public/`
- 默认公网端口：`80`、`443`
- 域名：`palantir.umb.ink`

## 数据库

- 线上数据库使用服务器内网地址：`172.21.32.14:5432`
- 数据库名：`xinyuanchang_prod`
- 数据库连接账号、密码在服务器 `/var/www/palantir/.env` 中维护。
- 部署时不要覆盖服务器 `.env`。
- 本地开发环境当前也连接 `xinyuanchang_prod`，连接信息在本地 `.env` 中。

## 本地项目

- 本地工作目录：`/Users/umb/Documents/XYC-Manager`
- 本地开发服务：

```bash
php artisan serve --host=127.0.0.1 --port=8001
```

- 本地访问：`http://127.0.0.1:8001`
- 常用测试账号密码均为：`password123`
- 主要测试账号：
  - `admin@xyc.test`：管理
  - `business@xyc.test`：业务
  - `engineering@xyc.test`：技术
  - `production@xyc.test`：生产
  - `procurement@xyc.test`：采购
  - `warehouse@xyc.test`：库管
  - `finance@xyc.test`：财务

## 部署流程

部署前先在本地执行：

```bash
npm run build
php artisan test
```

同步代码到服务器：

```bash
rsync -az --delete -e "ssh -o BindInterface=en0 -i ~/.ssh/cloud_server_ed25519" \
  --exclude='.env' \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='*.png' \
  --exclude='storage/app/public/attachments/*' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  ./ ubuntu@62.234.53.54:/var/www/palantir/
```

服务器上同步元数据、清缓存、重启服务：

```bash
ssh -o BindInterface=en0 -i ~/.ssh/cloud_server_ed25519 ubuntu@62.234.53.54 "cd /var/www/palantir && \
php artisan db:seed --class=XycPrototypeSeeder --force && \
php artisan optimize:clear && \
php artisan route:cache && \
php artisan config:cache && \
php artisan view:cache && \
sudo find /var/www/palantir -type d -exec chmod 755 {} + && \
sudo find /var/www/palantir -type f -exec chmod 644 {} + && \
sudo chown -R ubuntu:www-data /var/www/palantir/storage /var/www/palantir/bootstrap/cache && \
sudo chmod -R ug+rwX /var/www/palantir/storage /var/www/palantir/bootstrap/cache && \
sudo find /var/www/palantir/storage /var/www/palantir/bootstrap/cache -type d -exec chmod g+s {} + && \
sudo systemctl restart php8.4-fpm && \
sudo systemctl reload nginx"
```

部署后检查：

```bash
curl -I https://palantir.umb.ink
curl -I http://62.234.53.54
```

预期 `https://palantir.umb.ink` 未登录时返回 `302` 到 `/login`。

## 当前代码状态（2026-07-09 更新）

本地已完成并验证：

- `config/xyc.php` 已按飞书多维表格 **Grid View 列顺序** 严格对齐全部 15 张业务表字段（含名称与顺序）。
- 已逐字段核对通过（脚本比对，全部 EXACT）：客户信息、项目主档↔项目总控、客户与合同、技术图纸与方案、生产任务、班组日报、拆解表、成品发货、对账回款、采购日报、原材料入库单、生产领料出库单、库存台账、盘点表、物料主档↔产品表。
- 本轮补齐/修正的重点：
  - 项目主档：删除 `项目名称（规范）/客户名称（规范）/项目编号（规范）/父记录`，首列改为 `项目名称`，第二列为 `客户名称`，并保留 `关联合同编号` 自动回写。
  - 对账回款：补 `开票状态`（共 21 列）。
  - 原材料入库单：补 `供应商`，合并为 `数量/重量`，去掉 `备注`，按视图重排为 10 列。
  - 生产领料出库单：补 `是否优先使用余料/库存是否已扣减/实发数量/项目·订单·工序`，重排为 16 列。
  - 库存台账、盘点表：按 Grid View 顺序重排（盘点表 `差异数量` 修正错别字，`巾异重量kg` 保留与飞书一致）。
  - 物料主档：改为对齐飞书「产品表」（产品编码/产品名称/规格型号/材质/厚度/宽度/长度/单位/单重/安全库存/默认供应商/产品状态/备注），`title_field` 仍为 `name`，关联关系不受影响。
- 技术图纸与方案：飞书 Grid View 无 `附件` 列；本地在 18 列之后追加 `附件`（图纸必须能传附件），为唯一有意保留的例外。
- 退料单保持 `重量`、`钢材类别` 两列。
- 采购角色：`HandleInertiaRequests` 在本体工作台隐藏「采购申请（请购单）」，且采购无 `requisition.create`（不能提交），但保留「采购OA审批」。
- 经营大盘「库存风险」面板可点击「发起采购申请」，链接自带 `material_id`，`Requisitions/Create` 会预填物料。
- 项目主档的 `合同金额` 和 `关联合同编号` 会从合同表自动汇总回写。
- `app/Actions/SyncMaterialStockLedger.php` 已兼容物料新字段键（`safety_stock/material_quality/spec_model`，并向后兼容旧键）。
- `npm run build` 通过；`php artisan test` 通过：25 tests，325 assertions。
- **字段元数据已同步到生产库**：本地连接的是生产库 `xinyuanchang_prod`（公网 `bj-postgres-0i40ty04.sql.tencentcdb.com:26160`），已执行 `php artisan db:seed --class=XycPrototypeSeeder --force`，`business_objects.fields` 已更新为对齐后的字段。线上表格按库读字段，**字段名称与顺序的改动已在生产环境生效，可直接在 https://palantir.umb.ink 验收**。

## 当前部署状态

2026-07-09 14:21 已完成代码同步、生产库 seed、Laravel 缓存重建、`php8.4-fpm` 重启与 Nginx reload。

公网检查通过：

```bash
curl -I https://palantir.umb.ink   # 302 /login
curl -I http://62.234.53.54        # 302 /login
```

本机开启 Clash TUN 时，普通 SSH 会被 `utun6` 抢路由并在 banner 前断开。可用方案是给 SSH/rsync 加 `-o BindInterface=en0`；`deploy.sh` 已内置该参数。

## 注意事项

- 不要覆盖服务器 `.env`。
- 不要删除服务器 `storage` 目录里的用户上传附件。
- 字段定义存在数据库 `business_objects.fields`，修改 `config/xyc.php` 后必须执行：

```bash
php artisan db:seed --class=XycPrototypeSeeder --force
```

- `XycPrototypeSeeder` 会同步角色、权限和业务对象字段；如果数据库已有业务记录，不会清空业务数据。
- 前端表格使用 AG Grid，核心文件是 `resources/js/Components/ObjectGrid.jsx`。
- 页面字段表单控件在 `resources/js/Components/FieldControl.jsx`。
- 采购申请流程在 `app/Http/Controllers/RequisitionController.php`。
- 领料申请和班组日报公开表单在 `app/Http/Controllers/ShopFloorController.php`。
- 库存预警计算在 `app/Actions/SyncMaterialStockLedger.php`。
