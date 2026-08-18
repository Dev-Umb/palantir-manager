<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\CollectionProgress;
use Illuminate\Support\Facades\DB;

class UpdateProjectCollectionProgress
{
    public function __construct(private CollectionProgress $collectionProgress) {}

    public function expected(ObjectRecord $project): ?float
    {
        return $this->collectionProgress->percentage(
            $project->payload['occurred_amount'] ?? null,
            $project->payload['paid_amount'] ?? null,
        );
    }

    public function requiresUpdate(ObjectRecord $project, ?float $expected): bool
    {
        $payload = $project->payload ?? [];
        $current = $payload['payment_progress'] ?? null;

        if ($expected === null) {
            return array_key_exists('payment_progress', $payload) && $current !== null;
        }

        return ! is_numeric($current) || abs((float) $current - $expected) > 0.00001;
    }

    public function handle(string $projectId, ?User $user = null): bool
    {
        return DB::transaction(function () use ($projectId, $user): bool {
            $project = ObjectRecord::query()
                ->whereKey($projectId)
                ->whereRelation('businessObject', 'key', 'project')
                ->lockForUpdate()
                ->first();
            if (! $project) {
                return false;
            }

            $expected = $this->expected($project);
            if (! $this->requiresUpdate($project, $expected)) {
                return false;
            }

            $project->update(['payload' => [
                ...($project->payload ?? []),
                'payment_progress' => $expected,
            ]]);

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => 'object.project.collection-progress.recalculate',
                'subject_type' => 'project',
                'subject_id' => $project->id,
                'payload' => ['payment_progress' => $expected],
            ]);

            return true;
        });
    }
}
