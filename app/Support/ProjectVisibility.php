<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class ProjectVisibility
{
    public function scope(Builder|Relation $query, User $user): Builder|Relation
    {
        $roles = $this->roles($user);

        if (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            return $query;
        }

        if (in_array('business', $roles, true)) {
            return $query->where(function (Builder $query) use ($user): void {
                $query->where('payload->business_owner_user_id', (string) $user->id)
                    ->orWhereJsonContains('payload->informed_business_user_ids', (string) $user->id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeRecords(
        Builder|Relation $query,
        BusinessObject $object,
        User $user,
    ): Builder|Relation {
        if ($object->key === 'project') {
            return $this->scope($query, $user);
        }

        if ($object->key === 'tender') {
            return $query;
        }

        $roles = $this->roles($user);
        if ($this->isAdmin($user)) {
            return $query;
        }

        if (in_array($object->key, ['customer', 'customer_contact'], true)
            && $this->hasElevatedCustomerScope($roles)) {
            return $query;
        }

        if (in_array('business', $roles, true) && $object->key === 'customer') {
            return $this->scopeCustomers($query, $user);
        }

        if (in_array('business', $roles, true) && $object->key === 'customer_contact') {
            return $this->scopeCustomerContacts($query, $user);
        }

        $projectField = $this->projectField($object);
        if (! $projectField) {
            return $query;
        }

        $projectIds = $this->ownedProjectIds($user);

        return $query->where(function (Builder $query) use ($projectField, $projectIds, $user): void {
            if ($projectIds) {
                $query->whereIn("payload->{$projectField['key']}", $projectIds);
            }

            $method = $projectIds ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $query) use ($projectField, $user): void {
                $query->where('created_by', $user->id)
                    ->where(function (Builder $query) use ($projectField): void {
                        $query->whereNull("payload->{$projectField['key']}")
                            ->orWhere("payload->{$projectField['key']}", '');
                    });
            });
        });
    }

    public function allowsProject(User $user, ObjectRecord $project): bool
    {
        $project->loadMissing('businessObject');
        if ($project->businessObject?->key !== 'project') {
            return false;
        }

        return $this->scope(
            $project->businessObject->records()->whereKey($project->id),
            $user,
        )->exists();
    }

    public function allowsRecord(User $user, ObjectRecord $record): bool
    {
        $record->loadMissing('businessObject');
        $object = $record->businessObject;
        if (! $object) {
            return false;
        }

        return $this->scopeRecords(
            $object->records()->whereKey($record->id),
            $object,
            $user,
        )->exists();
    }

    public function allowsProjectUpdate(User $user, ObjectRecord $project): bool
    {
        $roles = $this->roles($user);
        if (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            return true;
        }

        return in_array('business', $roles, true)
            && (string) ($project->payload['business_owner_user_id'] ?? '') === (string) $user->id;
    }

    public function isInformedProject(User $user, ObjectRecord $project): bool
    {
        if (! in_array('business', $this->roles($user), true)
            || (string) ($project->payload['business_owner_user_id'] ?? '') === (string) $user->id) {
            return false;
        }

        return in_array(
            (string) $user->id,
            array_map('strval', is_array($project->payload['informed_business_user_ids'] ?? null)
                ? $project->payload['informed_business_user_ids']
                : []),
            true,
        );
    }

    /**
     * @param  array<int, string>|null  $projectIds
     * @return array<int, string>
     */
    public function visibleProjectIds(User $user, ?array $projectIds = null): array
    {
        $project = BusinessObject::where('key', 'project')->first();
        if (! $project) {
            return [];
        }

        $query = $this->scope($project->records(), $user);
        if ($projectIds !== null) {
            $query->whereIn('id', $projectIds);
        }

        return $query
            ->pluck('id')
            ->all();
    }

    /** @return array<int, string> */
    private function ownedProjectIds(User $user): array
    {
        $project = BusinessObject::where('key', 'project')->first();
        if (! $project) {
            return [];
        }

        $query = $project->records();
        $roles = $this->roles($user);
        if (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            return $query->pluck('id')->all();
        }
        if (in_array('business', $roles, true)) {
            return $query->where('payload->business_owner_user_id', (string) $user->id)->pluck('id')->all();
        }

        return [];
    }

    /** @return array<int, string> */
    private function roles(User $user): array
    {
        $user->loadMissing('roles');

        return $user->roles->pluck('name')->all();
    }

    private function isAdmin(User $user): bool
    {
        return in_array('admin', $this->roles($user), true);
    }

    /** @param array<int, string> $roles */
    private function hasElevatedCustomerScope(array $roles): bool
    {
        return collect(['admin', 'finance', 'tender'])->contains(
            fn (string $role): bool => in_array($role, $roles, true),
        );
    }

    private function scopeCustomers(Builder|Relation $query, User $user): Builder|Relation
    {
        $projectObjectId = BusinessObject::query()->where('key', 'project')->value('id');
        if (! $projectObjectId) {
            return $query->where('created_by', $user->id);
        }

        $recordsTable = (new ObjectRecord)->getTable();
        $driver = DB::connection()->getDriverName();
        $projectCustomerExpression = $driver === 'pgsql'
            ? "customer_projects.payload->>'customer_id'"
            : "json_extract(customer_projects.payload, '$.customer_id')";
        $projectOwnerExpression = $driver === 'pgsql'
            ? "customer_projects.payload->>'business_owner_user_id'"
            : "json_extract(customer_projects.payload, '$.business_owner_user_id')";
        $customerIdExpression = $driver === 'pgsql'
            ? "{$recordsTable}.id::text"
            : "{$recordsTable}.id";

        return $query->where(function (Builder $customers) use (
            $projectObjectId,
            $recordsTable,
            $projectCustomerExpression,
            $projectOwnerExpression,
            $customerIdExpression,
            $user,
        ): void {
            $customers->whereExists(function ($projects) use (
                $projectObjectId,
                $recordsTable,
                $projectCustomerExpression,
                $projectOwnerExpression,
                $customerIdExpression,
                $user,
            ): void {
                $projects->selectRaw('1')
                    ->from("{$recordsTable} as customer_projects")
                    ->where('customer_projects.business_object_id', $projectObjectId)
                    ->whereRaw("{$projectCustomerExpression} = {$customerIdExpression}")
                    ->whereRaw("{$projectOwnerExpression} = ?", [(string) $user->id]);
            })->orWhere(function (Builder $createdCustomers) use (
                $projectObjectId,
                $recordsTable,
                $projectCustomerExpression,
                $customerIdExpression,
                $user,
            ): void {
                $createdCustomers->where('created_by', $user->id)
                    ->whereNotExists(function ($projects) use (
                        $projectObjectId,
                        $recordsTable,
                        $projectCustomerExpression,
                        $customerIdExpression,
                    ): void {
                        $projects->selectRaw('1')
                            ->from("{$recordsTable} as customer_projects")
                            ->where('customer_projects.business_object_id', $projectObjectId)
                            ->whereRaw("{$projectCustomerExpression} = {$customerIdExpression}");
                    });
            });
        });
    }

    private function scopeCustomerContacts(Builder|Relation $query, User $user): Builder|Relation
    {
        $customerObject = BusinessObject::query()->where('key', 'customer')->first();
        if (! $customerObject) {
            return $query->whereRaw('1 = 0');
        }

        $visibleCustomersQuery = $customerObject->records();
        if (DB::connection()->getDriverName() === 'pgsql') {
            $visibleCustomersQuery->selectRaw('id::text');
        } else {
            $visibleCustomersQuery->select('id');
        }
        $visibleCustomers = $this->scopeCustomers($visibleCustomersQuery, $user);

        return $query->whereIn('payload->customer_id', $visibleCustomers);
    }

    private function projectField(BusinessObject $object): ?array
    {
        return collect($object->fields ?? [])->first(
            fn (array $field) => ($field['target'] ?? null) === 'project'
                && ($field['key'] ?? null) === 'project_id'
                && ($field['type'] ?? null) === 'relation',
        );
    }
}
