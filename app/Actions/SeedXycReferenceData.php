<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SeedXycReferenceData
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            $objects = BusinessObject::whereIn('key', ['material', 'production_team', 'team_member'])
                // SQLite omits FOR UPDATE in tests; PostgreSQL uses these ordered metadata-row locks
                // to serialize the reference-data query-then-create sequence across concurrent deploys.
                ->orderBy('key')
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            foreach (['material', 'production_team', 'team_member'] as $key) {
                if (! $objects->has($key)) {
                    throw new RuntimeException("Business object metadata [{$key}] must be synced before reference data.");
                }
            }

            $this->assertUniqueMaterialCodes($objects['material']);
            $materialCounts = $this->seedMaterials($objects['material']);
            $teamCounts = $this->seedTeamsAndMembers($objects['production_team'], $objects['team_member']);

            AuditLog::create([
                'user_id' => null,
                'action' => 'system.reference_data.seed',
                'subject_type' => 'system',
                'subject_id' => null,
                'payload' => [
                    'created' => [
                        'material' => $materialCounts['created'],
                        'production_team' => $teamCounts['production_team']['created'],
                        'team_member' => $teamCounts['team_member']['created'],
                    ],
                    'preserved' => [
                        'material' => $materialCounts['preserved'],
                        'production_team' => $teamCounts['production_team']['preserved'],
                        'team_member' => $teamCounts['team_member']['preserved'],
                    ],
                ],
            ]);
        });
    }

    /** @return array{created: int, preserved: int} */
    private function seedMaterials(BusinessObject $object): array
    {
        $materials = require database_path('data/materials.php');
        $counts = ['created' => 0, 'preserved' => 0];

        foreach ($materials as $material) {
            $record = $object->records()
                ->where('code', $material['material_code'])
                ->first()
                ?? $object->records()
                    ->where('payload->material_code', $material['material_code'])
                    ->first();
            if ($record) {
                $counts['preserved']++;

                continue;
            }

            $this->createRecord(
                $object,
                $material['material_code'],
                $material['name'],
                $this->snapshotPayload($material, ['status' => '启用']),
            );
            $counts['created']++;
        }

        return $counts;
    }

    private function assertUniqueMaterialCodes(BusinessObject $object): void
    {
        $duplicate = $object->records()
            ->get()
            ->groupBy(fn (ObjectRecord $record) => (string) ($record->payload['material_code'] ?? ''))
            ->reject(fn ($records, string $code) => $code === '' || $records->count() < 2)
            ->first();

        if (! $duplicate) {
            return;
        }

        $code = (string) ($duplicate->first()->payload['material_code'] ?? '');

        throw new RuntimeException(sprintf(
            'Duplicate material_code [%s] found in %d existing records.',
            $code,
            $duplicate->count(),
        ));
    }

    /** @return array{production_team: array{created: int, preserved: int}, team_member: array{created: int, preserved: int}} */
    private function seedTeamsAndMembers(BusinessObject $teamObject, BusinessObject $memberObject): array
    {
        $snapshot = require database_path('data/team-members.php');
        $teams = [];
        $counts = [
            'production_team' => ['created' => 0, 'preserved' => 0],
            'team_member' => ['created' => 0, 'preserved' => 0],
        ];

        foreach ($snapshot['teams'] as $index => $team) {
            $code = sprintf('%s-REF-%03d', $teamObject->code_prefix, $index + 1);
            $record = $teamObject->records()->where('code', $code)->first();
            if ($record) {
                $counts['production_team']['preserved']++;
            } else {
                $payload = $this->snapshotPayload($team, ['status' => '启用']);
                $payload['leader_id'] = null;
                $record = $this->createRecord($teamObject, $code, $team['name'], $payload);
                $counts['production_team']['created']++;
            }

            $teams[$team['name']] = $record;
        }

        foreach ($snapshot['members'] as $index => $member) {
            $team = $teams[$member['team']] ?? null;
            if (! $team) {
                throw new RuntimeException("Reference member [{$member['name']}] has unknown team [{$member['team']}].");
            }

            $code = sprintf('%s-REF-%03d', $memberObject->code_prefix, $index + 1);
            $record = $memberObject->records()->where('code', $code)->first();
            if ($record) {
                $counts['team_member']['preserved']++;

                continue;
            }

            $this->createRecord($memberObject, $code, $member['name'], $this->snapshotPayload([
                'name' => $member['name'],
                'team_id' => $team->id,
                'status' => $member['status'] ?? null,
                'remark' => $member['remark'] ?? null,
            ], ['status' => '启用']));
            $counts['team_member']['created']++;
        }

        return $counts;
    }

    private function snapshotPayload(array $snapshot, array $defaults): array
    {
        $payload = $snapshot;

        foreach ($defaults as $key => $value) {
            if (($payload[$key] ?? null) === null || $payload[$key] === '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    private function createRecord(
        BusinessObject $object,
        string $code,
        string $title,
        array $payload,
    ): ObjectRecord {
        return ObjectRecord::create([
            'business_object_id' => $object->id,
            'code' => $code,
            'title' => $title,
            'payload' => $payload,
        ]);
    }
}
