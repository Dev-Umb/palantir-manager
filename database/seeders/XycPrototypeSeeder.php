<?php

namespace Database\Seeders;

use App\Actions\CreateObjectRecord;
use App\Actions\SeedXycReferenceData;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class XycPrototypeSeeder extends Seeder
{
    public function run(): void
    {
        app(SyncXycMetadata::class)->handle();

        if (app()->environment(['local', 'testing'])) {
            $this->seedDemoUsers();
        }

        app(SeedXycReferenceData::class)->handle();

        if (! app()->environment(['local', 'testing']) || $this->hasNonReferenceBusinessRecords()) {
            return;
        }

        $this->seedDemoBusinessRecords();
    }

    private function seedDemoBusinessRecords(): void
    {
        $writer = app(CreateObjectRecord::class);
        $object = fn (string $key) => BusinessObject::where('key', $key)->firstOrFail();

        $customer = $writer->handle($object('customer'), [
            'name' => '鑫源昌样板客户',
            'level' => 'A',
        ]);
        $contact = $writer->handle($object('customer_contact'), [
            'name' => '陈经理',
            'phone' => '13800000000',
            'position' => '项目经理',
            'customer_id' => $customer->id,
            'status' => '启用',
        ]);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')
            ->where('payload->material_code', 'NO.007')
            ->firstOrFail();
        $project = $writer->handle($object('project'), [
            'name' => '南通厂房钢结构一期',
            'customer_contact_ids' => [$contact->id],
            'project_no' => 'XYC-DEMO-001',
            'customer_id' => $customer->id,
            'overall_status' => '进行中',
            'stage' => '生产加工',
            'manager' => '业务经理',
            'owner_role' => '生产',
            'contract_qty' => 1280,
            'weight' => 1280,
            'risk' => '核心材料尚未全部到货',
            'remark' => '本地演示项目。',
        ]);

        $writer->handle($object('contract'), [
            'ctype' => '销售合同',
            'amount' => 3860000,
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'status' => '已收到',
        ]);
        $writer->handle($object('drawing'), [
            'name' => 'A 区主梁深化图',
            'project_id' => $project->id,
            'design_status' => '已下放',
            'weight' => 420,
        ]);
        $writer->handle($object('shipment'), [
            'project_id' => $project->id,
            'product_name' => '箱型梁',
            'qty_ton' => 86,
        ]);
        $writer->handle($object('receivable'), [
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'contract_amount' => 3860000,
            'invoiced_amount' => 1800000,
            'paid_amount' => 950000,
            'unpaid_amount' => 2910000,
            'pay_status' => '部分回款',
        ]);
        $writer->handle($object('invoice'), [
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'invoice_no' => 'FP-DEMO-001',
            'amount' => 1800000,
            'invoice_date' => now()->format('Y-m-d'),
            'status' => '已开票',
        ]);
        $writer->handle($object('purchase'), [
            'date' => now()->format('Y-m-d'),
            'project_id' => $project->id,
            'requester' => '生产',
            'purchase_date' => now()->format('Y-m-d'),
            'supplier_name' => '太钢大明',
            'items' => [[
                'id' => (string) Str::uuid(),
                'material_id' => $material->id,
                'spec' => '12mm',
                'reported_qty' => '60张',
                'qty' => 60,
                'weight_ton' => 60,
                'price' => 4380,
                'total_price' => 262800,
                'arrived' => '部分到货',
                'daily_status' => '部分采购',
                'expected_arrival_date' => now()->addDay()->format('Y-m-d'),
                'remark' => '示例采购明细',
            ]],
        ]);
        $writer->handle($object('requisition'), [
            'requester' => '生产',
            'material_id' => $material->id,
            'qty' => 12,
            'unit' => '吨',
            'urgency' => '紧急',
            'reason' => '生产急需',
            'status' => '待处理',
        ]);
    }

    private function hasNonReferenceBusinessRecords(): bool
    {
        return BusinessObject::whereNotIn('key', ['material', 'production_team', 'team_member'])
            ->whereHas('records')
            ->exists();
    }

    private function seedDemoUsers(): void
    {
        foreach ([
            'business' => ['业务员', 'business@xyc.test'],
            'engineering' => ['技术员', 'engineering@xyc.test'],
            'procurement' => ['采购员', 'procurement@xyc.test'],
            'production_manager' => ['生产负责人', 'production_manager@xyc.test'],
            'production' => ['生产员', 'production@xyc.test'],
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
