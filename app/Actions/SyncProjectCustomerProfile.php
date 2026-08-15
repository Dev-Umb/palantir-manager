<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SyncProjectCustomerProfile
{
    /** @var array<string, string> */
    private const CUSTOMER_FIELD_LABELS = [
        'name' => '客户名称',
        'address' => '客户地址',
        'level' => '客户等级',
        'customer_nature' => '客户性质',
    ];

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     * @return array{customer: array<string, mixed>|null, conflicts: array<int, array{field: string, label: string, current: string, submitted: string}>}
     */
    public function preview(array $profile): array
    {
        [$customer, $identityMatch] = $this->resolveCustomer($profile);

        return [
            'customer' => $customer ? $this->customerSummary($customer) : null,
            'conflicts' => $this->customerConflicts($profile, $customer, $identityMatch),
        ];
    }

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     * @return array{customer_id: string, contact_ids: array<int, string>}
     */
    public function handle(array $profile, User $user, CreateObjectRecord $writer): array
    {
        [$customer, $identityMatch] = $this->resolveCustomer($profile, lock: true);
        $conflicts = $this->customerConflicts($profile, $customer, $identityMatch);
        if ($conflicts !== [] && ! $profile['overwrite_confirmed']) {
            throw ValidationException::withMessages([
                'payload.customer_profile' => '客户资料已发生冲突，请检查差异并确认是否覆盖。',
            ]);
        }

        $customer = $customer
            ? $this->updateCustomer($customer, $profile, $user, $writer)
            : $writer->handle(
                $this->customerObject(),
                $this->customerPayload($profile),
                $user,
                'project.customer.create',
            );

        return [
            'customer_id' => $customer->id,
            'contact_ids' => $this->syncContacts($customer, $profile['contacts'], $user, $writer),
        ];
    }

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     * @return array{0: ObjectRecord|null, 1: ObjectRecord|null}
     */
    private function resolveCustomer(array $profile, bool $lock = false): array
    {
        $query = $this->customerObject()->records()->with('businessObject');
        if ($lock) {
            $query->lockForUpdate();
        }

        $selected = $profile['customer_id']
            ? (clone $query)->whereKey($profile['customer_id'])->first()
            : null;
        if ($profile['customer_id'] && ! $selected) {
            throw ValidationException::withMessages([
                'customer_id' => '所选客户不存在或类型不正确。',
            ]);
        }
        $identityMatch = $this->findCustomerByIdentity($query, $profile['name'], $profile['address']);

        return [$identityMatch ?? $selected, $identityMatch];
    }

    private function findCustomerByIdentity(HasMany $query, string $name, string $address): ?ObjectRecord
    {
        $candidates = (clone $query)
            ->whereRaw('TRIM(title) = ?', [$name])
            ->orderBy('id')
            ->get();

        return $candidates->first(
            fn (ObjectRecord $customer): bool => $this->text($customer->payload['name'] ?? $customer->title) === $name
                && $this->text($customer->payload['address'] ?? '') === $address,
        );
    }

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     * @return array<int, array{field: string, label: string, current: string, submitted: string}>
     */
    private function customerConflicts(array $profile, ?ObjectRecord $customer, ?ObjectRecord $identityMatch): array
    {
        if (! $customer) {
            return [];
        }

        $fields = $profile['customer_id'] === $customer->id
            ? array_keys(self::CUSTOMER_FIELD_LABELS)
            : ['level', 'customer_nature'];
        $conflicts = collect($fields)
            ->map(function (string $field) use ($customer, $profile): ?array {
                $current = $this->text($customer->payload[$field] ?? ($field === 'name' ? $customer->title : ''));
                $submitted = $profile[$field];
                if ($current === $submitted) {
                    return null;
                }

                return [
                    'field' => $field,
                    'label' => self::CUSTOMER_FIELD_LABELS[$field],
                    'current' => $current,
                    'submitted' => $submitted,
                ];
            })
            ->filter()
            ->values();

        if ($profile['customer_id'] && $identityMatch && $profile['customer_id'] !== $identityMatch->id) {
            $conflicts->prepend([
                'field' => 'customer_id',
                'label' => '客户记录',
                'current' => '当前选择的客户',
                'submitted' => '名称和地址已匹配另一条客户记录，将改为关联该记录',
            ]);
        }

        return $conflicts->all();
    }

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     */
    private function updateCustomer(
        ObjectRecord $customer,
        array $profile,
        User $user,
        CreateObjectRecord $writer,
    ): ObjectRecord {
        $before = $customer->payload ?? [];
        $payload = $writer->normalizePayload(
            $customer->businessObject,
            [...$before, ...$this->customerPayload($profile)],
            $before,
            $user,
        );
        if ($payload === $before && $customer->title === $profile['name']) {
            return $customer;
        }

        $customer->update(['payload' => $payload, 'title' => $profile['name']]);
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'project.customer.update',
            'subject_type' => 'customer',
            'subject_id' => $customer->id,
            'payload' => ['before' => $before, 'after' => $payload],
        ]);

        return $customer;
    }

    /**
     * @param  array<int, array{id: string|null, name: string, phone: string}>  $contacts
     * @return array<int, string>
     */
    private function syncContacts(
        ObjectRecord $customer,
        array $contacts,
        User $user,
        CreateObjectRecord $writer,
    ): array {
        $contactObject = $this->contactObject();
        $existingContacts = $contactObject->records()
            ->with('businessObject')
            ->where('payload->customer_id', $customer->id)
            ->lockForUpdate()
            ->get();

        return collect($contacts)
            ->map(function (array $contact) use ($customer, $user, $writer, $contactObject, $existingContacts): string {
                $identityMatch = $this->contactByIdentity($existingContacts, $contact['name'], $contact['phone']);
                $selected = $contact['id'] ? $existingContacts->firstWhere('id', $contact['id']) : null;
                if ($contact['id'] && ! $selected) {
                    throw ValidationException::withMessages([
                        'payload.customer_profile.contacts' => '所选联系人不属于当前客户。',
                    ]);
                }
                $resolved = $identityMatch ?? $selected;

                if (! $resolved) {
                    $resolved = $writer->handle(
                        $contactObject,
                        [
                            'name' => $contact['name'],
                            'phone' => $contact['phone'],
                            'customer_id' => $customer->id,
                        ],
                        $user,
                        'project.customer_contact.create',
                    );
                    $existingContacts->push($resolved);

                    return $resolved->id;
                }

                if ($resolved->id === $selected?->id) {
                    $this->updateContact($resolved, $contact, $user);
                }

                return $resolved->id;
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{id: string|null, name: string, phone: string} $contact */
    private function updateContact(ObjectRecord $record, array $contact, User $user): void
    {
        $before = $record->payload ?? [];
        $payload = [...$before, 'name' => $contact['name'], 'phone' => $contact['phone']];
        if ($payload === $before && $record->title === $contact['name']) {
            return;
        }

        $record->update(['payload' => $payload, 'title' => $contact['name']]);
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'project.customer_contact.update',
            'subject_type' => 'customer_contact',
            'subject_id' => $record->id,
            'payload' => ['before' => $before, 'after' => $payload],
        ]);
    }

    private function contactByIdentity(Collection $contacts, string $name, string $phone): ?ObjectRecord
    {
        return $contacts->first(
            fn (ObjectRecord $contact): bool => $this->text($contact->payload['name'] ?? $contact->title) === $name
                && $this->text($contact->payload['phone'] ?? '') === $phone,
        );
    }

    /**
     * @param  array{customer_id: string|null, name: string, address: string, level: string, customer_nature: string, overwrite_confirmed: bool, contacts: array<int, array{id: string|null, name: string, phone: string}>}  $profile
     * @return array{name: string, address: string, level: string, customer_nature: string}
     */
    private function customerPayload(array $profile): array
    {
        return [
            'name' => $profile['name'],
            'address' => $profile['address'],
            'level' => $profile['level'],
            'customer_nature' => $profile['customer_nature'],
        ];
    }

    /** @return array{id: string, name: string, address: string, level: string, customer_nature: string} */
    private function customerSummary(ObjectRecord $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $this->text($customer->payload['name'] ?? $customer->title),
            'address' => $this->text($customer->payload['address'] ?? ''),
            'level' => $this->text($customer->payload['level'] ?? ''),
            'customer_nature' => $this->text($customer->payload['customer_nature'] ?? ''),
        ];
    }

    private function customerObject(): BusinessObject
    {
        return BusinessObject::query()->where('key', 'customer')->firstOrFail();
    }

    private function contactObject(): BusinessObject
    {
        return BusinessObject::query()->where('key', 'customer_contact')->firstOrFail();
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
