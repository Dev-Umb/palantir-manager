<?php

namespace App\Http\Middleware;

use App\Models\BusinessObject;
use App\Models\ProjectNotification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $permissions = $user ? $user->permissionKeys() : [];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? $user->only('id', 'name', 'email') : null,
                'roles' => $user ? $user->roles->sortBy('label')->map->only(['id', 'name', 'label'])->values() : [],
                'permissions' => $permissions,
            ],
            'nav' => $user ? $this->navigation($request, $permissions) : [],
            'notificationUnreadCount' => fn () => $user
                ? ProjectNotification::query()
                    ->where('user_id', $user->id)
                    ->active()
                    ->whereNull('read_at')
                    ->count()
                : 0,
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ];
    }

    private function navigation(Request $request, array $permissions): array
    {
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $roles = $request->user()?->roles->pluck('name')->all() ?? [];
        $objects = BusinessObject::query()
            ->withCount(['records as new_task_count' => function ($query) use ($roles): void {
                $query->whereNotNull('workflow_key')->whereNull('workflow_seen_at');
                if (! in_array('admin', $roles, true)) {
                    $query->where(function ($targets) use ($roles): void {
                        foreach ($roles as $role) {
                            $targets->orWhereJsonContains('workflow_target_roles', $role);
                        }
                    });
                }
            }])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (BusinessObject $object) => $can("object.{$object->key}.view"))
            ->reject(fn (BusinessObject $object) => $object->key === 'customer_contact'
                || ($object->key === 'requisition'
                    && in_array('procurement', $roles, true)
                    && ! in_array('admin', $roles, true)))
            ->values();

        return [
            ['label' => '经营大盘', 'href' => route('dashboard'), 'visible' => $can('dashboard.view')],
            ['label' => '通知中心', 'href' => route('notifications.index'), 'visible' => true],
            ['label' => '提交采购申请', 'href' => route('requisitions.create'), 'visible' => $can('requisition.create')],
            ['label' => '采购OA审批', 'href' => route('requisitions.approvals'), 'visible' => $can('object.requisition.update')],
            ['label' => '现场报工', 'href' => route('team-logs.create'), 'visible' => $can('object.team_log.create')],
            [
                'label' => '本体工作台',
                'href' => route('objects.index'),
                'visible' => $objects->isNotEmpty(),
                'children' => $objects
                    ->groupBy('group')
                    ->map(fn ($items, string $group) => [
                        'label' => $group,
                        'items' => $items->map(fn (BusinessObject $object) => [
                            'label' => $object->label,
                            'href' => route('objects.index', $object->key),
                            'new_task_count' => (int) $object->new_task_count,
                        ])->values(),
                    ])
                    ->values(),
            ],
            ['label' => '用户与权限', 'href' => route('rbac.index'), 'visible' => $can('rbac.manage')],
            ['label' => 'AI 数据助手', 'href' => route('ai.index'), 'visible' => (bool) config('ai.harness_v2') && $can('ai.harness.view')],
        ];
    }
}
