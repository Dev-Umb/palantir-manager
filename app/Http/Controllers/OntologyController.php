<?php

namespace App\Http\Controllers;

use App\Actions\AcknowledgeWorkflowTask;
use App\Actions\AdvanceProjectWorkflow;
use App\Actions\CreateObjectRecord;
use App\Actions\ResolveInboundMaterials;
use App\Actions\SyncProjectContractAmount;
use App\Actions\SyncProjectFinance;
use App\Actions\SyncProjectInvoiceAmount;
use App\Actions\SyncProjectNotifications;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\MaterialNames;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OntologyController extends Controller
{
    public function __construct(
        private ObjectRelations $relations,
        private ProjectVisibility $projectVisibility,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectInvoiceAmount $invoiceAmount,
        private SyncProjectFinance $projectFinance,
        private SyncProjectNotifications $projectNotifications,
        private ResolveInboundMaterials $inboundMaterials,
        private MaterialNames $materialNames,
        private AdvanceProjectWorkflow $projectWorkflow,
        private AcknowledgeWorkflowTask $workflowTasks,
    ) {}

    public function index(Request $request, ?string $object = null): Response|RedirectResponse
    {
        $permissions = $request->user()->permissionKeys();
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $objects = BusinessObject::orderBy('sort_order')->get();
        $visible = $objects->filter(fn (BusinessObject $item) => $can("object.{$item->key}.view"))->values();
        abort_if($visible->isEmpty(), 403);

        $current = $object
            ? $visible->firstWhere('key', $object)
            : $visible->first();

        abort_unless($current, 403);

        if ($current->key === 'customer_contact') {
            return redirect()->route('objects.index', 'customer');
        }

        $recordsQuery = $this->authorizedRecordsQuery($current, $request)
            ->with('businessObject')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $selected = $request->filled('record')
            ? (clone $recordsQuery)->whereKey((string) $request->query('record'))->first()
            : null;
        if ($selected && $request->query('mode') === 'detail') {
            $this->workflowTasks->handle($selected, $request->user());
            $selected->refresh();
        }
        $this->applySearch($recordsQuery, $current, $this->searchQuery($request));
        $this->applySort($recordsQuery, $current, $request);
        $records = $recordsQuery
            ->paginate($this->perPage($request))
            ->withQueryString();
        $recordsForLabels = $records->getCollection();
        if ($selected && ! $recordsForLabels->contains('id', $selected->id)) {
            $recordsForLabels = $recordsForLabels->concat([$selected]);
        }
        $this->relations->preloadLabels($recordsForLabels, $request->user());

        return Inertia::render('Ontology/Index', [
            'objects' => $visible,
            'currentObject' => $current,
            'records' => $records->through(fn (ObjectRecord $record) => $this->relations->formatRecord($record, $request->user())),
            'relationOptions' => $this->relations->optionsFor($current, $objects, $request->user(), $selected),
            'selectedRecordId' => $selected?->id,
            'selectedRecord' => $selected ? $this->relations->formatRecord($selected, $request->user()) : null,
            'can' => [
                'create' => $can("object.{$current->key}.create") && ! $current->read_only,
                'update' => $can("object.{$current->key}.update") && ! $current->read_only,
                'delete' => $can("object.{$current->key}.delete") && ! $current->read_only,
            ],
        ]);
    }

    public function exportCsv(Request $request, string $object): StreamedResponse
    {
        $current = BusinessObject::where('key', $object)->firstOrFail();
        abort_unless($request->user()->canDo("object.{$current->key}.view"), 403);

        $query = $this->authorizedRecordsQuery($current, $request)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        $this->applySearch($query, $current, $this->searchQuery($request));
        $this->applySort($query, $current, $request);
        $fields = collect($current->fields ?? [])->values();
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', $current->key) ?: 'records';

        return response()->streamDownload(function () use ($query, $fields, $current): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $fields->pluck('label')->all(), ',', '"', '');

            $writeBatch = function ($records) use ($output, $fields, $current): void {
                $records->each(fn (ObjectRecord $record) => $record->setRelation('businessObject', $current));
                $this->relations->preloadRelationLabels($records);

                foreach ($records as $record) {
                    $payload = $record->payload ?? [];
                    $hasItemFields = $fields->contains(fn (array $field) => ($field['scope'] ?? null) === 'item');
                    $items = $hasItemFields && is_array($payload['items'] ?? null) && $payload['items'] !== []
                        ? $payload['items']
                        : [null];

                    foreach ($items as $item) {
                        $item = is_array($item) ? $item : [];
                        $row = $fields->map(function (array $field) use ($record, $payload, $item) {
                            $container = ($field['scope'] ?? null) === 'item' ? $item : $payload;
                            $value = match ($field['system'] ?? null) {
                                'code' => $record->code,
                                'title' => $record->title,
                                default => in_array($field['type'] ?? null, ['relation', 'creatable_relation', 'multirelation'], true)
                                    ? $this->relations->relationDisplayValue($container, $field)
                                    : ($container[$field['key']] ?? null),
                            };

                            return $this->safeCsvCell($value);
                        })->all();
                        fputcsv($output, $row, ',', '"', '');
                    }
                }
            };

            $batch = collect();
            foreach ($query->cursor() as $record) {
                $batch->push($record);
                if ($batch->count() < 200) {
                    continue;
                }

                $writeBatch($batch);
                $batch = collect();
            }
            if ($batch->isNotEmpty()) {
                $writeBatch($batch);
            }

            fclose($output);
        }, "{$filename}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, BusinessObject $object, CreateObjectRecord $writer): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->canDo("object.{$object->key}.create") && ! $object->read_only, 403);

        $payload = $this->applySystemPayload($object, $this->validatePayload($request, $object), $request->user());
        $record = DB::transaction(function () use ($object, $payload, $request, $writer): ObjectRecord {
            $this->relations->lockReferenceGraph();
            if ($object->key === 'inbound') {
                $payload = $this->inboundMaterials->handle($payload, $request->user());
            }
            $this->relations->validateItemRelations($object, $payload, $request->user());

            $record = $writer->handle($object, $payload, $request->user());
            $this->projectWorkflow->handle($record, [], $request->user(), $writer);

            return $record;
        });

        $status = $this->statusWithFinanceWarning($request, "{$object->label}已创建。");

        if ($request->wantsJson()) {
            $record->load('businessObject');
            $this->relations->preloadLabels(collect([$record]), $request->user());

            return response()->json([
                'record' => $this->relations->formatRecord($record, $request->user()),
                'status' => $status,
            ], 201);
        }

        return redirect()->route('objects.index', $object->key)
            ->with('status', $status);
    }

    public function update(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse|JsonResponse
    {
        $object = $record->businessObject;
        abort_unless($request->user()->canDo("object.{$object->key}.update") && ! $object->read_only, 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);

        $payload = $this->applySystemPayload(
            $object,
            $this->validatePayload($request, $object, $record->payload ?? []),
            $request->user(),
        );
        DB::transaction(function () use ($record, $object, $payload, $writer, $request): void {
            $this->relations->lockReferenceGraph();
            if (in_array($object->key, ['stock_ledger', 'material'], true)) {
                BusinessObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
            }
            $lockedRecord = ObjectRecord::query()->lockForUpdate()->findOrFail($record->id);
            $oldPayload = $lockedRecord->payload ?? [];
            $payload = $this->mergeReadonlyPayload($object, $payload, $oldPayload);
            if ($object->key === 'team_log'
                && ($lockedRecord->payload['team_id'] ?? null) !== ($payload['team_id'] ?? null)) {
                unset($payload['team_leader_name']);
            }
            if ($object->key === 'inbound') {
                $payload = $this->inboundMaterials->handle($payload, $request->user());
            }
            $payload = $writer->normalizePayload($object, $payload, $oldPayload);
            if ($object->key === 'customer_contact') {
                foreach (['position', 'remark', 'status'] as $legacyKey) {
                    if (array_key_exists($legacyKey, $oldPayload)) {
                        $payload[$legacyKey] = $oldPayload[$legacyKey];
                    }
                }
            }
            $this->relations->validatePayloadRelations(
                $object,
                $payload,
                $request->user(),
                $oldPayload,
            );
            $this->relations->validateItemRelations(
                $object,
                $payload,
                $request->user(),
                $oldPayload,
            );
            if ($object->key === 'material') {
                $payload = $this->materialNames->normalizeAndGuardUnique($object, $payload, $lockedRecord->id);
            }
            $oldProjectId = $lockedRecord->payload['project_id'] ?? null;
            $newProjectId = $payload['project_id'] ?? null;
            $lockedProjects = collect();
            if (in_array($object->key, ['receivable', 'contract'], true)) {
                $lockedProjects = $this->projectFinance->lockProjects([$oldProjectId, $newProjectId]);
                $newProject = $this->projectFinance->lockedProjectOrFail($newProjectId, $lockedProjects);
                if ($object->key === 'receivable') {
                    $payload = $this->projectFinance->normalizePayload($payload, $newProject);
                    $this->projectFinance->guardUnique($newProjectId, $lockedRecord->id, $newProject);
                } else {
                    $payload = $this->projectFinance->fillContractProjectDefaults($payload, $oldPayload, $newProject);
                }
                $this->projectFinance->guardCustomerMatchesProject($newProject, $payload['customer_id'] ?? null);
            }

            if ($object->key === 'project') {
                $this->guardProjectCustomerChange($lockedRecord, $payload);
            }
            if ($object->key === 'production_team'
                && ($oldPayload['leader_id'] ?? null) !== ($payload['leader_id'] ?? null)) {
                $writer->validateProductionTeamLeader($lockedRecord, $payload);
            }
            if ($object->key === 'team_member') {
                $writer->validateTeamMemberChange($lockedRecord, $payload);
            }
            $lockedRecord->update([
                'payload' => $payload,
                'title' => (string) ($payload[$object->title_field] ?? $lockedRecord->title),
            ]);

            if ($object->key === 'contract') {
                foreach (collect([$oldProjectId, $newProjectId])->filter()->unique()->sort() as $projectId) {
                    $this->contractAmount->handle($projectId);
                    $this->projectWorkflow->syncContractFinance($projectId, $request->user());
                }
            }

            if ($object->key === 'receivable') {
                foreach ($lockedProjects as $project) {
                    $this->projectFinance->handleLocked($project, $request->user());
                }
            }

            if ($object->key === 'invoice') {
                foreach (collect([$oldProjectId, $newProjectId])->filter()->unique()->sort() as $projectId) {
                    $this->invoiceAmount->handle($projectId);
                }
            }

            if (in_array($object->key, ['contract', 'receivable'], true)) {
                $this->projectNotifications->handleProjects([$oldProjectId, $newProjectId]);
            }

            $this->projectWorkflow->handle($lockedRecord, $oldPayload, $request->user(), $writer);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'object.update',
                'subject_type' => $object->key,
                'subject_id' => $lockedRecord->id,
                'payload' => [
                    'code' => $lockedRecord->code,
                    'changes' => $this->payloadChanges($oldPayload, $payload),
                ],
            ]);
        });

        $clearedContactCount = (int) $request->attributes->get('cleared_project_contact_count', 0);
        $status = $clearedContactCount > 0
            ? "客户已变更，已自动清除 {$clearedContactCount} 个不属于新客户的联系人。"
            : "{$object->label}已更新。";
        $status = $this->statusWithFinanceWarning($request, $status);

        if ($request->wantsJson()) {
            $freshRecord = $record->fresh(['businessObject']);
            $this->relations->preloadLabels(collect([$freshRecord]), $request->user());

            return response()->json([
                'record' => $this->relations->formatRecord($freshRecord, $request->user()),
                'status' => $status,
            ]);
        }

        return redirect()->route('objects.index', $object->key)->with('status', $status);
    }

    public function destroy(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse|JsonResponse
    {
        $object = $record->businessObject;
        abort_unless($request->user()->canDo("object.{$object->key}.delete") && ! $object->read_only, 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);

        DB::transaction(function () use ($record, $object, $request, $writer): void {
            $this->relations->lockReferenceGraph();
            if ($object->key === 'stock_ledger') {
                BusinessObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
            }
            $lockedRecord = ObjectRecord::query()->lockForUpdate()->findOrFail($record->id);
            $oldPayload = $lockedRecord->payload ?? [];
            $oldProjectId = $lockedRecord->payload['project_id'] ?? null;
            $lockedProjects = in_array($object->key, ['receivable', 'contract'], true)
                ? $this->projectFinance->lockProjects([$oldProjectId])
                : collect();

            if ($object->key === 'team_member') {
                $writer->validateTeamMemberChange($lockedRecord, null);
            }

            $this->relations->assertNotReferenced($lockedRecord);

            $lockedRecord->delete();

            if ($object->key === 'contract') {
                $this->contractAmount->handle($oldProjectId);
                $this->projectWorkflow->syncContractFinance($oldProjectId, $request->user());
            }

            if ($object->key === 'receivable') {
                $project = $lockedProjects->get($oldProjectId);
                if ($project) {
                    $this->projectFinance->handleLocked($project, $request->user());
                }
            }

            if ($object->key === 'invoice') {
                $this->invoiceAmount->handle($oldProjectId);
            }

            if (in_array($object->key, ['contract', 'receivable'], true)) {
                $this->projectNotifications->handleProjects([$oldProjectId]);
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'object.delete',
                'subject_type' => $object->key,
                'subject_id' => $lockedRecord->id,
                'payload' => [
                    'code' => $lockedRecord->code,
                    'deleted_payload' => $oldPayload,
                ],
            ]);
        });

        $status = "{$object->label}已删除。";
        if ($request->wantsJson()) {
            return response()->json(['status' => $status]);
        }

        return back()->with('status', $status);
    }

    private function authorizedRecordsQuery(BusinessObject $object, Request $request): Builder|Relation
    {
        $query = $object->records();
        $this->projectVisibility->scopeRecords($query, $object, $request->user());
        if ($object->key === 'requisition' && ! $request->user()->canDo('object.requisition.update')) {
            $query->where('created_by', $request->user()->id);
        }

        return $query;
    }

    private function applySearch(Builder|Relation $query, BusinessObject $object, string $search): Builder|Relation
    {
        if ($search === '') {
            return $query;
        }

        $needle = "%{$search}%";
        $lowerNeedle = '%'.mb_strtolower($search).'%';
        $payloadKeys = collect($object->fields ?? [])
            ->filter(fn (array $field) => ($field['scope'] ?? null) !== 'item')
            ->pluck('key')
            ->filter()
            ->unique()
            ->values();

        return $query->where(function (Builder $query) use ($object, $needle, $lowerNeedle, $payloadKeys): void {
            $query->whereLike('code', $needle, caseSensitive: false)
                ->orWhereLike('title', $needle, caseSensitive: false)
                ->orWhereRaw('LOWER(CAST(payload AS TEXT)) LIKE ?', [$lowerNeedle]);
            foreach ($payloadKeys as $key) {
                $query->orWhereLike("payload->{$key}", $needle, caseSensitive: false);
            }

            if ($object->key !== 'customer') {
                return;
            }

            $contactObjectId = BusinessObject::where('key', 'customer_contact')->value('id');
            if (! $contactObjectId) {
                return;
            }

            $driver = DB::connection()->getDriverName();
            $customerExpression = $driver === 'pgsql'
                ? "contacts.payload->>'customer_id'"
                : "json_extract(contacts.payload, '$.customer_id')";
            $customerIdExpression = $driver === 'pgsql'
                ? 'object_records.id::text'
                : 'object_records.id';

            $query->orWhereExists(function ($contacts) use (
                $contactObjectId,
                $customerExpression,
                $customerIdExpression,
                $needle,
                $lowerNeedle,
            ): void {
                $contacts->selectRaw('1')
                    ->from('object_records as contacts')
                    ->where('contacts.business_object_id', $contactObjectId)
                    ->whereRaw("{$customerExpression} = {$customerIdExpression}")
                    ->where(function ($match) use ($needle, $lowerNeedle): void {
                        $match->whereLike('contacts.title', $needle, caseSensitive: false)
                            ->orWhereRaw('LOWER(CAST(contacts.payload AS TEXT)) LIKE ?', [$lowerNeedle]);
                    });
            });
        });
    }

    private function searchQuery(Request $request): string
    {
        $search = $request->query('q', '');

        return is_string($search) ? mb_substr(trim($search), 0, 100) : '';
    }

    private function applySort(Builder|Relation $query, BusinessObject $object, Request $request): void
    {
        $sort = $request->query('sort');
        if (! is_string($sort) || $sort === '') {
            return;
        }

        $field = collect($object->fields ?? [])->first(fn (array $candidate) => ($candidate['key'] ?? null) === $sort
            && ($candidate['scope'] ?? null) !== 'item'
            && ! in_array($candidate['type'] ?? null, ['relation', 'multirelation', 'creatable_relation', 'file'], true)
        );
        if (! $field) {
            return;
        }

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $query->reorder();
        if (($field['system'] ?? null) === 'code') {
            $query->orderBy('code', $direction);
        } elseif (($field['system'] ?? null) === 'title') {
            $query->orderBy('title', $direction);
        } elseif (in_array($field['type'] ?? null, ['number', 'range'], true)) {
            $driver = DB::connection()->getDriverName();
            $key = str_replace('"', '', (string) $field['key']);
            $expression = $driver === 'pgsql'
                ? "CAST(payload->>'{$key}' AS NUMERIC)"
                : "CAST(json_extract(payload, '$.{$key}') AS REAL)";
            $query->orderByRaw("{$expression} {$direction}");
        } else {
            $query->orderBy("payload->{$field['key']}", $direction);
        }
        $query->orderBy('id', $direction);
    }

    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page', 50);
        if (is_array($requested) || filter_var($requested, FILTER_VALIDATE_INT) === false) {
            return 50;
        }

        return max(1, min(100, (int) $requested));
    }

    private function safeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $text = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $text = array_is_list($value) && collect($value)->every(fn ($item) => is_scalar($item) || $item === null)
                ? collect($value)->map(fn ($item) => (string) ($item ?? ''))->implode('、')
                : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        } else {
            $text = (string) $value;
        }

        $trimmed = ltrim($text, " \t\r\n\v\f");
        $startsWithControl = isset($text[0]) && in_array($text[0], ["\t", "\r", "\n"], true);
        if ($startsWithControl || str_starts_with($trimmed, '=') || str_starts_with($trimmed, '+')
            || str_starts_with($trimmed, '-') || str_starts_with($trimmed, '@')) {
            return "'{$text}";
        }

        return $text;
    }

    private function validatePayload(Request $request, BusinessObject $object, array $existingPayload = []): array
    {
        if (in_array($object->key, ['receivable', 'contract'], true)) {
            $inputPayload = (array) $request->input('payload', []);
            $request->merge(['payload' => $object->key === 'receivable'
                ? $this->projectFinance->fillProjectDefaults($inputPayload)
                : $this->projectFinance->fillContractProjectDefaults($inputPayload, $existingPayload)]);
        }

        $rules = [];
        $fileFields = [];
        $readonlyFields = [];
        $fields = collect($object->fields ?? []);
        $itemFields = $fields->filter(fn (array $field) => ($field['scope'] ?? null) === 'item')->values();
        $commonFields = $fields->reject(fn (array $field) => ($field['scope'] ?? null) === 'item');

        foreach ($commonFields as $field) {
            if ($field['readonly'] ?? false) {
                continue;
            }

            if (in_array($field['type'], ['readonly', 'lookup', 'derived'], true)) {
                $readonlyFields[] = $field;

                continue;
            }

            if ($field['type'] === 'file') {
                $fileFields[] = $field;
                if ($request->hasFile("payload.{$field['key']}")) {
                    $rules["payload.{$field['key']}"] = [
                        'nullable',
                        File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024),
                    ];
                }

                continue;
            }

            if ($field['type'] === 'multirelation') {
                $rules["payload.{$field['key']}"] = [
                    ! empty($field['required']) ? 'required' : 'nullable',
                    'array',
                ];
                $rules["payload.{$field['key']}.*"] = ['string', 'distinct'];
            } else {
                $rules["payload.{$field['key']}"] = $this->fieldRules($object, $field);
            }
        }

        if ($itemFields->isNotEmpty()) {
            $rules['payload.items'] = ['required', 'array', 'min:1'];
            $rules['payload.items.*'] = ['array'];
            $rules['payload.items.*.id'] = ['nullable', 'string', 'max:100', 'distinct'];
            foreach ($itemFields as $field) {
                if (($field['readonly'] ?? false)
                    || in_array($field['type'] ?? null, ['readonly', 'lookup', 'derived'], true)) {
                    continue;
                }
                $rules["payload.items.*.{$field['key']}"] = $this->fieldRules($object, $field);
            }
        }

        $messages = [
            'payload.items.required' => '至少填写一条明细。',
            'payload.items.min' => '至少填写一条明细。',
            'payload.items.*.id.distinct' => '同一张单据的明细标识不能重复。',
            'payload.items.*.*.required' => '每条明细的必填字段都必须填写。',
            'payload.items.*.*.numeric' => '明细数值必须是有效数字。',
            'payload.items.*.*.integer' => '明细数值必须是整数。',
            'payload.items.*.*.min' => '明细数值不能小于 :min。',
            'payload.items.*.*.date' => '明细日期格式不正确。',
        ];
        $payload = $request->validate($rules, $messages)['payload'] ?? [];
        if ($itemFields->isNotEmpty()) {
            $existingIds = collect($existingPayload['items'] ?? [])->pluck('id')->filter()->all();
            $allowedKeys = $itemFields->pluck('key')->all();
            $payload['items'] = collect($payload['items'] ?? [])
                ->map(function (array $item) use ($existingIds, $allowedKeys): array {
                    $submittedId = $item['id'] ?? null;
                    $id = is_string($submittedId) && in_array($submittedId, $existingIds, true)
                        ? $submittedId
                        : (string) Str::uuid();
                    $normalized = ['id' => $id];
                    foreach ($allowedKeys as $key) {
                        if (array_key_exists($key, $item)) {
                            $normalized[$key] = $item[$key];
                        }
                    }

                    return $normalized;
                })
                ->values()
                ->all();
        }
        foreach ($fileFields as $field) {
            $key = $field['key'];
            if ($request->hasFile("payload.{$key}")) {
                $payload[$key] = $request->file("payload.{$key}")->store('attachments', 'local');
            } else {
                $payload[$key] = (string) ($existingPayload[$key] ?? '');
            }
        }
        foreach ($readonlyFields as $field) {
            if (array_key_exists($field['key'], $existingPayload)) {
                $payload[$field['key']] = $existingPayload[$field['key']];
            }
        }
        foreach ($this->relations->relationFields($object) as $field) {
            if (($field['type'] ?? null) === 'multirelation' && isset($payload[$field['key']]) && is_array($payload[$field['key']])) {
                $payload[$field['key']] = array_values(array_unique($payload[$field['key']]));
            }
        }
        $contactResult = $this->relations->clearMismatchedProjectContactsOnCustomerChange(
            $object,
            $payload,
            $existingPayload,
        );
        $payload = $contactResult['payload'];
        $request->attributes->set('cleared_project_contact_count', $contactResult['cleared_count']);
        if ($object->key === 'receivable') {
            $request->attributes->set('finance_status_warning', $this->projectFinance->paymentStatusWarning($payload));
            $payload = $this->projectFinance->normalizePayload($payload);
        }

        return $payload;
    }

    private function applySystemPayload(BusinessObject $object, array $payload, ?User $user): array
    {
        if ($object->key === 'purchase') {
            $payload['items'] = collect($payload['items'] ?? [])->map(function (array $item): array {
                if (($item['arrived'] ?? null) === '已到货' && empty($item['actual_arrival_date'])) {
                    $item['actual_arrival_date'] = now()->format('Y-m-d');
                }

                return $item;
            })->values()->all();
        }

        return $payload;
    }

    private function fieldRules(BusinessObject $object, array $field): array
    {
        $type = $field['type'] ?? 'text';
        $rules = array_values(array_filter([
            ! empty($field['required']) ? 'required' : 'nullable',
            in_array($type, ['number', 'range'], true) ? ($type === 'range' ? 'integer' : 'numeric') : 'string',
            $type === 'date' ? 'date' : null,
        ]));

        if ($type === 'select' && ! empty($field['options'])) {
            $rules[] = Rule::in($field['options']);
        }
        if ($type === 'number' && array_key_exists('min', $field)) {
            $rules[] = 'min:'.$field['min'];
        }
        if ($type === 'number' && array_key_exists('max', $field)) {
            $rules[] = 'max:'.$field['max'];
        }
        if ($type === 'range' && array_key_exists('min', $field)) {
            $rules[] = 'min:'.$field['min'];
        }
        if ($type === 'range' && array_key_exists('max', $field)) {
            $rules[] = 'max:'.$field['max'];
        }
        if ($object->key === 'receivable' && $type === 'number') {
            $rules[] = 'min:0';
        }

        return $rules;
    }

    private function statusWithFinanceWarning(Request $request, string $status): string
    {
        $warning = $request->attributes->get('finance_status_warning');

        return $warning ? "{$status} {$warning}" : $status;
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    private function payloadChanges(array $before, array $after): array
    {
        $changes = [];
        $keys = collect([...array_keys($before), ...array_keys($after)])
            ->unique()
            ->sort()
            ->values();
        foreach ($keys as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$key] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    private function mergeReadonlyPayload(BusinessObject $object, array $payload, array $currentPayload): array
    {
        foreach ($object->fields ?? [] as $field) {
            $readonly = ($field['readonly'] ?? false)
                || in_array($field['type'] ?? null, ['readonly', 'lookup', 'derived'], true);
            if (! $readonly) {
                continue;
            }

            if (array_key_exists($field['key'], $currentPayload)) {
                $payload[$field['key']] = $currentPayload[$field['key']];
            } else {
                unset($payload[$field['key']]);
            }
        }

        return $payload;
    }

    private function guardProjectCustomerChange(ObjectRecord $project, array $payload): void
    {
        $oldCustomerId = $project->payload['customer_id'] ?? null;
        $newCustomerId = $payload['customer_id'] ?? null;
        if ($oldCustomerId === $newCustomerId) {
            return;
        }

        $mismatched = ObjectRecord::query()
            ->whereHas('businessObject', fn ($query) => $query->whereIn('key', ['contract', 'receivable']))
            ->where('payload->project_id', $project->id)
            ->get()
            ->contains(fn (ObjectRecord $record) => ($record->payload['customer_id'] ?? null) !== $newCustomerId);

        if ($mismatched) {
            throw ValidationException::withMessages([
                'payload.customer_id' => '项目已有合同或财务台账，客户必须保持一致。',
            ]);
        }
    }
}
