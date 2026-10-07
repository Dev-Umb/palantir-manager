## Why

系统目前以站内通知作为唯一事实来源。未来若接入飞书、邮件或短信，需要统一、可配置、可观测的异步外发能力，同时不能让外部渠道失败影响业务事务或既有站内通知。

## What Changes

- 新增统一通知渠道契约与调度器，按提醒类型选择启用渠道。
- 新增日志、邮件、飞书驱动与短信配置骨架。
- 新增用户渠道偏好、异步投递、失败重试与投递日志。
- 将既有项目通知与 tender 通知接入调度器；站内通知始终先落库且保持事实来源。

## Capabilities

### New Capabilities

- `notification-channels`: 全项目统一的第三方通知渠道适配层。

### Modified Capabilities

- `notification-center`: 站内通知生成后可按类型异步外发，但生成规则、接收人、已读状态与展示不变。

## Impact

- 新增通知渠道契约、值对象、调度器、队列任务与渠道驱动。
- 新增 `user_notification_channels`、`notification_deliveries` 表及渠道配置。
- 在既有项目通知与 tender 通知成功落库后追加异步调度接入点。
- 本 change 为独立 proposal，未经单独 review/approval 不进入开发。
