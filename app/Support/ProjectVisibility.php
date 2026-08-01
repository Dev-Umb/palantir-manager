<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ProjectVisibility
{
    public function scope(Builder|Relation $query, User $user): Builder|Relation
    {
        $roles = $this->roles($user);

        if (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            return $query;
        }

        if (in_array('business', $roles, true)) {
            return $query->where('payload->business_owner_user_id', (string) $user->id);
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

        if ($this->isAdmin($user)) {
            return $query;
        }

        $projectField = $this->projectField($object);
        if (! $projectField) {
            return $query;
        }

        $projectIds = $this->visibleProjectIds($user);

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

    /** @return array<int, string> */
    public function visibleProjectIds(User $user): array
    {
        $project = BusinessObject::where('key', 'project')->first();
        if (! $project) {
            return [];
        }

        return $this->scope($project->records(), $user)
            ->pluck('id')
            ->all();
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

    private function projectField(BusinessObject $object): ?array
    {
        return collect($object->fields ?? [])->first(
            fn (array $field) => ($field['target'] ?? null) === 'project'
                && ($field['key'] ?? null) === 'project_id'
                && ($field['type'] ?? null) === 'relation',
        );
    }
}
