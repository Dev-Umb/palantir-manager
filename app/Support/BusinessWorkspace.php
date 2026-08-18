<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class BusinessWorkspace
{
    public const RETAINED_OBJECT_KEYS = [
        'customer',
        'customer_contact',
        'tender',
        'project',
        'project_business_summary',
        'contract',
    ];

    public const TABLE_OBJECT_KEYS = [
        'customer',
        'tender',
        'project',
        'project_business_summary',
        'contract',
    ];

    public const FINANCE_FIELD_KEYS = [
        'contract_amount',
        'occurred_amount',
        'paid_amount',
        'last_payment_date',
        'unpaid_amount',
        'reconciled_amount',
        'invoiced_amount',
        'uninvoiced_amount',
        'payment_progress',
        'payment_status',
        'signed_weight',
    ];

    private const PROJECT_SYSTEM_FIELD_KEYS = [
        'project_no',
        'contract_status',
        'contract_qty',
        'collection_count',
    ];

    public function allowsDirectObjectAccess(BusinessObject|string $object): bool
    {
        $key = $object instanceof BusinessObject ? $object->key : $object;

        return in_array($key, self::RETAINED_OBJECT_KEYS, true);
    }

    public function allowsObjectTableAccess(BusinessObject|string $object): bool
    {
        $key = $object instanceof BusinessObject ? $object->key : $object;

        return in_array($key, self::TABLE_OBJECT_KEYS, true);
    }

    /** @return array<int, string> */
    public function roleNames(User $user): array
    {
        $user->loadMissing('roles');

        return $user->roles->pluck('name')->all();
    }

    public function isAdmin(User $user): bool
    {
        return in_array('admin', $this->roleNames($user), true);
    }

    public function isFinance(User $user): bool
    {
        return in_array('finance', $this->roleNames($user), true);
    }

    public function isBusiness(User $user): bool
    {
        return in_array('business', $this->roleNames($user), true);
    }

    public function isTender(User $user): bool
    {
        return in_array('tender', $this->roleNames($user), true);
    }

    /** @return array<int, string> */
    public function writableFieldKeys(BusinessObject $object, User $user): array
    {
        if ($object->key === 'customer') {
            return $this->isAdmin($user) || $this->isBusiness($user) || $this->isTender($user)
                ? $this->editableMetadataKeys($object)
                : [];
        }

        if ($object->key === 'customer_contact') {
            return $this->isAdmin($user) || $this->isBusiness($user)
                ? $this->editableMetadataKeys($object)
                : [];
        }

        if ($object->key === 'tender') {
            return $this->isAdmin($user) || $this->isTender($user)
                ? $this->editableMetadataKeys($object)
                : [];
        }

        if ($object->key === 'contract') {
            return $this->isAdmin($user)
                ? $this->editableMetadataKeys($object)
                : [];
        }

        if ($object->key !== 'project') {
            return [];
        }

        if ($this->isAdmin($user)) {
            return collect($this->editableMetadataKeys($object))
                ->reject(fn (string $key): bool => in_array($key, self::PROJECT_SYSTEM_FIELD_KEYS, true))
                ->values()
                ->all();
        }

        if ($this->isFinance($user)) {
            return self::FINANCE_FIELD_KEYS;
        }

        if ($this->isBusiness($user)) {
            return collect($this->editableMetadataKeys($object))
                ->reject(fn (string $key): bool => in_array($key, [
                    ...self::FINANCE_FIELD_KEYS,
                    ...self::PROJECT_SYSTEM_FIELD_KEYS,
                    'business_owner_user_id',
                ], true))
                ->values()
                ->all();
        }

        return [];
    }

    public function canDelete(BusinessObject $object, User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return ($this->isBusiness($user)
            && in_array($object->key, ['customer', 'customer_contact'], true))
            || ($this->isTender($user) && $object->key === 'tender');
    }

    /** @return array<int, array<string, mixed>> */
    public function fieldsForUser(BusinessObject $object, User $user): array
    {
        $writable = array_flip($this->writableFieldKeys($object, $user));

        return collect($object->fields ?? [])->map(function (array $field) use ($writable): array {
            $intrinsicallyReadonly = ($field['readonly'] ?? false)
                || in_array($field['type'] ?? null, ['readonly', 'lookup', 'derived'], true);
            if (! $intrinsicallyReadonly && ! isset($writable[$field['key'] ?? ''])) {
                $field['readonly'] = true;
            }

            return $field;
        })->all();
    }

    /** @return Collection<int, array{id: int, label: string}> */
    public function businessAccountOptions(): Collection
    {
        $businessRole = Role::query()->where('name', 'business')->first();
        if (! $businessRole) {
            return collect();
        }

        return $businessRole->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'label' => $user->name]);
    }

    /** @return array<int, string> */
    private function editableMetadataKeys(BusinessObject $object): array
    {
        return collect($object->fields ?? [])
            ->reject(fn (array $field): bool => ($field['readonly'] ?? false)
                || in_array($field['type'] ?? null, ['readonly', 'lookup', 'derived'], true))
            ->pluck('key')
            ->filter()
            ->values()
            ->all();
    }
}
