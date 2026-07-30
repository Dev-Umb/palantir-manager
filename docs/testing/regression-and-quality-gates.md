# Palantir Manager 需求、回归与质量门禁

本设计借鉴 Legato 的核心原则，但按 Palantir Manager 当前的 PHPUnit、Vitest、SQLite 与 Node 线上脚本实现，不假设尚不存在的 Pest Browser、PostgreSQL 测试服务或 CI 门禁。

## 1. 需求约束

实质变更先建立或引用 OpenSpec change，再按 `propose → review → apply` 推进。实质变更包括用户可见能力、业务行为、权限、流程、公共契约、Schema、事件、认证授权、数据范围、关键依赖及跨模块变更。

小型文档/测试维护、只读调查、诊断、格式化和恢复既定行为的窄范围修复可豁免；一旦出现新需求、设计分支或跨边界影响，立即重新评估。

实施前必须写清：

- `必须改变`
- `必须保持`
- `允许隐藏`
- `必须可见`
- `禁止推断`

替换组件、状态、协议或数据源时，还必须逐项映射旧能力/字段到新入口、渲染或明确退役决定。

## 2. 回归分层

| 层级 | 目标 | 命令 | 不能证明 |
| --- | --- | --- | --- |
| L1 Unit | 纯规则、边界与错误分支 | `composer test:unit` | HTTP、数据库、部署 |
| L2 Feature | HTTP、授权、持久化、事务 | `composer test:feature` | 浏览器布局、部署 |
| L3 React | 组件渲染与交互 | `npm run test:ui` | 服务端授权、真实部署 |
| L4 Online | 固定线上目标的已部署链路 | `composer test:online-regression` | 其他版本、其他环境 |

每项行为变更至少覆盖目标行为、明确保持的相邻行为和最可能误伤的边界。隐藏/过滤/权限规则同时验证“消失的内容”和“仍可见的内容”。

## 3. 本地质量门禁

聚焦迭代：

```bash
composer test:narrow -- tests/Feature/TargetTest.php
npm run test:ui -- resources/js/Components/Target.test.jsx
```

完整交付：

```bash
composer quality:gate
```

安装 Git hook：

```bash
composer quality:gate:install
```

hook 对 staged paths 做风险选路：

- Laravel/PHPUnit → `composer test:application`
- React/frontend → `npm run test:ui` + `npm run build`
- OpenSpec/治理工具 → `composer openspec:validate`
- 普通文档 → staged diff check
- 未知生产或工具路径 → 全部核心检查

门禁失败不得用 `--no-verify` 绕过。

## 4. 线上回归安全边界

现有全链路脚本会创建和修改线上记录，因此默认禁用，也不属于普通本地测试或提交门禁。运行时必须只在当前进程提供：

```bash
ONLINE_REGRESSION_ENABLED=1 \
ONLINE_REGRESSION_ALLOW_MUTATIONS=1 \
ONLINE_REGRESSION_BASE_URL=https://palantir.umb.ink \
ONLINE_REGRESSION_RUN_ID=REG-20260731-example \
ONLINE_REGRESSION_PASSWORD='[process-only]' \
composer test:online-regression
```

不得把密码写进 Git、`.env`、报告、回复或记忆。当前脚本没有已验证的清理契约，会保留带 Run ID 的测试记录；运行前必须接受这一点并使用专用测试身份。

## 5. 证据口径

以下状态必须分别报告：

1. 聚焦测试通过
2. 完整质量门禁通过
3. commit 已创建
4. PR 已创建
5. PR 已合并
6. 已部署
7. 固定线上目标已验证

任何前一项都不能替代后一项。
