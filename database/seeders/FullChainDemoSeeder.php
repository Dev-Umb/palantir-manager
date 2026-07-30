<?php

namespace Database\Seeders;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncProjectFinance;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FullChainDemoSeeder extends Seeder
{
    public const PROJECT_NAME = '全链路演示 · 苏州智能制造中心';

    public function run(): void
    {
        $this->call(XycPrototypeSeeder::class);

        DB::transaction(function (): void {
            $projectObject = $this->object('project');
            $existingProject = $projectObject->records()
                ->where('payload->name', self::PROJECT_NAME)
                ->first();
            if ($existingProject) {
                $this->repairExistingFinance($existingProject);

                return;
            }

            $writer = app(CreateObjectRecord::class);
            $business = User::where('email', 'business@xyc.test')->firstOrFail();
            $engineering = User::where('email', 'engineering@xyc.test')->firstOrFail();
            $procurement = User::where('email', 'procurement@xyc.test')->firstOrFail();
            $productionManager = User::where('email', 'production_manager@xyc.test')->firstOrFail();
            $production = User::where('email', 'production@xyc.test')->firstOrFail();

            $customer = $writer->handle($this->object('customer'), [
                'name' => '苏州智造产业发展有限公司',
                'address' => '江苏省苏州市工业园区',
                'level' => 'A',
                'cooperation_history' => '一期厂房项目已完成，本次为智能制造中心扩建。',
                'remark' => '全链路演示客户。',
            ], $business);

            $contact = $writer->handle($this->object('customer_contact'), [
                'name' => '周启明',
                'customer_id' => $customer->id,
            ], $business);

            $project = $writer->handle($projectObject, [
                'name' => self::PROJECT_NAME,
                'customer_contact_ids' => [$contact->id],
                'customer_id' => $customer->id,
                'stage' => '发货签收',
                'overall_status' => '进行中',
                'delivery_date' => now()->addDays(35)->format('Y-m-d'),
                'owner_role' => '业务',
                'remark' => '覆盖合同、技术、采购、生产、发货与财务的本地演示项目。',
                'handover_date' => now()->subDays(45)->format('Y-m-d'),
                'manager' => '顾承泽',
                'contract_qty' => 1680,
                'weight' => 1680,
                'collection_count' => 2,
                'risk' => '尾款待跟进，第二批构件待发货。',
            ], $business);

            $writer->handle($this->object('contract'), [
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'status' => '已收到',
                'ctype' => '销售合同',
                'amount' => 5800000,
                'signed_date' => now()->subDays(48)->format('Y-m-d'),
                'business_owner' => '顾承泽',
                'contract_qty' => 1680,
                'remark' => '主合同已归档。',
            ], $business);

            $drawing = $writer->handle($this->object('drawing'), [
                'project_id' => $project->id,
                'manager' => '顾承泽',
                'handover_date' => now()->subDays(45)->format('Y-m-d'),
                'name' => '智能制造中心主厂房深化图',
                'designer' => '林工',
                'drawing_date' => now()->subDays(34)->format('Y-m-d'),
                'design_status' => '已下放',
                'project_progress' => '深化设计已完成并下放生产',
                'required_arrival_date' => now()->addDays(35)->format('Y-m-d'),
                'weight' => 1680,
                'release_price' => 42,
                'receiver' => '生产计划组',
                'receiving_factory' => '一厂',
                'remark' => 'A/B/C 三个施工分区。',
            ], $engineering);

            $team = ObjectRecord::whereRelation('businessObject', 'key', 'production_team')
                ->where('payload->status', '启用')
                ->firstOrFail();
            $member = ObjectRecord::whereRelation('businessObject', 'key', 'team_member')
                ->where('payload->team_id', $team->id)
                ->where('payload->status', '启用')
                ->firstOrFail();

            $writer->handle($this->object('teardown'), [
                'drawing_id' => $drawing->id,
                'teardown_date' => now()->subDays(31)->format('Y-m-d'),
                'teardown_finished_at' => now()->subDays(29)->format('Y-m-d'),
                'operator' => '技术拆解组',
                'material_ready_status' => '已到位',
                'plan_start_date' => now()->subDays(28)->format('Y-m-d'),
                'actual_start_date' => now()->subDays(27)->format('Y-m-d'),
                'remark' => '主梁与次梁拆解完成。',
            ], $productionManager);

            $writer->handle($this->object('work_order'), [
                'drawing_id' => $drawing->id,
                'status' => '生产中',
                'task_type' => '钢结构加工',
                'expected_material' => 'Q355B 钢板、H 型钢',
                'team_id' => $team->id,
                'plan_start_date' => now()->subDays(28)->format('Y-m-d'),
                'actual_start_date' => now()->subDays(27)->format('Y-m-d'),
                'material_ready_status' => '已到位',
                'release_status' => '已下放',
                'production_owner_id' => $member->id,
                'weight' => 1680,
                'production_qty_ton' => 1120,
                'expected_finish_date' => now()->addDays(20)->format('Y-m-d'),
                'remark' => '第一批构件已完成，第二批正在生产。',
            ], $productionManager);

            $writer->handle($this->object('team_log'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'status' => '生产中',
                'process' => '焊接',
                'completed_qty' => 86,
                'unit' => '吨',
                'exception_type' => '无',
                'work_date' => now()->subDay()->format('Y-m-d'),
                'part_name' => '主厂房箱型梁',
                'remark' => '当日焊缝检测合格。',
            ], $production);

            $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')
                ->where('payload->status', '启用')
                ->firstOrFail();

            $writer->handle($this->object('requisition'), [
                'requester' => '技术',
                'material_id' => $material->id,
                'qty' => 96,
                'unit' => '张',
                'project_id' => $project->id,
                'urgency' => '紧急',
                'reason' => '第二批主梁排产补料。',
                'status' => '已转采购',
            ], $engineering);

            $writer->handle($this->object('purchase'), [
                'date' => now()->subDays(23)->format('Y-m-d'),
                'project_id' => $project->id,
                'requester' => '技术',
                'purchase_date' => now()->subDays(22)->format('Y-m-d'),
                'supplier_name' => '江苏大明工业科技集团',
                'items' => [[
                    'id' => (string) Str::uuid(),
                    'material_id' => $material->id,
                    'material_model' => 'Q355B',
                    'spec' => '16mm×2200×12000',
                    'reported_qty' => '96张',
                    'qty' => 96,
                    'weight_ton' => 318.4,
                    'unit' => '张',
                    'price' => 4650,
                    'total_price' => 1480560,
                    'arrived' => '部分到货',
                    'daily_status' => '已采购',
                    'expected_arrival_date' => now()->addDays(3)->format('Y-m-d'),
                    'actual_arrival_date' => now()->subDays(10)->format('Y-m-d'),
                    'remark' => '首批 64 张已到货。',
                ]],
            ], $procurement);

            $writer->handle($this->object('shipment'), [
                'project_id' => $project->id,
                'product_name' => '主厂房首批钢构件',
                'qty_ton' => 720,
                'ship_date' => now()->subDays(3)->format('Y-m-d'),
                'shipping_owner' => '吴班长',
                'logistics_info' => '整车直送苏州项目现场',
                'plate_no' => '苏E·A8527',
                'driver_phone' => '13800001234',
                'remark' => '首批构件已签收。',
            ], $productionManager);

            $writer->handle($this->object('receivable'), [
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'pay_status' => '部分回款',
                'remark' => '预付款与进度款已到账，尾款待验收。',
                'signed_weight' => 720,
                'contract_amount' => 5800000,
                'occurred_amount' => 4200000,
                'occurred_amount_updated_at' => now()->subDay()->format('Y-m-d'),
                'paid_amount' => 2900000,
                'reconciled_amount' => 3600000,
                'reconcile_date' => now()->subDays(5)->format('Y-m-d'),
                'invoiced_amount' => 3600000,
                'last_payment_date' => now()->subDays(7)->format('Y-m-d'),
            ]);

            $writer->handle($this->object('invoice'), [
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'invoice_no' => 'INV-FULLCHAIN-001',
                'amount' => 3600000,
                'invoice_date' => now()->subDays(6)->format('Y-m-d'),
                'status' => '已开票',
            ]);
        });
    }

    private function object(string $key): BusinessObject
    {
        return BusinessObject::where('key', $key)->firstOrFail();
    }

    private function repairExistingFinance(ObjectRecord $project): void
    {
        $receivable = ObjectRecord::whereRelation('businessObject', 'key', 'receivable')
            ->where('payload->project_id', $project->id)
            ->first();
        if (! $receivable || (float) ($receivable->payload['contract_amount'] ?? 0) === 5800000.0) {
            return;
        }

        $receivable->update([
            'payload' => [
                ...($receivable->payload ?? []),
                'contract_amount' => 5800000,
            ],
        ]);
        app(SyncProjectFinance::class)->handle($project->id);
    }
}
