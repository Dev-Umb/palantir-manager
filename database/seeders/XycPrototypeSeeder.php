<?php

namespace Database\Seeders;

use App\Actions\CreateObjectRecord;
use App\Actions\SeedXycReferenceData;
use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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
        $businessUser = User::where('email', 'business@xyc.test')->firstOrFail();
        $processingLetter = 'attachments/demo-processing-letter.pdf';
        $signedContract = 'attachments/demo-signed-contract.pdf';
        $statement = 'attachments/demo-statement.pdf';
        foreach ([$processingLetter, $signedContract, $statement] as $path) {
            Storage::disk('local')->put($path, "%PDF-1.4\n% 本地演示附件，仅用于界面测试\n");
        }

        $customers = collect([
            ['name' => '江苏澄远新能源装备有限公司（演示）', 'address' => '江苏省南通市通州湾产业园海盛路88号', 'level' => 'A', 'remark' => '虚构演示客户，主营新能源装备制造。', 'contact' => ['name' => '周明远', 'phone' => '138****2601']],
            ['name' => '浙江启衡智能制造有限公司（演示）', 'address' => '浙江省嘉兴市秀洲区创智路166号', 'level' => 'A', 'remark' => '虚构演示客户，项目沟通响应及时。', 'contact' => ['name' => '沈佳宁', 'phone' => '139****7318']],
            ['name' => '安徽新岳仓储科技有限公司（演示）', 'address' => '安徽省合肥市肥西县先进制造产业园兴业大道21号', 'level' => 'B', 'remark' => '虚构演示客户，首次合作。', 'contact' => ['name' => '方建国', 'phone' => '136****4120']],
        ])->map(function (array $data) use ($writer, $object): array {
            $contactData = $data['contact'];
            unset($data['contact']);
            $customer = $writer->handle($object('customer'), $data);
            $contact = $writer->handle($object('customer_contact'), [...$contactData, 'customer_id' => $customer->id]);

            return compact('customer', 'contact');
        });

        $projectDefinitions = [
            ['customer' => 0, 'name' => '沿海风电装备基地钢构项目', 'status' => '合同签署', 'contract_status' => '已签署', 'amount' => 5280000, 'occurred' => 2880000, 'paid' => 1680000, 'invoiced' => 2100000, 'first' => '2025-11-18', 'last' => '2026-05-20', 'risk' => '二期对账单待客户确认'],
            ['customer' => 1, 'name' => '智能物流中心钢平台项目', 'status' => '已拿到加工函', 'contract_status' => '部分签署', 'amount' => 2860000, 'occurred' => 1260000, 'paid' => 460000, 'invoiced' => 680000, 'first' => '2026-04-10', 'last' => '', 'risk' => '补充协议尚未完成签署'],
            ['customer' => 2, 'name' => '高位仓库扩建钢结构项目', 'status' => '已中标', 'contract_status' => '未签署', 'amount' => null, 'occurred' => 0, 'paid' => 0, 'invoiced' => 0, 'first' => '', 'last' => '', 'risk' => '等待客户下发加工函'],
            ['customer' => 0, 'name' => '新能源设备车间改造项目', 'status' => '投标中', 'contract_status' => '未签署', 'amount' => null, 'occurred' => 0, 'paid' => 0, 'invoiced' => 0, 'first' => '', 'last' => '', 'risk' => '商务报价有效期临近'],
        ];

        foreach ($projectDefinitions as $index => $definition) {
            $account = $customers[$definition['customer']];
            $amount = $definition['amount'];
            $project = $writer->handle($object('project'), [
                'name' => $definition['name'],
                'customer_contact_ids' => [$account['contact']->id],
                'customer_id' => $account['customer']->id,
                'business_owner_user_id' => (string) $businessUser->id,
                'overall_status' => $definition['status'],
                'contract_status' => $definition['contract_status'],
                'first_shipment_date' => $definition['first'],
                'last_shipment_date' => $definition['last'],
                'handover_date' => now()->subDays(30 + $index * 12)->format('Y-m-d'),
                'weight' => [860, 430, 310, 275][$index],
                'contract_amount' => $amount,
                'occurred_amount' => $definition['occurred'],
                'paid_amount' => $definition['paid'],
                'unpaid_amount' => max($definition['occurred'] - $definition['paid'], 0),
                'reconciled_amount' => min($definition['occurred'], $definition['paid'] + 420000),
                'invoiced_amount' => $definition['invoiced'],
                'uninvoiced_amount' => max($definition['occurred'] - $definition['invoiced'], 0),
                'payment_progress' => $definition['occurred'] > 0 ? round($definition['paid'] / $definition['occurred'] * 100, 2) : 0,
                'payment_status' => $definition['paid'] > 0 ? '部分回款' : '未回款',
                'collection_count' => $index < 2 ? $index + 1 : 0,
                'risk' => $definition['risk'],
                'remark' => '本地演示数据，企业及联系人均为虚构。',
            ]);

            if ($index === 0) {
                $writer->handle($object('contract'), ['customer_id' => $account['customer']->id, 'project_id' => $project->id, 'status' => '已签署', 'ctype' => '加工合同', 'amount' => 5280000, 'signed_date' => '2025-10-28', 'processing_letter_attachments' => [$processingLetter], 'contract_attachments' => [$signedContract], 'statement_attachments' => [$statement], 'contract_qty' => 860, 'remark' => '主合同（演示）']);
            }
            if ($index === 1) {
                $writer->handle($object('contract'), ['customer_id' => $account['customer']->id, 'project_id' => $project->id, 'status' => '已签署', 'ctype' => '加工合同', 'amount' => 1980000, 'signed_date' => '2026-03-06', 'processing_letter_attachments' => [$processingLetter], 'contract_attachments' => [$signedContract], 'contract_qty' => 300, 'remark' => '首批加工合同（演示）']);
                $writer->handle($object('contract'), ['customer_id' => $account['customer']->id, 'project_id' => $project->id, 'status' => '已有加工函', 'ctype' => '补充协议', 'amount' => 880000, 'processing_letter_attachments' => [$processingLetter], 'contract_qty' => 130, 'remark' => '待签补充协议（演示）']);
            }
        }
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
