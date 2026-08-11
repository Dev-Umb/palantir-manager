## Why

客户主数据缺少国央企/私企分类，项目页面也无法直接看到关联客户性质。该字段必须由客户表唯一维护，项目只实时展示，避免双写和不一致。

## What Changes

- customer 新增可选“客户性质”下拉，仅允许“国央企、私企”。
- project 新增同名只读 lookup，读取关联客户当前值，不写入项目 payload。
- 历史空值、无客户或断链项目保持空值，不推断、不回填。

## Capabilities

### New Capabilities

- `customer-nature`: 客户性质维护与项目实时只读透传。

### Modified Capabilities

- `customer-management`: 客户 CRUD 增加客户性质。
- `project-management`: 项目列表和详情增加客户性质只读展示。

## Impact

- 修改 `config/xyc.php`、`app/Support/ObjectRelations.php` 并增加 Feature 测试。
- 不新增 migration、表、列、路由、角色、权限或依赖。

## Evidence Boundary

- 本地测试、部署和线上回归分别报告，不互相替代。
