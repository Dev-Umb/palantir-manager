## Why

用户已确认安卓业务端 V2 交互并要求继续 Flutter 实现、本地 Laravel 数据和 Android 模拟器测试。

## What Changes

- 新建 mobile Flutter Android 工程；工作台、项目、客户、我的，AI 从工作台进入，招采仅参考阅读。
- Flutter 直接复用现有 Web 登录、Inertia 页面数据及 JSON 写入接口；不新增移动专用后端路由或业务规则。
- 采用 Laravel session 与 CSRF 登录，客户端提供默认开启的“保持登录”，复用原 remember 参数，按服务来源加密保存会话；不持久化原始密码，开发调试连接本地后端，正式构建连接经确认的生产 HTTPS 地址。
- 独立本地 SQLite 与虚构测试资料；真实 AI 接口沿用现有服务，无密钥时明确配置缺失，不伪造结果。
- 首轮完成可运行的业务流程，保留弱网与输入、合同上传及核对归档、客户、消息和设置。

## Capabilities

### New Capabilities

- `flutter-business-app`: Android client using existing backend interfaces.

### Modified Capabilities

无；沿用当前授权与财务、合同契约。

## Impact

仅调整 mobile 客户端与本变更规格。2026-09-11 用户明确要求复用既有接口，取代此前未上线的移动适配部署方案；不发布后端适配器、不修改生产后端、数据库或权限。保留隔离环境的既有测试资料。
