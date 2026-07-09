# 公网全链路回归测试报告 - REG-20260707124400

- 测试入口：https://palantir.umb.ink
- 测试时间：2026-07-07T12:44:09.380Z
- 测试账号：admin=admin@xyc.test；business=business@xyc.test；engineering=engineering@xyc.test；production=production@xyc.test；procurement=procurement@xyc.test；warehouse=warehouse@xyc.test；finance=finance@xyc.test

## 结论

- 通过步骤：41
- 失败步骤：0
- 记录问题：0

## 测试步骤记录

| 链路 | 角色 | 步骤 | 结果 | 记录 |
| --- | --- | --- | --- | --- |
| 基础 | business | 公网登录 | PASS | business@xyc.test |
| 基础 | engineering | 公网登录 | PASS | engineering@xyc.test |
| 基础 | production | 公网登录 | PASS | production@xyc.test |
| 基础 | procurement | 公网登录 | PASS | procurement@xyc.test |
| 基础 | warehouse | 公网登录 | PASS | warehouse@xyc.test |
| 基础 | finance | 公网登录 | PASS | finance@xyc.test |
| 项目链路 | business | 创建 客户信息 | PASS | CUST-20260707-006 REG-20260707124400 客户 |
| 项目链路 | business | 创建 项目主档 | PASS | PRJ-20260707-009 REG-20260707124400 项目 |
| 项目链路 | business | 创建 客户与合同 | PASS | HT-20260707-009 客户与合同 |
| 项目链路 | business | 合同金额同步项目主档 | PASS | 528000 |
| 项目链路 | business | 项目流转到技术确认 | PASS |  |
| 项目链路 | engineering | 创建 技术图纸与方案 | PASS | TZ-20260707-005 REG-20260707124400 深化图 |
| 项目链路 | engineering | 图纸下放 | PASS |  |
| 项目链路 | engineering | 图纸附件字段 | PASS |  |
| 项目链路 | business | 项目流转到生产加工 | PASS |  |
| 项目链路 | production | 创建 生产任务 | PASS | TASK-20260707-005 生产任务 |
| 项目链路 | production | 创建 班组日报 | PASS | DAILY-20260707-004 班组日报 |
| 项目链路 | public | 公开班组日报提交 | PASS |  |
| 项目链路 | production | 生产任务完成 | PASS |  |
| 项目链路 | production | 创建 成品发货 | PASS | SHIP-20260707-004 成品发货 |
| 项目链路 | production | 发货附件字段 | PASS |  |
| 项目链路 | business | 项目流转到对账回款 | PASS |  |
| 项目链路 | finance | 创建 对账回款 | PASS | AR-20260707-005 对账回款 |
| 项目链路 | finance | 创建 开票记录 | PASS | INV-20260707-001 开票记录 |
| 项目链路 | finance | 开票金额同步项目主档 | PASS | 528000 |
| 项目链路 | finance | 项目完成 | PASS |  |
| 采购/库存链路 | warehouse | 创建 物料主档 | PASS | MAT-20260707-051 REG-20260707124400 Q355B钢板 |
| 采购/库存链路 | production | 提交采购申请 | PASS |  |
| 采购/库存链路 | production | 查看本人采购申请流转状态 | PASS | 待处理 |
| 采购/库存链路 | procurement | OA审批通过 | PASS | QG-20260707-004 |
| 采购/库存链路 | procurement | 采购日报生成 | PASS | CG-20260707-006 |
| 采购/库存链路 | procurement | 采购日报部分到货 | PASS |  |
| 采购/库存链路 | procurement | 采购完成 | PASS |  |
| 采购/库存链路 | procurement | 采购完成信息自动记录 | PASS | 2026-07-07 采购员 |
| 采购/库存链路 | warehouse | 创建 原材料入库单 | PASS | RK-20260707-004 原材料入库单 |
| 采购/库存链路 | public | 公开领料申请提交 | PASS |  |
| 采购/库存链路 | warehouse | 领料审批并生成出库单 | PASS | LL-20260707-002 |
| 采购/库存链路 | warehouse | 库存台账自动扣减 | PASS | 结存 5 |
| 采购/库存链路 | warehouse | 创建 废料台账 | PASS | FL-20260707-004 废料台账 |
| 采购/库存链路 | warehouse | 创建 盘点表 | PASS | PD-20260707-004 盘点表 |
| 采购/库存链路 | warehouse | 盘点回写库存台账 | PASS | 结存 5 |

## 问题清单

| 编号 | 严重级别 | 链路 | 问题 |
| --- | --- | --- | --- |

## 测试数据索引

- Run ID：REG-20260707124400
- 客户：CUST-20260707-006
- 项目：PRJ-20260707-009
- 合同：HT-20260707-009
- 图纸：TZ-20260707-005
- 采购申请：QG-20260707-004
- 采购日报：CG-20260707-006
- 物料：MAT-20260707-051
