<?php

namespace App\Http\Controllers;

use App\Actions\AcknowledgeWorkflowTask;
use App\Actions\BuildFilteredRecordSubtotal;
use App\Actions\CreateObjectRecord;
use App\Actions\ReassignTenderBusinessOwner;
use App\Actions\ResolveInboundMaterials;
use App\Actions\SyncProjectContractAmount;
use App\Actions\SyncProjectCustomerProfile;
use App\Actions\SyncProjectFinance;
use App\Actions\SyncProjectNotifications;
use App\Http\Requests\PreviewProjectCustomerProfileRequest;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\BusinessWorkspace;
use App\Support\MaterialNames;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OntologyController extends Controller
{
    private const BUSINESS_SUMMARY_KEY = 'project_business_summary';

    public function __construct(
        private ObjectRelations $relations,
        private ProjectVisibility $projectVisibility,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectFinance $projectFinance,
        private SyncProjectNotifications $projectNotifications,
        private ResolveInboundMaterials $inboundMaterials,
        private MaterialNames $materialNames,
        private AcknowledgeWorkflowTask $workflowTasks,
        private BusinessWorkspace $workspace,
        private ReassignTenderBusinessOwner $tenderBusinessOwner,
        private BuildFilteredRecordSubtotal $filteredRecordSubtotal,
        private SyncProjectCustomerProfile $projectCustomerProfile,
    ) {}

    public function index(Request $request, ?string $object = null): Response|RedirectResponse
    {
        $permissions = $request->user()->permissionKeys();
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $objects = BusinessObject::query()
            ->whereIn('key', BusinessWorkspace::RETAINED_OBJECT_KEYS)
            ->orderBy('sort_order')
            ->get();
        $visible = $objects->filter(fn (BusinessObject $item) => $this->workspace->allowsObjectTableAccess($item)
            && $can("object.{$item->key}.view")
            && ($item->key !== self::BUSINESS_SUMMARY_KEY || $can('object.project.view')))->values();
        abort_if($visible->isEmpty(), 403);

        $current = $object
            ? $visible->firstWhere('key', $object)
            : $visible->first();

        abort_unless($current, 403);
        $currentFields = $this->workspace->fieldsForUser($current, $request->user());

        $recordsObject = $this->recordsObject($current);
        $recordsQuery = $this->authorizedRecordsQuery($recordsObject, $request)
            ->with('businessObject')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $selected = $request->filled('record')
            ? (clone $recordsQuery)->whereKey((string) $request->query('record'))->first()
            : null;
        if ($selected && $request->query('mode') === 'detail' && $current->key !== self::BUSINESS_SUMMARY_KEY) {
            $this->workflowTasks->handle($selected, $request->user());
            $selected->refresh();
        }
        $this->applySearch($recordsQuery, $current, $this->searchQuery($request));
        $this->applyFilters($recordsQuery, $current, $request);
        $this->applySort($recordsQuery, $current, $request);
        $subtotalQuery = clone $recordsQuery;
        $records = $recordsQuery
            ->paginate($this->perPage($request))
            ->withQueryString();
        $subtotal = $records->onLastPage() && $records->total() > 0
            ? $this->filteredRecordSubtotal->handle($subtotalQuery, $currentFields)
            : null;
        $recordsForLabels = $records->getCollection();
        if ($selected && ! $recordsForLabels->contains('id', $selected->id)) {
            $recordsForLabels = $recordsForLabels->concat([$selected]);
        }
        $this->relations->preloadLabels($recordsForLabels, $request->user());

        $relationOptions = $current->key === self::BUSINESS_SUMMARY_KEY
            ? []
            : $this->relations->optionsFor($current, null, $request->user(), $selected);
        if ($current->key === 'project') {
            $businessAccounts = $this->workspace->businessAccountOptions()->all();
            $relationOptions['business_owner_user_id'] = [
                'items' => $businessAccounts,
                'selectedItems' => [],
            ];
            $relationOptions['informed_business_user_ids'] = [
                'items' => collect($businessAccounts)
                    ->map(fn (array $account): array => [...$account, 'id' => (string) $account['id']])
                    ->all(),
                'selectedItems' => [],
            ];
        }
        if ($current->key === 'tender') {
            $relationOptions['assignee_user_id'] = [
                'items' => $this->workspace->businessAccountOptions()->all(),
                'selectedItems' => [],
            ];
        }
        $currentForUser = $current->toArray();
        $currentForUser['fields'] = $currentFields;

        return Inertia::render('Ontology/Index', [
            'objects' => $visible->map(function (BusinessObject $object) use ($request): array {
                $data = $object->toArray();
                $data['fields'] = $this->workspace->fieldsForUser($object, $request->user());

                return $data;
            }),
            'contactObject' => $can('object.customer_contact.view')
                ? $objects->firstWhere('key', 'customer_contact')?->only(['id', 'key', 'label'])
                : null,
            'currentObject' => $currentForUser,
            'records' => $records->through(fn (ObjectRecord $record) => $this->formatRecordForObject(
                $current,
                $record,
                $request->user(),
            )),
            'subtotal' => $subtotal,
            'relationOptions' => $relationOptions,
            'selectedRecordId' => $selected?->id,
            'selectedRecord' => $selected
                ? $this->formatRecordForObject($current, $selected, $request->user())
                : null,
            'can' => [
                'create' => $can("object.{$current->key}.create") && $this->canCreate($current, $request->user()),
                'update' => $can("object.{$current->key}.update") && $this->workspace->writableFieldKeys($current, $request->user()) !== [],
                'delete' => $can("object.{$current->key}.delete") && $this->workspace->canDelete($current, $request->user()),
                'sync_contract_amount' => $current->key === 'project'
                    && ($this->workspace->isAdmin($request->user()) || $this->workspace->isFinance($request->user())),
                'manage_customers' => $current->key === 'project'
                    && ($this->workspace->isAdmin($request->user()) || $this->workspace->isBusiness($request->user())),
                'convert' => $current->key === 'tender' && $can('object.tender.update'),
            ],
            'businessUsers' => $current->key === 'tender'
                ? User::query()
                    ->whereHas('roles', fn ($query) => $query->where('name', 'business'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function exportCsv(Request $request, string $object): StreamedResponse
    {
        $current = BusinessObject::where('key', $object)->firstOrFail();
        abort_unless($this->workspace->allowsObjectTableAccess($current), 404);
        abort_unless($request->user()->canDo("object.{$current->key}.view"), 403);
        if ($current->key === self::BUSINESS_SUMMARY_KEY) {
            abort_unless($request->user()->canDo('object.project.view'), 403);
        }

        $query = $this->authorizedRecordsQuery($this->recordsObject($current), $request)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        $this->applySearch($query, $current, $this->searchQuery($request));
        $this->applyFilters($query, $current, $request);
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
                                    : (in_array($field['type'] ?? null, ['account', 'multiaccount'], true)
                                        ? $this->relations->accountDisplayValue(
                                            $container[$field['key']] ?? null,
                                            ($field['type'] ?? null) === 'multiaccount',
                                        )
                                        : ($container[$field['key']] ?? null)),
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
        abort_unless($this->workspace->allowsDirectObjectAccess($object), 404);
        abort_unless($request->user()->canDo("object.{$object->key}.create") && $this->canCreate($object, $request->user()), 403);

        $customerProfile = $this->projectCustomerProfileData($request, $object);
        $payload = $this->applySystemPayload(
            $object,
            $this->scopeWritablePayload($object, $this->validatePayload($request, $object), [], $request->user()),
            $request->user(),
        );
        if ($object->key === 'project') {
            $payload = $this->prepareNewProjectPayload($payload, $request->user());
        }
        if ($object->key === 'contract') {
            $this->guardContractEvidence($payload);
        }
        $this->guardTenderStatus($object, $payload);
        $record = DB::transaction(function () use ($object, $payload, $customerProfile, $request, $writer): ObjectRecord {
            $this->relations->lockReferenceGraph();
            if ($object->key === 'project' && $customerProfile) {
                $customerResult = $this->projectCustomerProfile->handle($customerProfile, $request->user(), $writer);
                $payload['customer_id'] = $customerResult['customer_id'];
                $payload['customer_contact_ids'] = $customerResult['contact_ids'];
            }
            if ($object->key === 'inbound') {
                $payload = $this->inboundMaterials->handle($payload, $request->user());
            }
            $this->relations->validateItemRelations($object, $payload, $request->user());

            $record = $writer->handle($object, $payload, $request->user());

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
        abort_unless($this->workspace->allowsDirectObjectAccess($object), 404);
        abort_unless($request->user()->canDo("object.{$object->key}.update")
            && $this->workspace->writableFieldKeys($object, $request->user()) !== [], 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);
        if ($object->key === 'project') {
            abort_unless($this->projectVisibility->allowsProjectUpdate($request->user(), $record), 403);
        }

        $customerProfile = $this->projectCustomerProfileData($request, $object);
        $payload = $this->applySystemPayload(
            $object,
            $this->scopeWritablePayload(
                $object,
                $this->validatePayload($request, $object, $record->payload ?? []),
                $record->payload ?? [],
                $request->user(),
            ),
            $request->user(),
        );
        DB::transaction(function () use ($record, $object, $payload, $customerProfile, $writer, $request): void {
            $this->relations->lockReferenceGraph();
            if (in_array($object->key, ['stock_ledger', 'material'], true)) {
                BusinessObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
            }
            $lockedRecord = ObjectRecord::query()->lockForUpdate()->findOrFail($record->id);
            $oldPayload = $lockedRecord->payload ?? [];
            $payload = $this->mergeReadonlyPayload($object, $payload, $oldPayload);
            if ($object->key === 'project' && $customerProfile) {
                $customerResult = $this->projectCustomerProfile->handle($customerProfile, $request->user(), $writer);
                $payload['customer_id'] = $customerResult['customer_id'];
                $payload['customer_contact_ids'] = $customerResult['contact_ids'];
            }
            if ($object->key === 'team_log'
                && ($lockedRecord->payload['team_id'] ?? null) !== ($payload['team_id'] ?? null)) {
                unset($payload['team_leader_name']);
            }
            if ($object->key === 'inbound') {
                $payload = $this->inboundMaterials->handle($payload, $request->user());
            }
            $payload = $writer->normalizePayload($object, $payload, $oldPayload, $request->user());
            $this->guardTenderStatus($object, $payload, $oldPayload);
            if ($object->key === 'customer_contact') {
                foreach (['position', 'remark', 'status'] as $legacyKey) {
                    if (array_key_exists($legacyKey, $oldPayload)) {
                        $payload[$legacyKey] = $oldPayload[$legacyKey];
                    }
                }
            }
            $relationPayload = $payload;
            if ($object->key === 'tender') {
                unset($relationPayload['converted_project_id']);
            }
            $this->relations->validatePayloadRelations(
                $object,
                $relationPayload,
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
            if ($object->key === 'contract') {
                $lockedProjects = $this->projectFinance->lockProjects([$oldProjectId, $newProjectId]);
                $newProject = $this->projectFinance->lockedProjectOrFail($newProjectId, $lockedProjects);
                $payload = $this->projectFinance->fillContractProjectDefaults($payload, $oldPayload, $newProject);
                $this->projectFinance->guardCustomerMatchesProject($newProject, $payload['customer_id'] ?? null);
            }

            if ($object->key === 'project') {
                $this->guardProjectCustomerChange($lockedRecord, $payload);
                $payload = $this->prepareProjectPayload($lockedRecord, $payload, $request->user());
            }
            if ($object->key === 'tender') {
                $this->tenderBusinessOwner->handle($lockedRecord, $payload, $request->user());
            }
            if ($object->key === 'contract') {
                $this->guardContractEvidence($payload);
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
                }
            }

            if ($object->key === 'contract') {
                $this->projectNotifications->handleProjects([$oldProjectId, $newProjectId]);
            }
            if ($object->key === 'project') {
                $this->projectNotifications->handleProjects([$lockedRecord->id]);
            }

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

        return redirect($this->objectReturnUrl($request, $object))->with('status', $status);
    }

    public function destroy(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse|JsonResponse
    {
        $object = $record->businessObject;
        abort_unless($this->workspace->allowsDirectObjectAccess($object), 404);
        abort_unless($request->user()->canDo("object.{$object->key}.delete")
            && $this->workspace->canDelete($object, $request->user()), 403);
        abort_unless($this->projectVisibility->allowsRecord($request->user(), $record), 403);

        DB::transaction(function () use ($record, $object, $request, $writer): void {
            $this->relations->lockReferenceGraph();
            if ($object->key === 'stock_ledger') {
                BusinessObject::query()->whereKey($object->id)->lockForUpdate()->firstOrFail();
            }
            $lockedRecord = ObjectRecord::query()->lockForUpdate()->findOrFail($record->id);
            $oldPayload = $lockedRecord->payload ?? [];
            if ($object->key === 'tender' && ! empty($oldPayload['converted_project_id'])) {
                throw ValidationException::withMessages([
                    'tender' => '已流转项目的招投标记录不能删除。',
                ]);
            }
            $oldProjectId = $lockedRecord->payload['project_id'] ?? null;
            if ($object->key === 'contract') {
                $this->projectFinance->lockProjects([$oldProjectId]);
            }

            if ($object->key === 'team_member') {
                $writer->validateTeamMemberChange($lockedRecord, null);
            }

            $this->relations->assertNotReferenced($lockedRecord);

            $lockedRecord->delete();

            if ($object->key === 'contract') {
                $this->contractAmount->handle($oldProjectId);
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

    private function recordsObject(BusinessObject $object): BusinessObject
    {
        if ($object->key !== self::BUSINESS_SUMMARY_KEY) {
            return $object;
        }

        return BusinessObject::query()->where('key', 'project')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function formatRecordForObject(
        BusinessObject $object,
        ObjectRecord $record,
        User $user,
    ): array {
        $formatted = $this->relations->formatRecord($record, $user);
        if ($object->key !== self::BUSINESS_SUMMARY_KEY) {
            return $formatted;
        }

        $fieldKeys = collect($object->fields ?? [])->pluck('key')->all();

        return [
            'id' => $formatted['id'],
            'code' => $formatted['code'],
            'title' => $formatted['title'],
            'payload' => Arr::only($formatted['payload'] ?? [], $fieldKeys),
            'display' => Arr::only($formatted['display'] ?? [], $fieldKeys),
        ];
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
                ->orWhereLike('title', $needle, caseSensitive: false);
            if ($object->key !== self::BUSINESS_SUMMARY_KEY) {
                $query->orWhereRaw('LOWER(CAST(payload AS TEXT)) LIKE ?', [$lowerNeedle]);
            }
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

    private function applyFilters(Builder|Relation $query, BusinessObject $object, Request $request): void
    {
        $filters = collect($request->query('filters', []))
            ->filter(fn (mixed $filter): bool => is_array($filter))
            ->take(10)
            ->values();
        if ($filters->isEmpty()) {
            return;
        }

        $fields = collect($object->fields ?? [])->keyBy('key');
        $logic = $request->query('filter_logic') === 'or' ? 'or' : 'and';
        $query->where(function (Builder $group) use ($filters, $fields, $logic): void {
            foreach ($filters as $filter) {
                $field = $fields->get($filter['field'] ?? '');
                $operator = is_string($filter['operator'] ?? null) ? $filter['operator'] : 'equals';
                $value = $filter['value'] ?? null;
                if (! is_array($field) || is_array($value) || ! $this->validFilterOperator($field, $operator)) {
                    continue;
                }
                if (! in_array($operator, ['is_empty', 'is_not_empty'], true) && ($value === null || $value === '')) {
                    continue;
                }

                $method = $logic === 'or' ? 'orWhere' : 'where';
                $group->{$method}(function (Builder $condition) use ($field, $operator, $value): void {
                    $this->applyFilterCondition($condition, $field, $operator, (string) $value);
                });
            }
        });
    }

    /** @param array<string, mixed> $field */
    private function applyFilterCondition(Builder $query, array $field, string $operator, string $value): void
    {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $column = match ($field['system'] ?? null) {
            'code' => 'code',
            'title' => 'title',
            default => "payload->{$key}",
        };

        if ($operator === 'is_empty') {
            $query->where(function (Builder $empty) use ($column): void {
                $empty->whereNull($column)->orWhere($column, '');
            });

            return;
        }
        if ($operator === 'is_not_empty') {
            $query->whereNotNull($column)->where($column, '!=', '');

            return;
        }

        if (in_array($type, ['number', 'range'], true) && is_numeric($value)) {
            $driver = DB::connection()->getDriverName();
            $safeKey = str_replace(["'", '"'], '', $key);
            $expression = $driver === 'pgsql'
                ? "CAST(payload->>'{$safeKey}' AS NUMERIC)"
                : "CAST(json_extract(payload, '$.{$safeKey}') AS REAL)";
            $comparison = match ($operator) {
                'greater_than' => '>',
                'greater_or_equal' => '>=',
                'less_than' => '<',
                'less_or_equal' => '<=',
                'not_equals' => '!=',
                default => '=',
            };
            $query->whereRaw("{$expression} {$comparison} ?", [(float) $value]);

            return;
        }

        if (in_array($type, ['number', 'range', 'date'], true) && $operator === 'between') {
            [$start, $end] = array_pad(explode('..', $value, 2), 2, null);
            if ($start !== null && $start !== '' && $end !== null && $end !== '') {
                if (in_array($type, ['number', 'range'], true) && is_numeric($start) && is_numeric($end)) {
                    $driver = DB::connection()->getDriverName();
                    $safeKey = str_replace(["'", '"'], '', $key);
                    $expression = $driver === 'pgsql'
                        ? "CAST(payload->>'{$safeKey}' AS NUMERIC)"
                        : "CAST(json_extract(payload, '$.{$safeKey}') AS REAL)";
                    $query->whereBetween(DB::raw($expression), [(float) $start, (float) $end]);
                } elseif ($type === 'date') {
                    $query->whereBetween($column, [$start, $end]);
                }
            }

            return;
        }

        $comparison = match ($operator) {
            'not_equals' => '!=',
            'after', 'greater_than' => '>',
            'on_or_after', 'greater_or_equal' => '>=',
            'before', 'less_than' => '<',
            'on_or_before', 'less_or_equal' => '<=',
            default => '=',
        };
        if ($operator === 'contains') {
            $query->whereLike($column, '%'.$value.'%', caseSensitive: false);
        } elseif ($operator === 'not_contains') {
            $query->whereNotLike($column, '%'.$value.'%', caseSensitive: false);
        } else {
            $query->where($column, $comparison, $value);
        }
    }

    /** @param array<string, mixed> $field */
    private function validFilterOperator(array $field, string $operator): bool
    {
        $type = $field['type'] ?? 'text';
        $allowed = match (true) {
            in_array($type, ['file', 'files', 'multirelation', 'multiaccount'], true) => [],
            in_array($type, ['number', 'range'], true) => ['equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'between', 'is_empty', 'is_not_empty'],
            $type === 'date' => ['equals', 'before', 'on_or_before', 'after', 'on_or_after', 'between', 'is_empty', 'is_not_empty'],
            in_array($type, ['select', 'relation', 'account'], true) => ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
            default => ['contains', 'not_contains', 'equals', 'not_equals', 'is_empty', 'is_not_empty'],
        };

        return in_array($operator, $allowed, true);
    }

    private function applySort(Builder|Relation $query, BusinessObject $object, Request $request): void
    {
        $sort = $request->query('sort');
        if (! is_string($sort) || $sort === '') {
            $this->applyDefaultProjectSort($query, $object);

            return;
        }

        $field = collect($object->fields ?? [])->first(fn (array $candidate) => ($candidate['key'] ?? null) === $sort
            && ($candidate['scope'] ?? null) !== 'item'
            && ! in_array($candidate['type'] ?? null, ['relation', 'multirelation', 'multiaccount', 'creatable_relation', 'file'], true)
        );
        if (! $field) {
            $this->applyDefaultProjectSort($query, $object);

            return;
        }

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $isProjectTitleField = $object->key === 'project' && ($field['key'] ?? null) === $object->title_field;
        $query->reorder();
        if ($object->key === 'project' && ! $isProjectTitleField) {
            $query->orderBy('title');
        }
        if (($field['system'] ?? null) === 'code') {
            $query->orderBy('code', $direction);
        } elseif (($field['system'] ?? null) === 'title' || $isProjectTitleField) {
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

    private function applyDefaultProjectSort(Builder|Relation $query, BusinessObject $object): void
    {
        if ($object->key !== 'project') {
            return;
        }

        $query->reorder()
            ->orderBy('title')
            ->orderBy('id');
    }

    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page', 50);
        if (is_array($requested) || filter_var($requested, FILTER_VALIDATE_INT) === false) {
            return 50;
        }

        return max(10, min(100, (int) $requested));
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
        if ($object->key === 'contract') {
            $inputPayload = (array) $request->input('payload', []);
            $request->merge(['payload' => $this->projectFinance->fillContractProjectDefaults($inputPayload, $existingPayload)]);
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

            if ($field['type'] === 'files') {
                $fileFields[] = $field;
                $rules["payload.{$field['key']}"] = ['nullable', 'array', 'max:20'];
                $rules["payload.{$field['key']}.*"] = [
                    File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024),
                ];

                continue;
            }

            if ($object->key === 'project'
                && $request->has('payload.customer_profile')
                && ($field['key'] ?? null) === 'customer_id') {
                $rules["payload.{$field['key']}"] = ['nullable', 'string'];
            } elseif (in_array($field['type'], ['multirelation', 'multiaccount'], true)) {
                $rules["payload.{$field['key']}"] = [
                    ! empty($field['required']) ? 'required' : 'nullable',
                    'array',
                ];
                $rules["payload.{$field['key']}.*"] = ($field['type'] ?? null) === 'multiaccount'
                    ? ['string']
                    : ['string', 'distinct'];
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
            if (($field['type'] ?? null) === 'files') {
                $existingFiles = collect($existingPayload[$key] ?? [])
                    ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
                    ->values();
                $uploadedFiles = collect($request->file("payload.{$key}", []))
                    ->map(fn ($file): string => $file->store('attachments', 'local'));
                $payload[$key] = $existingFiles->concat($uploadedFiles)->values()->all();
            } elseif ($request->hasFile("payload.{$key}")) {
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
            if (($field['type'] ?? null) === 'multirelation'
                && isset($payload[$field['key']]) && is_array($payload[$field['key']])) {
                $payload[$field['key']] = array_values(array_unique($payload[$field['key']]));
            }
        }
        foreach ($object->fields ?? [] as $field) {
            if (($field['type'] ?? null) === 'multiaccount'
                && isset($payload[$field['key']]) && is_array($payload[$field['key']])) {
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

        return $payload;
    }

    /**
     * @return array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}|null
     */
    private function projectCustomerProfileData(Request $request, BusinessObject $object): ?array
    {
        if ($object->key !== 'project' || ! $request->has('payload.customer_profile')) {
            return null;
        }

        abort_unless(
            $this->workspace->isAdmin($request->user()) || $this->workspace->isBusiness($request->user()),
            403,
        );
        $rules = [
            'payload.customer_profile' => ['required', 'array'],
            ...PreviewProjectCustomerProfileRequest::profileRules('payload.customer_profile.'),
        ];
        $validated = Validator::make($request->all(), $rules)->validate();

        return PreviewProjectCustomerProfileRequest::normalizeProfile(
            data_get($validated, 'payload.customer_profile', []),
        );
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
            in_array($type, ['date', 'datetime'], true) ? 'date' : null,
        ]));

        if ($type === 'select' && ! empty($field['options'])) {
            $rules[] = Rule::in($field['options']);
        }
        if ($type === 'account') {
            $rules = [
                ! empty($field['required']) ? 'required' : 'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ];
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

        return $rules;
    }

    private function objectReturnUrl(Request $request, BusinessObject $object): string
    {
        $fallback = route('objects.index', $object->key, absolute: false);
        $returnTo = $request->query('return_to');
        if (! is_string($returnTo)) {
            return $fallback;
        }

        $parts = parse_url($returnTo);
        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || ($parts['path'] ?? null) !== $fallback) {
            return $fallback;
        }

        return $fallback.(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
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
            ->whereHas('businessObject', fn ($query) => $query->where('key', 'contract'))
            ->where('payload->project_id', $project->id)
            ->get()
            ->contains(fn (ObjectRecord $record) => ($record->payload['customer_id'] ?? null) !== $newCustomerId);

        if ($mismatched) {
            throw ValidationException::withMessages([
                'payload.customer_id' => '项目已有合同，客户必须保持一致。',
            ]);
        }
    }

    private function canCreate(BusinessObject $object, User $user): bool
    {
        if ($object->read_only) {
            return false;
        }

        return match ($object->key) {
            'project' => $this->workspace->isAdmin($user) || $this->workspace->isBusiness($user),
            'contract' => $this->workspace->isAdmin($user),
            'customer' => $this->workspace->isAdmin($user) || $this->workspace->isBusiness($user) || $this->workspace->isTender($user),
            'customer_contact' => $this->workspace->isAdmin($user) || $this->workspace->isBusiness($user),
            'tender' => $this->workspace->isAdmin($user) || $this->workspace->isTender($user),
            default => false,
        };
    }

    private function scopeWritablePayload(
        BusinessObject $object,
        array $submitted,
        array $existing,
        User $user,
    ): array {
        $allowed = array_flip($this->workspace->writableFieldKeys($object, $user));
        $payload = $existing;
        foreach ($submitted as $key => $value) {
            if (isset($allowed[$key])) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    private function prepareNewProjectPayload(array $payload, User $user): array
    {
        $payload = $this->projectFinance->withCalculatedPaymentProgress($payload);

        if (! $this->workspace->isAdmin($user) && $this->workspace->isBusiness($user)) {
            $payload['business_owner_user_id'] = (string) $user->id;
        }
        if (empty($payload['business_owner_user_id'])) {
            throw ValidationException::withMessages([
                'payload.business_owner_user_id' => '请选择负责业务员。',
            ]);
        }
        $this->guardBusinessOwner($payload['business_owner_user_id']);
        $this->guardInformedBusinessUsers($payload['informed_business_user_ids'] ?? []);

        $payload['overall_status'] = $payload['overall_status'] ?? '投标中';
        $this->guardProjectContractState($payload);
        $this->guardShipmentDates($payload);
        $payload['overall_status_changed_at'] = now()->toISOString();
        $payload['contract_status'] = '未签署';
        $payload['collection_count'] = 0;
        if (is_numeric($payload['contract_amount'] ?? null)) {
            $payload['contract_amount_source'] = 'manual';
        }

        return $payload;
    }

    private function prepareProjectPayload(ObjectRecord $project, array $payload, User $user): array
    {
        $payload = $this->projectFinance->withCalculatedPaymentProgress($payload);
        $oldPayload = $project->payload ?? [];
        if (($oldPayload['business_owner_user_id'] ?? null) !== ($payload['business_owner_user_id'] ?? null)) {
            $this->guardBusinessOwner($payload['business_owner_user_id'] ?? null);
        }
        if (($oldPayload['informed_business_user_ids'] ?? []) !== ($payload['informed_business_user_ids'] ?? [])) {
            $this->guardInformedBusinessUsers($payload['informed_business_user_ids'] ?? []);
        }
        $this->guardProjectContractState($payload);
        $this->guardShipmentDates($payload);

        if (($oldPayload['overall_status'] ?? null) !== ($payload['overall_status'] ?? null)) {
            $payload['overall_status_changed_at'] = now()->toISOString();
        }

        if (($oldPayload['contract_amount'] ?? null) !== ($payload['contract_amount'] ?? null)
            && ($this->workspace->isAdmin($user) || $this->workspace->isFinance($user))) {
            $payload['contract_amount_source'] = 'manual';
            $payload['contract_amount_synced_at'] = null;
            $payload['contract_amount_synced_by'] = null;
        }

        $paymentKeys = [
            'occurred_amount',
            'paid_amount',
            'last_payment_date',
            'unpaid_amount',
            'reconciled_amount',
            'payment_progress',
            'payment_status',
        ];
        $paymentChanged = collect($paymentKeys)->contains(
            fn (string $key): bool => ($oldPayload[$key] ?? null) !== ($payload[$key] ?? null),
        );
        if ($paymentChanged && ($this->workspace->isAdmin($user) || $this->workspace->isFinance($user))) {
            $payload['payment_reminder_anchor_at'] = now()->toISOString();
            $payload['payment_data_updated_at'] = now()->toISOString();
        }

        return $payload;
    }

    private function guardProjectContractState(array $payload): void
    {
        $status = $payload['overall_status'] ?? '投标中';
        $contractStatus = $payload['contract_status'] ?? '未签署';
        if ($status === '已拿到加工函'
            && ! in_array($contractStatus, ['已有加工函', '部分签署', '已签署'], true)) {
            throw ValidationException::withMessages([
                'payload.overall_status' => '请先在合同表上传加工函并更新合同状态。',
            ]);
        }
        if ($status === '合同签署' && $contractStatus !== '已签署') {
            throw ValidationException::withMessages([
                'payload.overall_status' => '所有合同签署并上传合同附件后，才可更新为合同签署。',
            ]);
        }
    }

    private function guardShipmentDates(array $payload): void
    {
        $first = $payload['first_shipment_date'] ?? null;
        $last = $payload['last_shipment_date'] ?? null;
        if (is_string($first) && $first !== '' && is_string($last) && $last !== '' && $last < $first) {
            throw ValidationException::withMessages([
                'payload.last_shipment_date' => '末次发货日期不能早于首次发货日期。',
            ]);
        }
    }

    private function guardContractEvidence(array $payload): void
    {
        foreach (['processing_letter_attachments', 'contract_attachments', 'statement_attachments'] as $key) {
            if (count($payload[$key] ?? []) > 20) {
                throw ValidationException::withMessages([
                    "payload.{$key}" => '每类附件最多保留 20 个文件。',
                ]);
            }
        }

        if (($payload['status'] ?? '未签署') === '已有加工函'
            && empty($payload['processing_letter_attachments'])) {
            throw ValidationException::withMessages([
                'payload.processing_letter_attachments' => '合同状态为已有加工函时，必须上传加工函附件。',
            ]);
        }
        if (($payload['status'] ?? '未签署') === '已签署'
            && empty($payload['contract_attachments'])) {
            throw ValidationException::withMessages([
                'payload.contract_attachments' => '合同状态为已签署时，必须上传合同附件。',
            ]);
        }
    }

    private function guardBusinessOwner(mixed $userId): void
    {
        $valid = filter_var($userId, FILTER_VALIDATE_INT) !== false
            && User::query()
                ->whereKey((int) $userId)
                ->whereHas('roles', fn ($query) => $query->where('name', 'business'))
                ->exists();
        if (! $valid) {
            throw ValidationException::withMessages([
                'payload.business_owner_user_id' => '负责业务员必须选择具有业务角色的账号。',
            ]);
        }
    }

    private function guardInformedBusinessUsers(mixed $userIds): void
    {
        if (! is_array($userIds)) {
            throw ValidationException::withMessages([
                'payload.informed_business_user_ids' => '知会人员必须是有效的多选列表。',
            ]);
        }

        $ids = collect($userIds)
            ->filter(fn (mixed $userId): bool => filter_var($userId, FILTER_VALIDATE_INT) !== false)
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();
        $validCount = User::query()
            ->whereIn('id', $ids->all())
            ->whereHas('roles', fn ($query) => $query->where('name', 'business'))
            ->count();

        if ($ids->count() !== count($userIds) || $validCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'payload.informed_business_user_ids' => '知会人员必须选择具有业务角色的账号。',
            ]);
        }
    }

    private function guardTenderStatus(
        BusinessObject $object,
        array $payload,
        array $existingPayload = [],
    ): void {
        if ($object->key !== 'tender') {
            return;
        }

        $currentStatus = $existingPayload['status'] ?? null;
        $nextStatus = $payload['status'] ?? null;
        if ($nextStatus === '已中标' && $currentStatus !== '已中标') {
            throw ValidationException::withMessages([
                'payload.status' => '已中标只能通过“确认中标并流转”操作写入。',
            ]);
        }

        if (! empty($existingPayload['converted_project_id']) && $nextStatus !== '已中标') {
            throw ValidationException::withMessages([
                'payload.status' => '已流转项目的招投标状态必须保持为已中标。',
            ]);
        }
    }
}
