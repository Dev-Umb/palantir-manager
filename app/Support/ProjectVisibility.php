<?php

namespace App\Support;

use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ProjectVisibility
{
    private const ROLE_STAGES = [
        'engineering' => ['技术确认', '正在对接', '设计出图', '采购执行', '生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'procurement' => ['采购执行', '生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'production' => ['生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'warehouse' => ['生产加工', '成品发货', '发货签收', '对账回款', '项目完成', '异常'],
        'finance' => ['对账回款', '项目完成', '异常'],
    ];

    private const ROLE_LINKS = [
        'engineering' => ['drawing'],
        'procurement' => ['purchase'],
        'production' => ['work_order', 'teardown', 'shipment', 'outbound'],
        'warehouse' => ['outbound'],
        'finance' => ['receivable'],
    ];

    public function scope(Builder|Relation $query, User $user): Builder|Relation
    {
        $roles = $user->relationLoaded('roles')
            ? $user->roles->pluck('name')->all()
            : $user->roles()->pluck('name')->all();

        if (in_array('admin', $roles, true) || in_array('business', $roles, true)) {
            return $query;
        }

        $stages = collect($roles)
            ->flatMap(fn (string $role) => self::ROLE_STAGES[$role] ?? [])
            ->unique()
            ->values()
            ->all();
        $linkedProjectIds = $this->linkedProjectIds($roles);

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

    private function linkedProjectIds(array $roles): array
    {
        $objectKeys = collect($roles)
            ->flatMap(fn (string $role) => self::ROLE_LINKS[$role] ?? [])
            ->unique()
            ->values()
            ->all();

        if (! $objectKeys) {
            return [];
        }

        return ObjectRecord::whereRelation('businessObject', fn (Builder $query) => $query->whereIn('key', $objectKeys))
            ->get()
            ->pluck('payload.project_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
