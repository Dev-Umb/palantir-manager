<?php

namespace App\Http\Controllers;

use App\Actions\CreateObjectRecord;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Support\BusinessWorkspace;
use App\Support\ObjectRelations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectCustomerController extends Controller
{
    public function __construct(
        private BusinessWorkspace $workspace,
        private ObjectRelations $relations,
    ) {}

    public function show(Request $request, ObjectRecord $customer): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $this->guardObject($customer, 'customer');
        $this->relations->preloadLabels(collect([$customer]), $request->user());

        return response()->json(['customer' => $this->relations->formatRecord($customer, $request->user())]);
    }

    public function store(Request $request, CreateObjectRecord $writer): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $data = $this->customerData($request);
        $customer = $writer->handle(
            BusinessObject::where('key', 'customer')->firstOrFail(),
            $data,
            $request->user(),
            'project.customer.create',
        );
        $this->relations->preloadLabels(collect([$customer]), $request->user());

        return response()->json(['customer' => $this->relations->formatRecord($customer, $request->user())], 201);
    }

    public function update(Request $request, ObjectRecord $customer, CreateObjectRecord $writer): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $this->guardObject($customer, 'customer');
        $data = $this->customerData($request);

        DB::transaction(function () use ($customer, $data, $request, $writer): void {
            $locked = ObjectRecord::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $locked->payload ?? [];
            $payload = $writer->normalizePayload($locked->businessObject, [...$before, ...$data], $before);
            $locked->update(['payload' => $payload, 'title' => $data['name']]);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'project.customer.update',
                'subject_type' => 'customer',
                'subject_id' => $locked->id,
                'payload' => ['before' => $before, 'after' => $payload],
            ]);
        });

        $fresh = $customer->fresh(['businessObject']);
        $this->relations->preloadLabels(collect([$fresh]), $request->user());

        return response()->json(['customer' => $this->relations->formatRecord($fresh, $request->user())]);
    }

    public function storeContact(Request $request, ObjectRecord $customer, CreateObjectRecord $writer): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $this->guardObject($customer, 'customer');
        $data = $this->contactData($request);
        $contact = $writer->handle(
            BusinessObject::where('key', 'customer_contact')->firstOrFail(),
            [...$data, 'customer_id' => $customer->id],
            $request->user(),
            'project.customer_contact.create',
        );

        return response()->json(['contact' => $this->contactResponse($contact)], 201);
    }

    public function updateContact(Request $request, ObjectRecord $customer, ObjectRecord $contact): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $this->guardObject($customer, 'customer');
        $this->guardObject($contact, 'customer_contact');
        abort_unless(($contact->payload['customer_id'] ?? null) === $customer->id, 404);
        $data = $this->contactData($request);

        DB::transaction(function () use ($contact, $data, $request): void {
            $locked = ObjectRecord::query()->lockForUpdate()->findOrFail($contact->id);
            $before = $locked->payload ?? [];
            $payload = [...$before, ...$data];
            $locked->update(['payload' => $payload, 'title' => $data['name']]);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'project.customer_contact.update',
                'subject_type' => 'customer_contact',
                'subject_id' => $locked->id,
                'payload' => ['before' => $before, 'after' => $payload],
            ]);
        });

        return response()->json(['contact' => $this->contactResponse($contact->fresh())]);
    }

    /** @return array{name: string, address: string|null, level: string|null, remark: string|null} */
    private function customerData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:500'],
            'level' => ['nullable', 'in:A,B,C'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array{name: string, phone: string|null} */
    private function contactData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function authorizeCustomerManager(Request $request): void
    {
        abort_unless($this->workspace->isAdmin($request->user()) || $this->workspace->isBusiness($request->user()), 403);
    }

    private function guardObject(ObjectRecord $record, string $key): void
    {
        abort_unless($record->businessObject->key === $key, 404);
    }

    /** @return array{id: string, name: string, phone: string|null, customer_id: string} */
    private function contactResponse(ObjectRecord $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->payload['name'] ?? $contact->title,
            'phone' => $contact->payload['phone'] ?? null,
            'customer_id' => $contact->payload['customer_id'],
        ];
    }
}
