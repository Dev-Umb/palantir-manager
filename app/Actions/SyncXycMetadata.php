<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\Permission;
use App\Models\Role;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncXycMetadata
{
    public function __construct(private SyncProjectContractAmount $contractSync) {}

    public function handle(?Closure $afterPrune = null): void
    {
        DB::transaction(function () use ($afterPrune): void {
            $this->sync($afterPrune);
        });
    }

    private function sync(?Closure $afterPrune): void
    {
        $config = config('xyc');
        $now = now();

        Role::upsert(
            collect($config['roles'])->map(fn (array $role) => [
                'name' => $role['name'],
                'label' => $role['label'],
                'description' => $role['description'] ?? null,
                'locked' => $role['locked'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['name'],
            ['label', 'description', 'locked', 'updated_at'],
        );

        $permissions = collect($config['permissions']);
        foreach ($config['objects'] as $object) {
            if ($object['archived'] ?? false) {
                continue;
            }

            $actions = $object['read_only'] ?? false
                ? ['view' => '查看']
                : ['view' => '查看', 'create' => '新建', 'update' => '编辑', 'delete' => '删除'];
            foreach ($actions as $action => $label) {
                $permissions->push([
                    'key' => "object.{$object['key']}.{$action}",
                    'module' => $object['key'],
                    'action' => $action,
                    'label' => "{$label}{$object['label']}",
                ]);
            }
        }

        Permission::upsert(
            $permissions->map(fn (array $permission) => [
                'key' => $permission['key'],
                'module' => $permission['module'],
                'action' => $permission['action'],
                'label' => $permission['label'],
                'description' => $permission['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['module', 'action', 'label', 'description', 'updated_at'],
        );

        BusinessObject::upsert(
            collect($config['objects'])->map(fn (array $object, int $index) => [
                'key' => $object['key'],
                'label' => $object['label'],
                'group' => $object['group'],
                'code_prefix' => $object['code_prefix'],
                'title_field' => $object['title_field'],
                'fields' => json_encode($object['fields'], JSON_UNESCAPED_UNICODE),
                'roles' => json_encode($object['roles'], JSON_UNESCAPED_UNICODE),
                'read_only' => $object['read_only'] ?? false,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['label', 'group', 'code_prefix', 'title_field', 'fields', 'roles', 'read_only', 'sort_order', 'updated_at'],
        );

        $this->pruneRemovedObjects(collect($config['objects'])->pluck('key'));
        $afterPrune?->__invoke();
        Permission::whereNotIn('key', $permissions->pluck('key'))->delete();

        $permissionByKey = Permission::whereIn('key', $permissions->pluck('key'))->get()->keyBy('key')->toBase();

        $this->syncRolePermissions($permissionByKey);
        $this->retireRoles();
        $this->syncRenamedPayloadFields();
        $this->syncPurchaseTaskIds();
        $this->syncDrawingStatuses();
        $this->syncProjectReferencePayloads();
        $this->syncShipmentStages();
        $this->syncBusinessContractWorkspace();
    }

    /** @param Collection<string, Permission> $permissionByKey */
    private function syncRolePermissions(Collection $permissionByKey): void
    {
        $config = config('xyc');
        $objects = collect($config['objects']);
        $rows = [];

        foreach (Role::all() as $role) {
            $keys = $config['role_permissions'][$role->name] ?? [];

            if ($keys === ['*']) {
                foreach ($permissionByKey->pluck('id') as $permissionId) {
                    $rows[] = ['role_id' => $role->id, 'permission_id' => $permissionId];
                }

                continue;
            }

            $keys = collect($keys);
            foreach ($objects as $object) {
                $writeRoles = $object['write_roles'] ?? $object['roles'];

                if (in_array($role->name, $object['roles'], true) || in_array($role->name, $writeRoles, true)) {
                    $keys = $keys->push("object.{$object['key']}.view");
                }

                if (in_array($role->name, $writeRoles, true)) {
                    $keys = $keys->merge([
                        "object.{$object['key']}.create",
                        "object.{$object['key']}.update",
                        "object.{$object['key']}.delete",
                    ]);
                }
            }

            foreach ($permissionByKey->only($keys->unique()->all())->pluck('id') as $permissionId) {
                $rows[] = ['role_id' => $role->id, 'permission_id' => $permissionId];
            }
        }

        DB::table('permission_role')->delete();

        if ($rows) {
            DB::table('permission_role')->insert($rows);
        }
    }

    private function retireRoles(): void
    {
        Role::whereIn('name', config('xyc.retired_roles', []))
            ->withCount('users')
            ->get()
            ->each(function (Role $role): void {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'metadata.roles.retire',
                    'subject_type' => 'role',
                    'subject_id' => (string) $role->id,
                    'payload' => [
                        'name' => $role->name,
                        'users_count' => $role->users_count,
                    ],
                ]);

                $role->delete();
            });
    }

    private function syncProjectReferencePayloads(): void
    {
        $projectObject = BusinessObject::where('key', 'project')->first();
        if (! $projectObject) {
            return;
        }

        $projects = $projectObject->records()->get();
        $projects->each(function ($record): void {
            if (($record->payload['project_no'] ?? null) !== $record->code) {
                $record->update(['payload' => [
                    ...($record->payload ?? []),
                    'project_no' => $record->code,
                ]]);
            }
        });
        $projectNumbers = $projects
            ->mapWithKeys(fn ($record) => [$record->id => $record->code])
            ->all();

        BusinessObject::all()
            ->filter(function (BusinessObject $object) {
                $fields = collect($object->fields ?? [])->keyBy('key');

                return ($fields['project_id']['target'] ?? null) === 'project' && $fields->has('project_no');
            })
            ->each(function (BusinessObject $object) use ($projectNumbers) {
                $object->records()->each(function ($record) use ($projectNumbers) {
                    $payload = $record->payload ?? [];
                    $projectNo = $projectNumbers[$payload['project_id'] ?? ''] ?? '';
                    $oldPayload = $payload;

                    $payload['project_no'] = $projectNo;
                    unset($payload['project_no_norm']);

                    if ($payload !== $oldPayload) {
                        $record->update(['payload' => $payload]);
                    }
                });
            });
    }

    private function syncRenamedPayloadFields(): void
    {
        $aliases = [
            'project' => [
                'signed_qty' => 'signed_weight',
                'arrears' => 'unpaid_amount',
            ],
            'receivable' => [
                'signed_qty' => 'signed_weight',
                'actual_amount' => 'occurred_amount',
                'actual_amount_updated_at' => 'occurred_amount_updated_at',
                'unpaid' => 'unpaid_amount',
                'invoice_amount' => 'invoiced_amount',
            ],
            'purchase' => [
                'tonnage' => 'weight_ton',
            ],
        ];

        BusinessObject::whereIn('key', array_keys($aliases))->get()->each(function (BusinessObject $object) use ($aliases) {
            $object->records()->each(function ($record) use ($aliases, $object) {
                $payload = $record->payload ?? [];
                $oldPayload = $payload;

                foreach ($aliases[$object->key] as $oldKey => $newKey) {
                    if (! array_key_exists($newKey, $payload) && array_key_exists($oldKey, $payload)) {
                        $payload[$newKey] = $payload[$oldKey];
                    }
                }

                if ($payload !== $oldPayload) {
                    $record->update(['payload' => $payload]);
                }
            });
        });
    }

    private function syncDrawingStatuses(): void
    {
        $drawing = BusinessObject::where('key', 'drawing')->first();
        if (! $drawing) {
            return;
        }

        $drawing->records()
            ->where('payload->design_status', '已完成')
            ->each(function ($record): void {
                $record->update(['payload' => [
                    ...($record->payload ?? []),
                    'design_status' => '已下放',
                ]]);
            });
    }

    private function syncPurchaseTaskIds(): void
    {
        $purchase = BusinessObject::where('key', 'purchase')->first();
        if (! $purchase) {
            return;
        }

        $purchase->records()->each(function ($record): void {
            if (($record->payload['task_id'] ?? null) === $record->code) {
                return;
            }

            $record->update(['payload' => [
                ...($record->payload ?? []),
                'task_id' => $record->code,
            ]]);
        });
    }

    private function syncShipmentStages(): void
    {
        $shipment = BusinessObject::where('key', 'shipment')->first();
        $project = BusinessObject::where('key', 'project')->first();
        if (! $shipment || ! $project) {
            return;
        }

        $projectIds = $shipment->records()
            ->whereNotNull('payload->ship_date')
            ->where('payload->ship_date', '!=', '')
            ->get()
            ->pluck('payload.project_id')
            ->filter()
            ->unique();

        $project->records()
            ->whereIn('id', $projectIds)
            ->where('payload->stage', '成品发货')
            ->each(fn ($record) => $record->update([
                'payload' => [...($record->payload ?? []), 'stage' => '发货签收'],
            ]));
    }

    private function syncBusinessContractWorkspace(): void
    {
        $businessUserIds = Role::query()
            ->where('name', 'business')
            ->first()?->users()
            ->pluck('users.id')
            ->map(fn (int $id): string => (string) $id)
            ->all() ?? [];
        $businessUserLookup = array_flip($businessUserIds);
        $projectObject = BusinessObject::query()->where('key', 'project')->first();
        $contractObject = BusinessObject::query()->where('key', 'contract')->first();

        $projectObject?->records()->each(function ($project) use ($businessUserLookup): void {
            $payload = $project->payload ?? [];
            $before = $payload;
            $payload['overall_status'] = match ($payload['overall_status'] ?? null) {
                '投标中', '已中标', '已拿到加工函', '合同签署', '已完成' => $payload['overall_status'],
                default => '投标中',
            };
            $payload['overall_status_changed_at'] ??= now()->toISOString();
            $payload['contract_status'] ??= '未签署';
            $payload['collection_count'] = (int) ($payload['collection_count'] ?? 0);
            if (! array_key_exists('business_owner_user_id', $payload)
                && $project->created_by
                && isset($businessUserLookup[(string) $project->created_by])) {
                $payload['business_owner_user_id'] = (string) $project->created_by;
            }
            if (is_numeric($payload['contract_amount'] ?? null)
                && ! isset($payload['contract_amount_source'])) {
                $payload['contract_amount_source'] = 'manual';
            }
            if ($payload !== $before) {
                $project->update(['payload' => $payload]);
            }
        });

        $contractObject?->records()->each(function ($contract): void {
            $payload = $contract->payload ?? [];
            $status = $payload['status'] ?? '未签署';
            if (! in_array($status, ['未签署', '已有加工函', '已签署'], true)) {
                $payload['legacy_status'] = $status;
                $payload['status'] = '未签署';
            }
            foreach (['processing_letter_attachments', 'contract_attachments', 'statement_attachments'] as $key) {
                $payload[$key] = collect($payload[$key] ?? [])->filter(fn ($path): bool => is_string($path) && $path !== '')->values()->all();
            }
            $contract->update(['payload' => $payload]);
        });

        $projectObject?->records()->pluck('id')->each(
            fn (string $projectId) => $this->contractSync->handle($projectId),
        );
    }

    /** @param Collection<int, string> $configuredKeys */
    private function pruneRemovedObjects(Collection $configuredKeys): void
    {
        $removed = BusinessObject::whereNotIn('key', $configuredKeys)
            ->withCount('records')
            ->orderBy('key')
            ->get();

        if ($removed->isEmpty()) {
            return;
        }

        AuditLog::create([
            'user_id' => null,
            'action' => 'metadata.objects.prune',
            'subject_type' => 'metadata',
            'subject_id' => null,
            'payload' => [
                'objects' => $removed->map(fn (BusinessObject $object) => [
                    'key' => $object->key,
                    'records_count' => $object->records_count,
                ])->values()->all(),
            ],
        ]);

        BusinessObject::whereIn('id', $removed->pluck('id'))->delete();
    }
}
