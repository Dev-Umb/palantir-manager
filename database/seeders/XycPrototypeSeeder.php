<?php

namespace Database\Seeders;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class XycPrototypeSeeder extends Seeder
{
    public function run(): void
    {
        app(SyncXycMetadata::class)->handle();
        $this->seedDemoUsers();

        if (BusinessObject::whereHas('records')->exists()) {
            return;
        }

        $writer = app(CreateObjectRecord::class);
        $object = fn (string $key) => BusinessObject::where('key', $key)->firstOrFail();

        $customer = $writer->handle($object('customer'), ['name' => '鑫源昌样板客户', 'contact' => '陈经理', 'level' => 'A']);
        $material = $writer->handle($object('material'), ['name' => '12MMQ355B中厚板', 'material_type' => '钢板', 'spec' => '6mm×1500×6000', 'unit' => '张', 'unit_weight_type' => '每平米', 'unit_weight' => 94.2, 'fixed_size' => '2000×8000', 'warning_qty' => 30, 'remark' => '低合金高强板', 'status' => '启用']);
        $project = $writer->handle($object('project'), [
            'name' => '南通厂房钢结构一期',
            'project_no' => 'XYC-DEMO-001',
            'customer_id' => $customer->id,
            'overall_status' => '进行中',
            'stage' => '生产加工',
            'contract_amount' => 3860000,
            'paid_amount' => 950000,
            'manager' => '业务经理',
            'owner_role' => '生产',
            'contract_qty' => 1280,
            'weight' => 1280,
            'shipped_qty' => 86,
            'arrears' => 2910000,
            'risk' => '核心材料库存低于安全线',
            'remark' => '原型项目，覆盖合同、技术、采购、生产、发货、回款链路。',
        ]);

        $writer->handle($object('contract'), ['ctype' => '销售合同', 'amount' => 3860000, 'customer_id' => $customer->id, 'project_id' => $project->id, 'project_no_norm' => 'XYC-DEMO-001', 'status' => '已收到']);
        $drawing = $writer->handle($object('drawing'), ['name' => 'A 区主梁深化图', 'drawing_no' => 'DRW-A-001', 'project_id' => $project->id, 'project_no_norm' => 'XYC-DEMO-001', 'release_status' => '已下放', 'design_status' => '进行中', 'weight' => 420]);
        $writer->handle($object('work_order'), ['project_id' => $project->id, 'project_no_norm' => 'XYC-DEMO-001', 'drawing_id' => $drawing->id, 'team' => '班组A', 'status' => '部分完成', 'progress' => 62]);
        $writer->handle($object('shipment'), ['project_id' => $project->id, 'product_name' => '箱型梁', 'qty_ton' => 86, 'sign_status' => '未签收']);
        $writer->handle($object('receivable'), ['customer_name' => '鑫源昌样板客户', 'project_id' => $project->id, 'project_no_norm' => 'XYC-DEMO-001', 'contract_amount' => 3860000, 'invoice_amount' => 1800000, 'paid_amount' => 950000, 'unpaid' => 2910000, 'pay_status' => '进行中']);
        $writer->handle($object('invoice'), ['customer_id' => $customer->id, 'project_id' => $project->id, 'invoice_no' => 'FP-DEMO-001', 'amount' => 1800000, 'invoice_date' => now()->format('Y-m-d'), 'status' => '已开票']);
        $writer->handle($object('purchase'), [
            'date' => now()->format('Y-m-d'),
            'project_id' => $project->id,
            'requester' => '生产',
            'material_id' => $material->id,
            'spec' => '12mm',
            'reported_qty' => '60张',
            'tonnage' => 60,
            'purchase_date' => now()->format('Y-m-d'),
            'supplier_name' => '太钢大明',
            'qty' => 60,
            'price' => '4380元/吨',
            'total_price' => '262800',
            'arrived' => '部分到货',
            'daily_status' => '部分采购',
            'expected_arrival_date' => now()->addDay()->format('Y-m-d'),
            'remark' => '示例采购日报',
            'task_id' => 'TASK-DEMO',
        ]);
        $writer->handle($object('requisition'), ['requester' => '生产', 'material_id' => $material->id, 'qty' => 12, 'unit' => '吨', 'urgency' => '紧急', 'reason' => '生产急需', 'status' => '待处理']);
        $writer->handle($object('inbound'), ['material_id' => $material->id, 'spec' => '6mm×1500×6000', 'unit' => '张', 'qty' => 24, 'weight' => 24000, 'bin' => 'A区-01']);
        $writer->handle($object('outbound'), ['material_id' => $material->id, 'project_id' => $project->id, 'qty' => 31, 'team' => '下料班组']);
        $writer->handle($object('return_order'), ['weight' => 2.4, 'steel_category' => '钢板']);
        $writer->handle($object('scrap_ledger'), ['scrap_date' => now()->format('Y-m-d'), 'material_category' => '钢板', 'spec' => '12mm', 'qty' => 4, 'loss_rate' => 0.047, 'laser_team' => '班组A', 'outbound_total' => 31, 'raw_weight' => 38.4, 'scrap_weight' => 1.8, 'unit' => '张']);
        $writer->handle($object('scrap_quarter_stat'), ['stat_time' => now()->format('Y-m-d'), 'material_id' => $material->id, 'scrap_weight' => 18.6, 'avg_loss_rate' => 0.047]);
        $writer->handle($object('stocktake'), ['material_id' => $material->id, 'book_qty' => 25.4, 'real_qty' => 25.1, 'diff_reason' => '规格混放', 'handle_status' => '处理中']);
    }

    private function seedDemoUsers(): void
    {
        foreach ([
            'business' => ['业务员', 'business@xyc.test'],
            'engineering' => ['技术员', 'engineering@xyc.test'],
            'procurement' => ['采购员', 'procurement@xyc.test'],
            'production' => ['生产员', 'production@xyc.test'],
            'warehouse' => ['库管员', 'warehouse@xyc.test'],
            'finance' => ['财务员', 'finance@xyc.test'],
        ] as $roleName => [$name, $email]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password123')],
            );
            $user->roles()->syncWithoutDetaching([Role::where('name', $roleName)->firstOrFail()->id]);
        }
    }
}
