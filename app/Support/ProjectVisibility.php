<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class ProjectVisibility
{
    private const ROLE_STAGES = [
        'engineering' => ['技术确认', '正在对接', '设计出图', '采购执行', '生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'procurement' => ['采购执行', '生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'production_manager' => ['生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'production' => ['生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'finance' => ['对账回款', '项目完成', '异常'],
    ];

    private const ROLE_LINKS = [
        'engineering' => ['drawing'],
        'procurement' => ['purchase'],
        'production_manager' => ['work_order', 'teardown', 'shipment', 'team_log'],
        'production' => ['work_order', 'teardown', 'shipment', 'team_log'],
        'finance' => ['receivable'],
    ];

    public function scope(Builder|Relation $query, User $user): Builder|Relation
    {
        $roles = $this->roles($user);

        if (in_array('admin', $roles, true)) {
            return $query;
        }

        if (in_array('business', $roles, true)) {
            return $query->where('created_by', $user->id);
        }

        $stages = collect($roles)
            ->flatMap(fn (string $role) => self::ROLE_STAGES[$role] ?? [])
            ->unique()
            ->values()
            ->all();
        $linkedProjectIds = $this->linkedProjectIds($roles, $user);

        if (! $stages && ! $linkedProjectIds) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($stages, $linkedProjectIds) {
            if ($stages) {
                $query->whereIn('payload->stage', $stages);
            }

            if ($linkedProjectIds) {
                $method = $stages ? 'orWhereIn' : 'whereIn';
                $query->{$method}('id', $linkedProjectIds);
            }
        });
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

    private function linkedProjectIds(array $roles, User $user): array
    {
        $objectKeys = collect($roles)
            ->flatMap(fn (string $role) => self::ROLE_LINKS[$role] ?? [])
            ->unique()
            ->values()
            ->all();

        $linked = collect();
        if ($objectKeys) {
            $query = ObjectRecord::whereRelation('businessObject', fn (Builder $query) => $query->whereIn('key', $objectKeys));
            $driver = DB::connection()->getDriverName();
            $linked = match ($driver) {
                'pgsql' => $query->selectRaw("payload->>'project_id' as project_id")->pluck('project_id'),
                'sqlite' => $query->selectRaw("json_extract(payload, '$.project_id') as project_id")->pluck('project_id'),
                default => $query->get(['payload'])->pluck('payload.project_id'),
            };
        }

        if (in_array('finance', $roles, true)) {
            $linked = $linked->concat(
                ProjectNotification::query()
                    ->where('user_id', $user->id)
                    ->where('type', ProjectNotification::TYPE_PAYMENT)
                    ->pluck('project_id'),
            );
        }

        return $linked->filter()->unique()->values()->all();
    }
}
