<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\MaterialNames;
use App\Support\ObjectRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateObjectRecord
{
    public function __construct(
        private AllocateObjectCode $codes,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectFinance $projectFinance,
        private SyncProjectNotifications $projectNotifications,
        private MaterialNames $materialNames,
        private ObjectRelations $relations,
    ) {}

    public function handle(
        BusinessObject $object,
        array $payload,
        ?User $user = null,
        string $action = 'object.create',
        ?string $workflowKey = null,
        array $workflowTargetRoles = [],
    ): ObjectRecord {
        return DB::transaction(function () use (
            $object,
            $payload,
            $user,
            $action,
            $workflowKey,
            $workflowTargetRoles,
        ): ObjectRecord {
            $this->relations->lockReferenceGraph();
            $object = BusinessObject::query()->lockForUpdate()->findOrFail($object->id);
            $payload = $this->normalizePayload($object, $payload, user: $user);
            $payload = $this->materialNames->normalizeAndGuardUnique($object, $payload);
            $this->relations->validatePayloadRelations($object, $payload, $user);
            $this->relations->validateItemRelations($object, $payload, $user);
            $project = null;
            if (in_array($object->key, ['receivable', 'contract'], true)) {
                $project = $this->projectFinance->lockedProjectOrFail($payload['project_id'] ?? null);
            }
            if ($object->key === 'receivable') {
                $payload = $this->projectFinance->normalizePayload($payload, $project);
                $this->projectFinance->guardUnique($payload['project_id'] ?? null, null, $project);
            }
            if ($object->key === 'contract') {
                $payload = $this->projectFinance->fillContractProjectDefaults($payload, project: $project);
            }
            if ($project) {
                $this->projectFinance->guardCustomerMatchesProject($project, $payload['customer_id'] ?? null);
            }

            $code = $this->nextCode($object);
            $payload = $this->fillSystemCode($object, $payload, $code);
            $record = ObjectRecord::create([
                'business_object_id' => $object->id,
                'code' => $code,
                'title' => $this->title($object, $payload),
                'payload' => $payload,
                'workflow_key' => $workflowKey,
                'workflow_target_roles' => $workflowTargetRoles ?: null,
                'created_by' => $user?->id,
            ]);

            if ($object->key === 'production_team') {
                $this->validateProductionTeamLeader($record, $payload);
            }

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $object->key,
                'subject_id' => $record->id,
                'payload' => ['code' => $record->code, 'title' => $record->title],
            ]);

            if ($object->key === 'contract') {
                $this->contractAmount->handle($payload['project_id'] ?? null);
            }

            if ($object->key === 'contract') {
                $this->projectNotifications->handleProjects([$payload['project_id'] ?? null]);
            }

            return $record;
        });
    }

    public function normalizePayload(
        BusinessObject $object,
        array $payload,
        array $existingPayload = [],
        ?User $user = null,
    ): array {
        if ($object->key === 'tender') {
            $payload = $this->normalizeTender($payload, $user);
        }

        $payload = match ($object->key) {
            'drawing' => $this->normalizeDrawing($payload),
            'work_order' => $this->fillWorkOrderFromTeam(
                $this->fillWorkOrderFromDrawing($payload, $existingPayload),
                $existingPayload,
            ),
            'teardown' => $this->fillTeardownFromDrawing($payload, $existingPayload),
            'team_log' => $this->fillTeamLogFromTeam($payload, $existingPayload),
            default => $payload,
        };

        $payload = $this->roundProjectNumbers($object, $payload);
        $payload = $this->fillProjectNumber($object, $payload, $existingPayload);

        return $this->snapshotRelations($object, $payload, $existingPayload);
    }

    private function roundProjectNumbers(BusinessObject $object, array $payload): array
    {
        if ($object->key !== 'project') {
            return $payload;
        }

        foreach ($object->fields ?? [] as $field) {
            $key = $field['key'] ?? null;
            if (($field['type'] ?? null) !== 'number'
                || ! is_string($key)
                || ! array_key_exists($key, $payload)
                || $payload[$key] === null
                || $payload[$key] === '') {
                continue;
            }

            $payload[$key] = round((float) $payload[$key], 2);
        }

        return $payload;
    }

    private function normalizeTender(array $payload, ?User $user): array
    {
        $payload['status'] = trim((string) ($payload['status'] ?? '')) ?: '跟踪中';
        $payload['purchase_status'] = trim((string) ($payload['purchase_status'] ?? '')) ?: '未购买';

        $customerReference = trim((string) ($payload['customer_id'] ?? ''));
        if ($customerReference === '' || (Str::isUuid($customerReference)
            && ObjectRecord::whereKey($customerReference)
                ->whereRelation('businessObject', 'key', 'customer')->exists())) {
            return $payload;
        }

        if (! $user?->canDo('object.customer.create')) {
            throw ValidationException::withMessages([
                'payload.customer_id' => '当前用户无权新建客户。',
            ]);
        }

        $customerObject = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $existingCustomer = $customerObject->records()
            ->where('title', $customerReference)
            ->first();
        if ($existingCustomer) {
            $payload['customer_id'] = $existingCustomer->id;

            return $payload;
        }

        $customer = $this->handle(
            $customerObject,
            ['name' => $customerReference],
            $user,
            action: 'object.create.related',
        );
        $payload['customer_id'] = $customer->id;

        return $payload;
    }

    public function nextCode(BusinessObject $object): string
    {
        return $this->codes->handle($object);
    }

    private function title(BusinessObject $object, array $payload): string
    {
        if ($object->title_field === 'code') {
            return $object->label;
        }

        return (string) ($payload[$object->title_field] ?? $payload['name'] ?? $object->label);
    }

    private function fillWorkOrderFromDrawing(array $payload, array $existingPayload = []): array
    {
        if (trim((string) ($payload['release_status'] ?? '')) === '') {
            $payload['release_status'] = $existingPayload['release_status'] ?? '未下放';
        }

        $drawing = $this->linkedRecord($payload['drawing_id'] ?? null, 'drawing');
        if (! $drawing) {
            return $payload;
        }

        if (($existingPayload['drawing_id'] ?? null) === ($payload['drawing_id'] ?? null)) {
            foreach (['project_id', 'project_no', 'drawing_no', 'drawing_name'] as $key) {
                if (array_key_exists($key, $existingPayload)) {
                    $payload[$key] = $existingPayload[$key];
                }
            }

            return $payload;
        }

        if (($drawing->payload['design_status'] ?? null) !== '已下放') {
            throw ValidationException::withMessages([
                'payload.drawing_id' => '图纸编号必须选择已下放的技术图纸。',
            ]);
        }

        $drawingPayload = $drawing->payload ?? [];
        $payload['project_id'] = $drawingPayload['project_id'] ?? ($payload['project_id'] ?? '');
        $payload['project_no'] = $drawingPayload['project_no'] ?? $drawingPayload['project_no_norm'] ?? ($payload['project_no'] ?? '');
        $payload['drawing_no'] = $drawingPayload['drawing_no'] ?? $drawing->code;
        $payload['drawing_name'] = $drawingPayload['name'] ?? $drawing->title;

        return $payload;
    }

    private function fillTeardownFromDrawing(array $payload, array $existingPayload = []): array
    {
        $drawing = $this->linkedRecord($payload['drawing_id'] ?? null, 'drawing');
        if (! $drawing) {
            return $payload;
        }

        if (($existingPayload['drawing_id'] ?? null) === ($payload['drawing_id'] ?? null)) {
            foreach (['drawing_name', 'project_id', 'project_no'] as $key) {
                if (array_key_exists($key, $existingPayload)) {
                    $payload[$key] = $existingPayload[$key];
                }
            }

            return $payload;
        }

        $drawingPayload = $drawing->payload ?? [];
        $payload['drawing_name'] = $drawingPayload['name'] ?? $drawing->title;
        $payload['project_id'] = $drawingPayload['project_id'] ?? null;

        return $payload;
    }

    private function fillWorkOrderFromTeam(array $payload, array $existingPayload = []): array
    {
        $teamId = $payload['team_id'] ?? null;
        if (! is_string($teamId) || $teamId === '') {
            $payload['team_leader_name'] = '';

            return $payload;
        }

        $keepsExistingTeam = ($existingPayload['team_id'] ?? null) === $teamId;
        if ($keepsExistingTeam && array_key_exists('team_leader_name', $existingPayload)) {
            $payload['team_leader_name'] = $existingPayload['team_leader_name'];

            return $payload;
        }

        $team = $this->linkedRecord($teamId, 'production_team');
        if (! $team || (($team->payload['status'] ?? '启用') === '停用' && ! $keepsExistingTeam)) {
            throw ValidationException::withMessages([
                'payload.team_id' => '加工班组必须选择当前启用的生产班组。',
            ]);
        }

        $leader = $this->linkedRecord($team->payload['leader_id'] ?? null, 'team_member');
        $payload['team_leader_name'] = $leader
            && ($leader->payload['status'] ?? '启用') !== '停用'
            && ($leader->payload['team_id'] ?? null) === $team->id
                ? (string) (($leader->payload['name'] ?? '') ?: $leader->title)
                : '';

        return $payload;
    }

    private function normalizeDrawing(array $payload): array
    {
        if (trim((string) ($payload['design_status'] ?? '')) === '') {
            $payload['design_status'] = '草稿';
        }

        if (($payload['design_status'] ?? null) === '已下放' && empty($payload['release_date'])) {
            $payload['release_date'] = now()->format('Y-m-d');
        }

        return $payload;
    }

    private function fillTeamLogFromTeam(array $payload, array $existingPayload = []): array
    {
        $team = $this->linkedRecord($payload['team_id'] ?? null, 'production_team');
        $keepsExistingTeam = is_string($payload['team_id'] ?? null)
            && ($existingPayload['team_id'] ?? null) === $payload['team_id'];
        if (! $team || (($team->payload['status'] ?? '启用') === '停用' && ! $keepsExistingTeam)) {
            throw ValidationException::withMessages([
                'payload.team_id' => '班组名称必须选择当前启用的生产班组。',
            ]);
        }

        if ($keepsExistingTeam && array_key_exists('team_leader_name', $existingPayload)) {
            $payload['team_leader_name'] = $existingPayload['team_leader_name'];

            return $payload;
        }

        if (! array_key_exists('team_leader_name', $payload)) {
            $leader = $this->linkedRecord($team->payload['leader_id'] ?? null, 'team_member');
            $payload['team_leader_name'] = $leader
                ? (string) (($leader->payload['name'] ?? '') ?: $leader->title)
                : '';
        }

        return $payload;
    }

    public function validateProductionTeamLeader(ObjectRecord $team, array $payload): void
    {
        $leaderId = $payload['leader_id'] ?? null;
        if (! $leaderId) {
            return;
        }

        $leader = $this->linkedRecord($leaderId, 'team_member');
        if (! $leader
            || ($leader->payload['status'] ?? '启用') === '停用'
            || ($leader->payload['team_id'] ?? null) !== $team->id) {
            throw ValidationException::withMessages([
                'payload.leader_id' => '班组负责人必须是当前班组中已启用的成员。',
            ]);
        }
    }

    public function validateTeamMemberChange(ObjectRecord $member, ?array $payload): void
    {
        $leaderTeams = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'production_team')
            ->where('payload->leader_id', $member->id)
            ->get();
        if ($leaderTeams->isEmpty()) {
            return;
        }

        $valid = $payload !== null
            && ($payload['status'] ?? '启用') !== '停用'
            && $leaderTeams->every(fn (ObjectRecord $team) => ($payload['team_id'] ?? null) === $team->id);
        if (! $valid) {
            throw ValidationException::withMessages([
                'payload.team_id' => '该成员是班组负责人，请先为对应班组更换负责人后再停用、调组或删除。',
            ]);
        }
    }

    private function fillSystemCode(BusinessObject $object, array $payload, string $code): array
    {
        foreach ($object->fields ?? [] as $field) {
            if (($field['system'] ?? null) === 'code') {
                $payload[$field['key']] = $code;
            }
        }

        if ($object->key === 'material' && empty($payload['material_code'])) {
            $payload['material_code'] = $code;
        }

        return $payload;
    }

    private function fillProjectNumber(BusinessObject $object, array $payload, array $existingPayload = []): array
    {
        $fields = collect($object->fields ?? [])->keyBy('key');
        if (! $fields->has('project_id') || ! $fields->has('project_no')) {
            unset($payload['project_no_norm']);

            return $payload;
        }

        if (($existingPayload['project_id'] ?? null) === ($payload['project_id'] ?? null)
            && array_key_exists('project_no', $existingPayload)) {
            $payload['project_no'] = $existingPayload['project_no'];
        } else {
            $project = $this->linkedRecord($payload['project_id'] ?? null, 'project');
            $payload['project_no'] = $project
                ? (string) (($project->payload['project_no'] ?? '') ?: $project->code)
                : '';
        }
        unset($payload['project_no_norm']);

        return $payload;
    }

    private function linkedRecord(?string $id, string $objectKey): ?ObjectRecord
    {
        if (! $id) {
            return null;
        }

        return ObjectRecord::whereKey($id)
            ->whereRelation('businessObject', 'key', $objectKey)
            ->first();
    }

    private function snapshotRelations(BusinessObject $object, array $payload, array $existingPayload): array
    {
        $fields = collect($object->fields ?? [])->filter(fn (array $field) => in_array($field['type'] ?? null, ['relation', 'creatable_relation', 'multirelation'], true)
            && ! empty($field['target'])
        );
        if ($fields->isEmpty()) {
            return $payload;
        }

        $commonFields = $fields->reject(fn (array $field) => ($field['scope'] ?? null) === 'item');
        $itemFields = $fields->filter(fn (array $field) => ($field['scope'] ?? null) === 'item');
        $relationIds = $commonFields
            ->flatMap(function (array $field) use ($payload): array {
                $value = $payload[$field['key']] ?? null;

                return ($field['type'] ?? null) === 'multirelation' && is_array($value)
                    ? $value
                    : [$value];
            })
            ->concat(collect($payload['items'] ?? [])->flatMap(
                fn (array $item) => $itemFields->flatMap(function (array $field) use ($item): array {
                    $value = $item[$field['key']] ?? null;

                    return ($field['type'] ?? null) === 'multirelation' && is_array($value)
                        ? $value
                        : [$value];
                }),
            ))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $records = ObjectRecord::with('businessObject')
            ->whereIn('id', $relationIds->all())
            ->get()
            ->keyBy('id');

        $rootSnapshots = [];
        foreach ($commonFields as $field) {
            $key = $field['key'];
            $id = $payload[$key] ?? null;
            if (($field['type'] ?? null) === 'multirelation') {
                $previousById = collect($existingPayload['_snapshots'][$key] ?? [])
                    ->filter(fn ($snapshot) => is_array($snapshot)
                        && is_string($snapshot['id'] ?? null)
                        && is_string($snapshot['label'] ?? null))
                    ->keyBy('id');
                $snapshots = collect(is_array($id) ? $id : [])
                    ->filter(fn ($relatedId) => is_string($relatedId) && $relatedId !== '')
                    ->map(function (string $relatedId) use ($previousById, $records, $field): ?array {
                        $previous = $previousById->get($relatedId);
                        if (is_array($previous)) {
                            return $previous;
                        }

                        $record = $records->get($relatedId);
                        if ($record?->businessObject?->key !== ($field['target'] ?? null)) {
                            return null;
                        }

                        return ['id' => $relatedId, 'label' => $this->snapshotLabel($record)];
                    })
                    ->filter()
                    ->values()
                    ->all();
                if ($snapshots !== []) {
                    $rootSnapshots[$key] = $snapshots;
                }

                continue;
            }
            if (! is_string($id) || $id === '') {
                continue;
            }

            $previous = $existingPayload['_snapshots'][$key] ?? null;
            if (is_array($previous) && ($previous['id'] ?? null) === $id && is_string($previous['label'] ?? null)) {
                $rootSnapshots[$key] = $previous;

                continue;
            }

            $record = $records->get($id);
            if ($record?->businessObject?->key === ($field['target'] ?? null)) {
                $rootSnapshots[$key] = ['id' => $id, 'label' => $this->snapshotLabel($record)];
            }
        }
        if ($rootSnapshots) {
            $payload['_snapshots'] = $rootSnapshots;
        } else {
            unset($payload['_snapshots']);
        }

        if ($itemFields->isEmpty() || ! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $existingItems = collect($existingPayload['items'] ?? [])->keyBy('id');

        $payload['items'] = collect($payload['items'])->map(function (array $item) use ($itemFields, $records, $existingItems): array {
            $snapshots = [];
            $existing = $existingItems->get($item['id'] ?? null, []);

            foreach ($itemFields as $field) {
                $key = $field['key'];
                $id = $item[$key] ?? null;
                if (! is_string($id) || $id === '') {
                    continue;
                }

                $previous = $existing['_snapshots'][$key] ?? null;
                if (is_array($previous) && ($previous['id'] ?? null) === $id && is_string($previous['label'] ?? null)) {
                    $snapshots[$key] = $previous;

                    continue;
                }

                $record = $records->get($id);
                if ($record?->businessObject?->key !== ($field['target'] ?? null)) {
                    continue;
                }

                $snapshots[$key] = [
                    'id' => $id,
                    'label' => $this->snapshotLabel($record),
                ];
            }

            if ($snapshots) {
                $item['_snapshots'] = $snapshots;
            } else {
                unset($item['_snapshots']);
            }

            return $item;
        })->values()->all();

        return $payload;
    }

    private function snapshotLabel(ObjectRecord $record): string
    {
        return match ($record->businessObject?->key) {
            'material' => (string) (($record->payload['name'] ?? '') ?: $record->title ?: $record->code),
            'project' => collect([$record->payload['project_no'] ?? $record->code, $record->title])->filter()->implode(' · '),
            'drawing' => collect([$record->payload['drawing_no'] ?? $record->code, $record->title])->filter()->implode(' · '),
            default => (string) ($record->title ?: $record->code),
        };
    }
}
