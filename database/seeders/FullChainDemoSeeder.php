<?php

namespace Database\Seeders;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncProjectContractAmount;
use App\Actions\SyncProjectNotifications;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class FullChainDemoSeeder extends Seeder
{
    public const PROJECT_NAME = '业务合同演示 · 苏州智能制造中心';

    public const PROJECT_PREFIX = '业务合同演示 · ';

    /** @var array<int, string> */
    private const DOCUMENT_FILENAMES = [
        'demo-processing-letter-01.pdf',
        'demo-processing-letter-02.pdf',
        'demo-processing-letter-03.pdf',
        'demo-contract-01.pdf',
        'demo-contract-02.pdf',
        'demo-contract-03.pdf',
        'demo-statement-01.pdf',
        'demo-statement-02.pdf',
        'demo-statement-03.pdf',
        'demo-statement-04.pdf',
        'demo-statement-05.pdf',
        'demo-statement-06.pdf',
        'demo-statement-07.pdf',
        'demo-statement-08.pdf',
        'demo-statement-09.pdf',
        'demo-statement-10.pdf',
    ];

    public function run(
        CreateObjectRecord $writer,
        SyncProjectContractAmount $contractAmounts,
        SyncProjectNotifications $notifications,
    ): void {
        $this->call(XycPrototypeSeeder::class);
        $this->publishDocuments();

        $projects = DB::transaction(function () use ($writer): array {
            $users = $this->demoUsers();
            $customers = $this->customers($writer, $users['business']);
            $projects = $this->projects($writer, $users, $customers);
            $this->contracts($writer, $customers, $projects);
            $this->seedNotificationHistory($projects['completed'], $users['admin']);

            return $projects;
        });

        collect($projects)->each(
            fn (ObjectRecord $project): mixed => $contractAmounts->handle($project->id),
        );
        $notifications->handleProjects(collect($projects)->pluck('id')->all());
        $this->shapeNotificationExamples($projects);
    }

    private function publishDocuments(): void
    {
        foreach (self::DOCUMENT_FILENAMES as $filename) {
            $source = base_path("output/pdf/{$filename}");
            if (! is_file($source)) {
                throw new \RuntimeException("演示附件不存在：{$source}");
            }

            Storage::disk('local')->put(
                "attachments/full-chain-{$filename}",
                file_get_contents($source),
            );
        }
    }

    /** @return array{business: User, business_secondary: User, admin: User, finance: User} */
    private function demoUsers(): array
    {
        $business = User::where('email', 'business@xyc.test')->firstOrFail();
        $finance = User::where('email', 'finance@xyc.test')->firstOrFail();
        $businessSecondary = User::updateOrCreate(
            ['email' => 'business.demo2@xyc.test'],
            ['name' => '业务员乙（演示）', 'password' => Hash::make('password123')],
        );
        $admin = User::updateOrCreate(
            ['email' => 'demo-notification-admin@xyc.test'],
            ['name' => '通知管理员（演示）', 'password' => Hash::make('password123')],
        );
        $businessRole = Role::where('name', 'business')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $businessSecondary->roles()->syncWithoutDetaching([$businessRole->id]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return [
            'business' => $business,
            'business_secondary' => $businessSecondary,
            'admin' => $admin,
            'finance' => $finance,
        ];
    }

    /**
     * @return array<string, array{customer: ObjectRecord, contacts: array<int, ObjectRecord>}>
     */
    private function customers(CreateObjectRecord $writer, User $business): array
    {
        $definitions = [
            'suzhou' => [
                'name' => '苏州衡远智能产业有限公司（演示）',
                'address' => '江苏省苏州市工业园区星湖街88号',
                'level' => 'A',
                'remark' => '虚构演示客户；覆盖多项目、多合同和历史联系人。',
                'contacts' => [
                    ['name' => '周启明（演示）', 'phone' => '138****2036'],
                    ['name' => '林嘉怡（演示）', 'phone' => '139****5182'],
                ],
            ],
            'shandong' => [
                'name' => '山东海岳装备工程有限公司（演示）',
                'address' => '山东省青岛市西海岸新区海西路126号',
                'level' => 'B',
                'remark' => '虚构演示客户；覆盖投标和中标提醒边界。',
                'contacts' => [
                    ['name' => '许正涛（演示）', 'phone' => '137****6109'],
                    ['name' => '罗文静（演示）', 'phone' => '186****3047'],
                ],
            ],
            'guangdong' => [
                'name' => '广东联铸物流设备有限公司（演示）',
                'address' => '广东省佛山市顺德区智造一路59号',
                'level' => 'A',
                'remark' => '虚构演示客户；覆盖签署、回款和已完成状态。',
                'contacts' => [
                    ['name' => '谢宇航（演示）', 'phone' => '135****7821'],
                    ['name' => '陈思敏（演示）', 'phone' => '188****4475'],
                ],
            ],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition, string $key) use ($writer, $business): array {
            $contacts = $definition['contacts'];
            unset($definition['contacts']);
            $customer = $this->upsertRecord(
                $writer,
                'customer',
                "full-chain-customer-{$key}",
                $definition,
                $business,
            );
            $contactRecords = collect($contacts)->map(
                fn (array $contact, int $index): ObjectRecord => $this->upsertRecord(
                    $writer,
                    'customer_contact',
                    "full-chain-contact-{$key}-{$index}",
                    [...$contact, 'customer_id' => $customer->id],
                    $business,
                ),
            )->all();

            return [$key => ['customer' => $customer, 'contacts' => $contactRecords]];
        })->all();
    }

    /**
     * @param  array{business: User, business_secondary: User, admin: User, finance: User}  $users
     * @param  array<string, array{customer: ObjectRecord, contacts: array<int, ObjectRecord>}>  $customers
     * @return array<string, ObjectRecord>
     */
    private function projects(CreateObjectRecord $writer, array $users, array $customers): array
    {
        $now = now();
        $definitions = [
            'bid_due' => ['customer' => 'shandong', 'name' => '投标满15天 - 已报警', 'owner' => 'business', 'status' => '投标中', 'status_at' => $now->copy()->subDays(16), 'risk' => '投标状态超过15天，等待业务更新。'],
            'bid_pending' => ['customer' => 'shandong', 'name' => '投标未满15天 - 未报警', 'owner' => 'business_secondary', 'status' => '投标中', 'status_at' => $now->copy()->subDays(10), 'risk' => '投标提醒尚未到期。'],
            'won_due' => ['customer' => 'suzhou', 'name' => '中标待加工函满15天 - 已报警', 'owner' => 'business', 'status' => '已中标', 'status_at' => $now->copy()->subDays(16), 'risk' => '中标后尚未取得加工函。'],
            'won_pending' => ['customer' => 'suzhou', 'name' => '中标待加工函未到期', 'owner' => 'business_secondary', 'status' => '已中标', 'status_at' => $now->copy()->subDays(8), 'risk' => '等待客户按计划下发加工函。'],
            'letter_due' => ['customer' => 'guangdong', 'name' => '加工函待签与回款 - 双提醒', 'owner' => 'business', 'status' => '已拿到加工函', 'status_at' => $now->copy()->subMonthsNoOverflow(4), 'processing_at' => $now->copy()->subMonthsNoOverflow(4), 'payment_at' => $now->copy()->subDays(32), 'risk' => '加工函已下发，合同及首笔回款均超期。'],
            'partial' => ['customer' => 'suzhou', 'name' => str_replace(self::PROJECT_PREFIX, '', self::PROJECT_NAME), 'owner' => 'business', 'status' => '已拿到加工函', 'status_at' => $now->copy()->subMonthsNoOverflow(5), 'processing_at' => $now->copy()->subMonthsNoOverflow(5), 'payment_at' => $now->copy()->subDays(35), 'risk' => '主合同已签、补充协议仅有加工函；需要重复催签和催款。'],
            'signed_partial_payment' => ['customer' => 'guangdong', 'name' => '全部签署 - 部分回款提醒', 'owner' => 'business_secondary', 'status' => '合同签署', 'status_at' => $now->copy()->subDays(45), 'processing_at' => $now->copy()->subDays(45), 'payment_at' => $now->copy()->subDays(32), 'risk' => '合同全部签署，仍有未回款和未开票金额。'],
            'paid' => ['customer' => 'shandong', 'name' => '全部签署 - 全额回款', 'owner' => 'business', 'status' => '合同签署', 'status_at' => $now->copy()->subDays(90), 'processing_at' => $now->copy()->subDays(100), 'payment_at' => $now->copy()->subDays(60), 'risk' => '无；用于验证已回款后停止提醒。'],
            'completed' => ['customer' => 'suzhou', 'name' => '人工已完成 - 历史提醒已解决', 'owner' => 'business_secondary', 'status' => '已完成', 'status_at' => $now->copy()->subDays(120), 'processing_at' => $now->copy()->subDays(150), 'payment_at' => $now->copy()->subDays(90), 'risk' => '已人工完成，保留历史通知但不再触发。'],
            'unassigned' => ['customer' => 'guangdong', 'name' => '历史未分配负责人', 'owner' => null, 'status' => '投标中', 'status_at' => $now->copy()->subDays(20), 'risk' => '历史迁移边界：未分配负责人，仅管理员和财务可见。'],
            'manual_amount' => ['customer' => 'shandong', 'name' => '财务手工金额保护', 'owner' => 'business', 'status' => '投标中', 'status_at' => $now->copy()->subDays(3), 'risk' => '合同合计与财务手工合同金额不同，后续合同变更不得覆盖。'],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition, string $key) use ($writer, $users, $customers): array {
            $account = $customers[$definition['customer']];
            $owner = $definition['owner'] ? $users[$definition['owner']] : null;
            $name = self::PROJECT_PREFIX.$definition['name'];
            $finance = $this->financialScenario($key);
            $payload = [
                '_demo_key' => "full-chain-project-{$key}",
                'name' => $name,
                'customer_contact_ids' => collect($account['contacts'])->pluck('id')->all(),
                'customer_id' => $account['customer']->id,
                'business_owner_user_id' => $owner ? (string) $owner->id : '',
                'overall_status' => $definition['status'],
                'overall_status_changed_at' => $definition['status_at']->toISOString(),
                'contract_status' => '未签署',
                'processing_letter_at' => isset($definition['processing_at']) ? $definition['processing_at']->toISOString() : '',
                'payment_reminder_anchor_at' => isset($definition['payment_at']) ? $definition['payment_at']->toISOString() : '',
                'last_payment_date' => isset($definition['payment_at']) ? $definition['payment_at']->toDateString() : '',
                'first_shipment_date' => in_array($key, ['partial', 'signed_partial_payment', 'paid', 'completed'], true) ? '2024-04-10' : '',
                'last_shipment_date' => in_array($key, ['paid', 'completed'], true) ? '2026-05-20' : '',
                'handover_date' => $definition['status_at']->format('Y-m-d'),
                'weight' => $finance['weight'],
                'contract_amount' => $finance['contract_amount'],
                'occurred_amount' => $finance['occurred_amount'],
                'paid_amount' => $finance['paid_amount'],
                'unpaid_amount' => $finance['unpaid_amount'],
                'reconciled_amount' => $finance['reconciled_amount'],
                'invoiced_amount' => $finance['invoiced_amount'],
                'uninvoiced_amount' => $finance['uninvoiced_amount'],
                'payment_progress' => $finance['payment_progress'],
                'payment_status' => $finance['payment_status'],
                'collection_count' => $finance['collection_count'],
                'risk' => $definition['risk'],
                'remark' => '本地全链路演示数据；客户、联系人、项目、金额和文件均为虚构。',
            ];
            if ($key === 'manual_amount') {
                $payload['contract_amount_source'] = 'manual';
                $payload['contract_amount_synced_at'] = now()->subDays(2)->toISOString();
                $payload['contract_amount_synced_by'] = $users['finance']->id;
            }

            $project = $this->upsertRecord(
                $writer,
                'project',
                "full-chain-project-{$key}",
                $payload,
                $users['admin'],
            );
            $project->update(['created_by' => $owner?->id]);

            return [$key => $project->refresh()];
        })->all();
    }

    /**
     * @param  array<string, array{customer: ObjectRecord, contacts: array<int, ObjectRecord>}>  $customers
     * @param  array<string, ObjectRecord>  $projects
     */
    private function contracts(
        CreateObjectRecord $writer,
        array $customers,
        array $projects,
    ): void {
        $letters = $this->documentPaths('demo-processing-letter-', 3);
        $contracts = $this->documentPaths('demo-contract-', 3);
        $statements = $this->documentPaths('demo-statement-', 10);
        $definitions = [
            ['key' => 'letter-due', 'project' => 'letter_due', 'customer' => 'guangdong', 'status' => '已有加工函', 'type' => '加工合同', 'amount' => 1680000, 'letters' => $letters, 'contracts' => [], 'statements' => array_slice($statements, 0, 2)],
            ['key' => 'partial-main', 'project' => 'partial', 'customer' => 'suzhou', 'status' => '已签署', 'type' => '加工合同', 'amount' => 5000000, 'letters' => [$letters[0]], 'contracts' => $contracts, 'statements' => array_slice($statements, 0, 5)],
            ['key' => 'partial-supplement', 'project' => 'partial', 'customer' => 'suzhou', 'status' => '已有加工函', 'type' => '补充协议', 'amount' => 800000, 'letters' => $letters, 'contracts' => [], 'statements' => array_slice($statements, 5, 5)],
            ['key' => 'signed-1', 'project' => 'signed_partial_payment', 'customer' => 'guangdong', 'status' => '已签署', 'type' => '加工合同', 'amount' => 2860000, 'letters' => [$letters[0]], 'contracts' => [$contracts[0]], 'statements' => array_slice($statements, 0, 3)],
            ['key' => 'signed-2', 'project' => 'signed_partial_payment', 'customer' => 'guangdong', 'status' => '已签署', 'type' => '补充协议', 'amount' => 460000, 'letters' => [$letters[1]], 'contracts' => [$contracts[1]], 'statements' => array_slice($statements, 3, 2)],
            ['key' => 'paid', 'project' => 'paid', 'customer' => 'shandong', 'status' => '已签署', 'type' => '加工合同', 'amount' => 1280000, 'letters' => [$letters[0]], 'contracts' => [$contracts[0]], 'statements' => [$statements[9]]],
            ['key' => 'completed', 'project' => 'completed', 'customer' => 'suzhou', 'status' => '已签署', 'type' => '框架合同', 'amount' => 3600000, 'letters' => [$letters[2]], 'contracts' => [$contracts[2]], 'statements' => array_slice($statements, 6, 4)],
            ['key' => 'manual-1', 'project' => 'manual_amount', 'customer' => 'shandong', 'status' => '未签署', 'type' => '加工合同', 'amount' => 700000, 'letters' => [], 'contracts' => [], 'statements' => []],
            ['key' => 'manual-2', 'project' => 'manual_amount', 'customer' => 'shandong', 'status' => '未签署', 'type' => '补充协议', 'amount' => 500000, 'letters' => [], 'contracts' => [], 'statements' => []],
        ];

        foreach ($definitions as $index => $definition) {
            $project = $projects[$definition['project']];
            $actor = User::find($project->created_by);
            $this->upsertRecord($writer, 'contract', "full-chain-contract-{$definition['key']}", [
                'customer_id' => $customers[$definition['customer']]['customer']->id,
                'project_id' => $project->id,
                'status' => $definition['status'],
                'ctype' => $definition['type'],
                'amount' => $definition['amount'],
                'signed_date' => $definition['status'] === '已签署' ? now()->subDays(70 - $index * 4)->format('Y-m-d') : '',
                'processing_letter_attachments' => $definition['letters'],
                'contract_attachments' => $definition['contracts'],
                'statement_attachments' => $definition['statements'],
                'contract_qty' => 100 + $index * 35,
                'contract_chase_record' => $definition['status'] === '已有加工函' ? '已催签2次（演示）' : '',
                'remark' => "全链路演示合同 {$definition['key']}；附件均为虚构样本。",
            ], $actor);
        }
    }

    private function seedNotificationHistory(ObjectRecord $completedProject, User $admin): void
    {
        ProjectNotification::updateOrCreate(
            [
                'project_id' => $completedProject->id,
                'type' => ProjectNotification::TYPE_PAYMENT,
                'user_id' => $admin->id,
            ],
            [
                'status' => ProjectNotification::STATUS_ACTIVE,
                'read_at' => now()->subDays(45),
                'resolved_at' => null,
                'triggered_at' => now()->subDays(60),
                'occurrences' => 2,
            ],
        );
    }

    /** @param array<string, ObjectRecord> $projects */
    private function shapeNotificationExamples(array $projects): void
    {
        ProjectNotification::query()
            ->where('project_id', $projects['bid_due']->id)
            ->where('type', ProjectNotification::TYPE_BID)
            ->whereRelation('recipient.roles', 'name', 'admin')
            ->update(['read_at' => now()->subHour()]);

        ProjectNotification::query()
            ->whereIn('project_id', [$projects['letter_due']->id, $projects['partial']->id])
            ->whereIn('type', [ProjectNotification::TYPE_SIGNATURE, ProjectNotification::TYPE_PAYMENT])
            ->update(['occurrences' => 3]);
    }

    private function upsertRecord(
        CreateObjectRecord $writer,
        string $objectKey,
        string $demoKey,
        array $payload,
        ?User $user,
    ): ObjectRecord {
        $object = $this->object($objectKey);
        $payload['_demo_key'] = $demoKey;
        $record = $object->records()->where('payload->_demo_key', $demoKey)->first();
        if (! $record) {
            return $writer->handle($object, $payload, $user, 'demo.full_chain.create');
        }

        if ($objectKey === 'project') {
            foreach ([
                'overall_status_changed_at',
                'processing_letter_at',
                'payment_reminder_anchor_at',
                'collection_count',
            ] as $systemKey) {
                if (array_key_exists($systemKey, $record->payload ?? [])) {
                    $payload[$systemKey] = $record->payload[$systemKey];
                }
            }
        }
        $payload = $writer->normalizePayload($object, $payload, $record->payload ?? []);
        $record->update([
            'title' => $object->title_field === 'code'
                ? $record->title
                : (string) ($payload[$object->title_field] ?? $record->title),
            'payload' => $payload,
            'created_by' => $user?->id,
        ]);

        return $record->refresh();
    }

    /** @return array<string, float|int|string|null> */
    private function financialScenario(string $key): array
    {
        $scenario = match ($key) {
            'partial' => [5800000, 4200000, 2900000, 1300000, 3600000, 3100000, 1100000, 69.05, '部分回款', 3, 1680],
            'letter_due' => [1680000, 680000, 0, 680000, 260000, 0, 680000, 0, '未回款', 0, 520],
            'signed_partial_payment' => [3320000, 2960000, 1280000, 1680000, 2380000, 1800000, 1160000, 43.24, '部分回款', 1, 910],
            'paid' => [1280000, 1280000, 1280000, 0, 1280000, 1280000, 0, 100, '已回款', 2, 360],
            'completed' => [3600000, 3580000, 3580000, 0, 3580000, 3580000, 0, 100, '已回款', 5, 1250],
            'manual_amount' => [1000000, 280000, 10000, 270000, 200000, 90004, 189996, 3.57, '部分回款', 0, 280],
            default => [null, 0, 0, 0, 0, 0, 0, 0, '未回款', 0, 200],
        };

        return array_combine([
            'contract_amount',
            'occurred_amount',
            'paid_amount',
            'unpaid_amount',
            'reconciled_amount',
            'invoiced_amount',
            'uninvoiced_amount',
            'payment_progress',
            'payment_status',
            'collection_count',
            'weight',
        ], $scenario);
    }

    /** @return array<int, string> */
    private function documentPaths(string $prefix, int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index): string => sprintf(
                'attachments/full-chain-%s%02d.pdf',
                $prefix,
                $index,
            ))
            ->all();
    }

    private function object(string $key): BusinessObject
    {
        return BusinessObject::where('key', $key)->firstOrFail();
    }
}
