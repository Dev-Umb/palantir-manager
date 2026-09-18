<?php

namespace App\Http\Middleware;

use App\Models\BusinessObject;
use App\Models\ProjectNotification;
use App\Models\TenderNotification;
use App\Support\BusinessWorkspace;
use App\Support\HubAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $permissions = $user ? $user->permissionKeys() : [];
        $passwordOnly = $user?->isTimebookOperator() ?? false;
        if ($passwordOnly) {
            abort_unless($request->routeIs('timebook.*', 'ai.index', 'ai.messages', 'ai.conversations.show',
                'ai.runs.store', 'ai.runs.show', 'ai.runs.events', 'ai.runs.cancel',
                'settings.index', 'settings.email', 'settings.password', 'logout'), 403);
        }

        if ($request->is('procurement-hub', 'procurement-hub/*')) {
            $canRead = HubAccess::canRead($user);
            $canManage = HubAccess::canManage($user);

            return [
                ...parent::share($request),
                'auth' => ['user' => $user?->only('id', 'name', 'email', 'is_password_changed'), 'roles' => $user?->roles->map->only(['id', 'name', 'label'])->values() ?? [], 'permissions' => $permissions, 'settings_url' => Route::has('settings.index') ? route('settings.index') : null],
                'nav' => [
                    ['key' => 'hub', 'exact' => true, 'label' => '招采信息中心', 'href' => route('hub.index'), 'visible' => $canRead],
                    ['key' => 'hub-follows', 'label' => '我的关注', 'href' => route('hub.follows'), 'visible' => $canRead],
                    ['key' => 'platform', 'label' => '返回主平台', 'href' => '/', 'visible' => true],
                ],
                'notificationUnreadCount' => 0,
                'flash' => ['status' => fn () => $request->session()->get('status')],
            ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'password_only' => $passwordOnly,
                'user' => $user ? $user->only('id', 'name', 'email', 'is_password_changed') : null,
                'roles' => $user ? $user->roles->sortBy('label')->map->only(['id', 'name', 'label'])->values() : [],
                'permissions' => $permissions,
                'settings_url' => $user ? route('settings.index') : null,
            ],
            'nav' => $user ? $this->navigation($request, $permissions) : [],
            'notificationUnreadCount' => fn () => $user && ! $passwordOnly
                ? $this->unreadNotificationCount($user->id)
                : 0,
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ];
    }

    private function unreadNotificationCount(int $userId): int
    {
        $notifications = ProjectNotification::query()
            ->select(['user_id', 'status', 'read_at'])
            ->unionAll(
                TenderNotification::query()->select(['user_id', 'status', 'read_at']),
            );

        return DB::query()
            ->fromSub($notifications, 'notifications')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('read_at')
            ->count();
    }

    private function navigation(Request $request, array $permissions): array
    {
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $roles = $request->user()?->roles->pluck('name')->all() ?? [];
        if (in_array('timebook_operator', $roles, true)) {
            return [
                ['key' => 'timebook', 'label' => '工日簿', 'href' => route('timebook.index'), 'visible' => $can('timebook.view'), 'mobile_priority' => 25],
                ['key' => 'ai', 'label' => 'AI 数据助手', 'href' => route('ai.index'), 'visible' => (bool) config('ai.harness_v2') && $can('ai.harness.view'), 'mobile_priority' => 60],
                ['key' => 'settings', 'label' => '用户设置', 'href' => route('settings.index'), 'visible' => true, 'mobile_priority' => 90],
            ];
        }
        $objects = BusinessObject::query()
            ->whereIn('key', BusinessWorkspace::TABLE_OBJECT_KEYS)
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
            ->filter(fn (BusinessObject $object) => $can("object.{$object->key}.view")
                && ($object->key !== 'project_business_summary' || $can('object.project.view')))
            ->reject(fn (BusinessObject $object) => $object->key === 'requisition'
                    && in_array('procurement', $roles, true)
                    && ! in_array('admin', $roles, true))
            ->values();

        return [
            ['key' => 'dashboard', 'label' => '经营大盘', 'href' => route('dashboard'), 'visible' => $can('dashboard.view'), 'mobile_priority' => 10],
            ['key' => 'notifications', 'label' => '通知中心', 'href' => route('notifications.index'), 'visible' => true, 'mobile_priority' => 40],
            [
                'key' => 'ontology',
                'label' => '本体工作台',
                'href' => route('objects.index'),
                'visible' => $objects->isNotEmpty(),
                'mobile_priority' => 50,
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
            ['key' => 'settings', 'label' => '用户设置', 'href' => route('settings.index'), 'visible' => true, 'mobile_priority' => 90],
            ['key' => 'rbac', 'label' => '用户与权限', 'href' => route('rbac.index'), 'visible' => $can('rbac.manage'), 'mobile_priority' => 80],
            ['key' => 'hub', 'exact' => true, 'label' => '招采信息中心', 'href' => route('hub.index'), 'visible' => HubAccess::canRead($request->user()), 'mobile_priority' => 65],
            ['key' => 'ai', 'label' => 'AI 数据助手', 'href' => route('ai.index'), 'visible' => (bool) config('ai.harness_v2') && $can('ai.harness.view'), 'mobile_priority' => 60],
            ...($can('timebook.view') ? [['key' => 'timebook', 'label' => '工日簿', 'href' => route('timebook.index'), 'visible' => true, 'mobile_priority' => 25]] : []),
        ];
    }
}
