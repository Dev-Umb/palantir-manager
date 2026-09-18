<?php

namespace App\Support;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;

class HubProjectContext
{
    public const FIELDS = ['name', 'project_no', 'stage', 'overall_status', 'remark', 'delivery_date', 'weight'];

    /** Only a projection of project master rows; never hydrate ERP relations. */
    public function forUser(?User $user): array
    {
        $projects = [];
        if ($user && HubAccess::canRead($user) && $user->canDo('object.project.view')) {
            $admin = $user->roles->contains('name', 'admin');
            $business = $user->roles->contains('name', 'business');
            if ($admin || $business) {
                $objectId = BusinessObject::where('key', 'project')->value('id');
                if ($objectId) {
                    $columns = ['id', 'code', 'title', ...array_map(fn ($field) => 'payload->'.$field.' as '.$field, self::FIELDS)];
                    $query = ObjectRecord::query()->where('business_object_id', $objectId);
                    $projects = app(ProjectVisibility::class)->scope($query, $user)
                        ->orderByDesc('updated_at')->orderBy('id')->limit(1000)->get($columns)
                        ->map(fn ($row) => $row->only(['id', 'code', 'title', ...self::FIELDS]))->all();
                }
            }
        }

        return ['project_user_id' => $user?->id, 'projects' => $projects,
            'project_version' => hash('sha256', json_encode([$user?->id, $projects], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    public function current(array $snapshot): bool
    {
        if (! array_key_exists('project_version', $snapshot)) {
            return false;
        }
        $context = $this->forUser(isset($snapshot['project_user_id']) ? User::find($snapshot['project_user_id']) : null);

        return hash_equals($context['project_version'], $snapshot['project_version']);
    }
}
