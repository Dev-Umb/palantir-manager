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
use Illuminate\Validation\ValidationException;

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
        $contactData = $this->combinedContactData($request);
        [$customer, $contact] = DB::transaction(function () use ($data, $contactData, $request, $writer): array {
            $customer = $writer->handle(
                BusinessObject::where('key', 'customer')->firstOrFail(),
                $data,
                $request->user(),
                'project.customer.create',
            );
            $contact = $this->saveCombinedContact($customer, $contactData, $request, $writer);

            return [$customer, $contact];
        });
        $this->relations->preloadLabels(collect([$customer]), $request->user());

        return response()->json([
            'customer' => $this->relations->formatRecord($customer, $request->user()),
            'contact' => $contact ? $this->contactResponse($contact) : null,
        ], 201);
    }

    public function update(Request $request, ObjectRecord $customer, CreateObjectRecord $writer): JsonResponse
    {
        $this->authorizeCustomerManager($request);
        $this->guardObject($customer, 'customer');
        $data = $this->customerData($request);
        $contactData = $this->combinedContactData($request);

        $contact = DB::transaction(function () use ($customer, $data, $contactData, $request, $writer): ?ObjectRecord {
            $this->relations->lockReferenceGraph();
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

            return $this->saveCombinedContact($locked, $contactData, $request, $writer);
        });

        $fresh = $customer->fresh(['businessObject']);
        $this->relations->preloadLabels(collect([$fresh]), $request->user());

        return response()->json([
            'customer' => $this->relations->formatRecord($fresh, $request->user()),
            'contact' => $contact ? $this->contactResponse($contact) : null,
        ]);
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

    /** @return array{id: string|null, name: string, phone: string|null}|null */
    private function combinedContactData(Request $request): ?array
    {
        if (! $request->has('contact')) {
            return null;
        }

        $validated = $request->validate([
            'contact' => ['required', 'array'],
            'contact.id' => ['nullable', 'uuid'],
            'contact.name' => ['required', 'string', 'max:100'],
            'contact.phone' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'id' => $validated['contact']['id'] ?? null,
            'name' => $validated['contact']['name'],
            'phone' => $validated['contact']['phone'] ?? null,
        ];
    }

    /** @param array{id: string|null, name: string, phone: string|null}|null $data */
    private function saveCombinedContact(
        ObjectRecord $customer,
        ?array $data,
        Request $request,
        CreateObjectRecord $writer,
    ): ?ObjectRecord {
        if ($data === null) {
            return null;
        }

        if (! $data['id']) {
            return $writer->handle(
                BusinessObject::where('key', 'customer_contact')->firstOrFail(),
                ['name' => $data['name'], 'phone' => $data['phone'], 'customer_id' => $customer->id],
                $request->user(),
                'project.customer_contact.create',
            );
        }

        $contact = ObjectRecord::query()
            ->with('businessObject')
            ->lockForUpdate()
            ->find($data['id']);
        if (! $contact
            || $contact->businessObject->key !== 'customer_contact'
            || ($contact->payload['customer_id'] ?? null) !== $customer->id) {
            throw ValidationException::withMessages([
                'contact.id' => '联系人不属于当前客户。',
            ]);
        }

        $before = $contact->payload ?? [];
        $payload = [...$before, 'name' => $data['name'], 'phone' => $data['phone']];
        $contact->update(['payload' => $payload, 'title' => $data['name']]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'project.customer_contact.update',
            'subject_type' => 'customer_contact',
            'subject_id' => $contact->id,
            'payload' => ['before' => $before, 'after' => $payload],
        ]);

        return $contact;
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
