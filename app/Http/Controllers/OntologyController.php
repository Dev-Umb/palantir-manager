<?php

namespace App\Http\Controllers;

use App\Actions\CreateObjectRecord;
use App\Actions\SyncMaterialStockLedger;
use App\Actions\SyncProjectContractAmount;
use App\Actions\SyncProjectInvoiceAmount;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\ObjectRelations;
use App\Support\ProjectVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class OntologyController extends Controller
{
    public function __construct(
        private ObjectRelations $relations,
        private ProjectVisibility $projectVisibility,
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectInvoiceAmount $invoiceAmount,
        private SyncMaterialStockLedger $stockLedger,
    )
    {
    }

    public function index(Request $request, ?string $object = null): Response
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

        $recordsQuery = $current->records()->with('businessObject')->latest();
        if ($current->key === 'project') {
            $this->projectVisibility->scope($recordsQuery, $request->user());
        }
        if ($current->key === 'requisition' && ! $can('object.requisition.update')) {
            $recordsQuery->where('created_by', $request->user()->id);
        }

        $records = $recordsQuery->paginate(12)->withQueryString();
        $this->relations->preloadLabels($records->getCollection());

        $selected = $request->filled('record')
            ? (clone $recordsQuery)->whereKey((string) $request->query('record'))->first()
            : null;

        return Inertia::render('Ontology/Index', [
            'objects' => $visible,
            'currentObject' => $current,
            'records' => $records->through(fn (ObjectRecord $record) => $this->relations->formatRecord($record)),
            'relationOptions' => $this->relations->optionsFor($current, $objects, $request->user()),
            'selectedRecordId' => $selected?->id,
            'can' => [
                'create' => $can("object.{$current->key}.create") && ! $current->read_only,
                'update' => $can("object.{$current->key}.update") && ! $current->read_only,
                'delete' => $can("object.{$current->key}.delete") && ! $current->read_only,
            ],
        ]);
    }

    public function store(Request $request, BusinessObject $object, CreateObjectRecord $writer): RedirectResponse
    {
        abort_unless($request->user()->canDo("object.{$object->key}.create") && ! $object->read_only, 403);

        $payload = $this->applySystemPayload($object, $this->validatePayload($request, $object), $request->user());
        $writer->handle($object, $payload, $request->user());

        return redirect()->route('objects.index', $object->key)->with('status', "{$object->label}已创建。");
    }

    public function update(Request $request, ObjectRecord $record, CreateObjectRecord $writer): RedirectResponse|JsonResponse
    {
        $object = $record->businessObject;
        abort_unless($request->user()->canDo("object.{$object->key}.update") && ! $object->read_only, 403);

        $oldProjectId = $record->payload['project_id'] ?? null;
        $oldMaterialId = $record->payload['material_id'] ?? null;
        $payload = $writer->normalizePayload(
            $object,
            $this->applySystemPayload(
                $object,
                $this->validatePayload($request, $object, $record->payload ?? []),
                $request->user(),
            ),
        );
        $record->update([
            'payload' => $payload,
            'title' => (string) ($payload[$object->title_field] ?? $record->title),
        ]);

        if ($object->key === 'contract') {
            $this->contractAmount->handle($oldProjectId);
            $this->contractAmount->handle($payload['project_id'] ?? null);
        }

        if ($object->key === 'invoice') {
            $this->invoiceAmount->handle($oldProjectId);
            $this->invoiceAmount->handle($payload['project_id'] ?? null);
        }

        if ($object->key === 'project') {
            $this->invoiceAmount->handle($record->id);
        }

        if (in_array($object->key, ['inbound', 'outbound', 'return_order', 'stocktake'], true)) {
            $this->stockLedger->handle($oldMaterialId);
            $this->stockLedger->handle($payload['material_id'] ?? null);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'object.update',
            'subject_type' => $object->key,
            'subject_id' => $record->id,
            'payload' => ['code' => $record->code],
        ]);

        if ($request->wantsJson()) {
            $freshRecord = $record->fresh(['businessObject']);
            $this->relations->preloadLabels(collect([$freshRecord]));

            return response()->json([
                'record' => $this->relations->formatRecord($freshRecord),
            ]);
        }

        return redirect()->route('objects.index', $object->key)->with('status', "{$object->label}已更新。");
    }

    public function destroy(Request $request, ObjectRecord $record): RedirectResponse
    {
        $object = $record->businessObject;
        abort_unless($request->user()->canDo("object.{$object->key}.delete") && ! $object->read_only, 403);

        $oldProjectId = $record->payload['project_id'] ?? null;
        $oldMaterialId = $record->payload['material_id'] ?? null;
        $record->delete();

        if ($object->key === 'contract') {
            $this->contractAmount->handle($oldProjectId);
        }

        if ($object->key === 'invoice') {
            $this->invoiceAmount->handle($oldProjectId);
        }

        if (in_array($object->key, ['inbound', 'outbound', 'return_order', 'stocktake'], true)) {
            $this->stockLedger->handle($oldMaterialId);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'object.delete',
            'subject_type' => $object->key,
            'subject_id' => $record->id,
            'payload' => ['code' => $record->code],
        ]);

        return back()->with('status', "{$object->label}已删除。");
    }

    private function validatePayload(Request $request, BusinessObject $object, array $existingPayload = []): array
    {
        $rules = [];
        $fileFields = [];
        $readonlyFields = [];
        foreach ($object->fields as $field) {
            if (in_array($field['type'], ['readonly', 'lookup', 'derived'], true)) {
                $readonlyFields[] = $field;
                continue;
            }

            if ($field['type'] === 'file') {
                $fileFields[] = $field;
                if ($request->hasFile("payload.{$field['key']}")) {
                    $rules["payload.{$field['key']}"] = ['nullable', 'file', 'max:20480'];
                }
                continue;
            }

            $rules["payload.{$field['key']}"] = array_filter([
                ! empty($field['required']) ? 'required' : 'nullable',
                $field['type'] === 'number' ? 'numeric' : 'string',
                $field['type'] === 'date' ? 'date' : null,
            ]);
        }

        $payload = $request->validate($rules)['payload'] ?? [];
        foreach ($fileFields as $field) {
            $key = $field['key'];
            if ($request->hasFile("payload.{$key}")) {
                $payload[$key] = Storage::url($request->file("payload.{$key}")->store('attachments', 'public'));
            } else {
                $payload[$key] = (string) $request->input("payload.{$key}", $existingPayload[$key] ?? '');
            }
        }
        foreach ($readonlyFields as $field) {
            if (array_key_exists($field['key'], $existingPayload)) {
                $payload[$field['key']] = $existingPayload[$field['key']];
            }
        }
        $this->relations->validatePayloadRelations($object, $payload);

        return $payload;
    }

    private function applySystemPayload(BusinessObject $object, array $payload, ?\App\Models\User $user): array
    {
        if ($object->key === 'purchase' && ($payload['arrived'] ?? null) === '已到货' && empty($payload['actual_arrival_date'])) {
            $payload['actual_arrival_date'] = now()->format('Y/m/d');
        }

        return $payload;
    }
}
