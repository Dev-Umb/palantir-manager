# 公网全链路回归测试报告 - REG-20260707123620

- 测试入口：https://palantir.umb.ink
- 测试时间：2026-07-07T12:36:28.792Z
- 测试账号：admin=admin@xyc.test；business=business@xyc.test；engineering=engineering@xyc.test；production=production@xyc.test；procurement=procurement@xyc.test；warehouse=warehouse@xyc.test；finance=finance@xyc.test

## 结论

- 通过步骤：37
- 失败步骤：0
- 记录问题：3

## 测试步骤记录

| 链路 | 角色 | 步骤 | 结果 | 记录 |
| --- | --- | --- | --- | --- |
| 基础 | business | 公网登录 | PASS | business@xyc.test |
| 基础 | engineering | 公网登录 | PASS | engineering@xyc.test |
| 基础 | production | 公网登录 | PASS | production@xyc.test |
| 基础 | procurement | 公网登录 | PASS | procurement@xyc.test |
| 基础 | warehouse | 公网登录 | PASS | warehouse@xyc.test |
| 基础 | finance | 公网登录 | PASS | finance@xyc.test |
| 项目链路 | business | 创建 客户信息 | PASS | CUST-20260707-005 REG-20260707123620 客户 |
| 项目链路 | business | 创建 项目主档 | PASS | PRJ-20260707-008 REG-20260707123620 项目 |
| 项目链路 | business | 创建 客户与合同 | PASS | HT-20260707-008 客户与合同 |
| 项目链路 | business | 合同金额同步项目主档 | PASS | 528000 |
| 项目链路 | business | 项目流转到技术确认 | PASS |  |
| 项目链路 | engineering | 创建 技术图纸与方案 | PASS | TZ-20260707-004 REG-20260707123620 深化图 |
| 项目链路 | engineering | 图纸下放 | PASS |  |
| 项目链路 | engineering | 图纸附件字段 | PASS |  |
| 项目链路 | business | 项目流转到生产加工 | PASS |  |
| 项目链路 | production | 创建 生产任务 | PASS | TASK-20260707-004 生产任务 |
| 项目链路 | production | 创建 班组日报 | PASS | DAILY-20260707-002 班组日报 |
| 项目链路 | public | 公开班组日报提交 | PASS |  |
| 项目链路 | production | 生产任务完成 | PASS |  |
| 项目链路 | production | 创建 成品发货 | PASS | SHIP-20260707-003 成品发货 |
| 项目链路 | production | 发货附件字段 | PASS |  |
| 项目链路 | business | 项目流转到对账回款 | PASS |  |
| 项目链路 | finance | 创建 对账回款 | PASS | AR-20260707-004 对账回款 |
| 项目链路 | finance | 项目完成 | PASS |  |
| 采购/库存链路 | warehouse | 创建 物料主档 | PASS | MAT-20260707-050 REG-20260707123620 Q355B钢板 |
| 采购/库存链路 | production | 提交采购申请 | PASS |  |
| 采购/库存链路 | production | 查看本人采购申请流转状态 | PASS | 待处理 |
| 采购/库存链路 | procurement | OA审批通过 | PASS | QG-20260707-003 |
| 采购/库存链路 | procurement | 采购日报生成 | PASS | CG-20260707-005 |
| 采购/库存链路 | procurement | 采购日报部分到货 | PASS |  |
| 采购/库存链路 | procurement | 采购完成 | PASS |  |
| 采购/库存链路 | warehouse | 创建 原材料入库单 | PASS | RK-20260707-003 原材料入库单 |
| 采购/库存链路 | public | 公开领料申请提交 | PASS |  |
| 采购/库存链路 | warehouse | 领料审批并生成出库单 | PASS | LL-20260707-001 |
| 采购/库存链路 | warehouse | 创建 废料台账 | PASS | FL-20260707-003 废料台账 |
| 采购/库存链路 | warehouse | 创建 库存台账 | PASS | KC-20260707-003 库存台账 |
| 采购/库存链路 | warehouse | 创建 盘点表 | PASS | PD-20260707-003 盘点表 |

## 问题清单

| 编号 | 严重级别 | 链路 | 问题 |
| --- | --- | --- | --- |
| P3 | 中 | 项目链路 | 开票只有“对账回款.invoice_amount”字段，没有独立开票对象/审批流，也未自动回写项目主档已开票金额。 |
| S2 | 中 | 采购/库存链路 | 入库、出库、库存台账、盘点之间没有自动库存计算，库存台账需手工录入 balance。 |
| S3 | 中 | 采购/库存链路 | 采购日报只有“材料是否到货”字段，没有明确的采购完成时间、完成人、验收凭证。 |

## 测试数据索引

- Run ID：REG-20260707123620
- 客户：CUST-20260707-005
- 项目：PRJ-20260707-008
- 合同：HT-20260707-008
- 图纸：TZ-20260707-004
- 采购申请：QG-20260707-003
- 采购日报：CG-20260707-005
- 物料：MAT-20260707-050
