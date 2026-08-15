<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectRecordSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SyncXycMetadata::class)->handle();
    }

    public function test_projects_default_to_name_ascending_with_stable_ids(): void
    {
        $admin = $this->userWithRole('admin');
        $this->project('00000000-0000-4000-8000-000000000004', 'BETA', 'Beta Project', 900, $admin);
        $this->project('00000000-0000-4000-8000-000000000002', 'ALPHA-2', 'Alpha Project', 300, $admin);
        $this->project('00000000-0000-4000-8000-000000000001', 'ALPHA-1', 'Alpha Project', 100, $admin);

        $this->actingAs($admin)
            ->get('/objects/project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data', fn ($records): bool => collect($records)->pluck('code')->all() === [
                    'ALPHA-1',
                    'ALPHA-2',
                    'BETA',
                ]));
    }

    public function test_manual_project_sort_keeps_names_grouped_and_sorts_within_each_group(): void
    {
        $admin = $this->userWithRole('admin');
        $this->project('00000000-0000-4000-8000-000000000001', 'ALPHA-LOW', 'Alpha Project', 100, $admin);
        $this->project('00000000-0000-4000-8000-000000000002', 'ALPHA-HIGH', 'Alpha Project', 300, $admin);
        $this->project('00000000-0000-4000-8000-000000000003', 'BETA-HIGHEST', 'Beta Project', 999, $admin);
        $this->project('00000000-0000-4000-8000-000000000004', 'GAMMA', 'Gamma Project', 50, $admin);

        $this->actingAs($admin)
            ->get('/objects/project?sort=occurred_amount&direction=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data', fn ($records): bool => collect($records)->pluck('code')->all() === [
                    'ALPHA-HIGH',
                    'ALPHA-LOW',
                    'BETA-HIGHEST',
                    'GAMMA',
                ]));
    }

    public function test_manual_project_name_sort_respects_the_selected_direction(): void
    {
        $admin = $this->userWithRole('admin');
        $this->project('00000000-0000-4000-8000-000000000001', 'ALPHA', 'Alpha Project', 100, $admin);
        $this->project('00000000-0000-4000-8000-000000000002', 'BETA', 'Beta Project', 200, $admin);
        $this->project('00000000-0000-4000-8000-000000000003', 'GAMMA', 'Gamma Project', 300, $admin);

        $this->actingAs($admin)
            ->get('/objects/project?sort=name&direction=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data', fn ($records): bool => collect($records)->pluck('code')->all() === [
                    'GAMMA',
                    'BETA',
                    'ALPHA',
                ]));
    }

    public function test_non_project_objects_keep_their_recently_updated_default_order(): void
    {
        $admin = $this->userWithRole('admin');
        $older = $this->record('customer', 'OLDER', 'Alpha Customer', $admin);
        $newer = $this->record('customer', 'NEWER', 'Beta Customer', $admin);
        $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $newer->forceFill(['updated_at' => now()])->saveQuietly();

        $this->actingAs($admin)
            ->get('/objects/customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data', fn ($records): bool => collect($records)->pluck('code')->all() === [
                    'NEWER',
                    'OLDER',
                ]));
    }

    private function project(string $id, string $code, string $name, float $occurredAmount, User $creator): ObjectRecord
    {
        return $this->record('project', $code, $name, $creator, $id, [
            'name' => $name,
            'occurred_amount' => $occurredAmount,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function record(
        string $objectKey,
        string $code,
        string $title,
        User $creator,
        ?string $id = null,
        array $payload = [],
    ): ObjectRecord {
        $object = BusinessObject::query()->where('key', $objectKey)->firstOrFail();

        $record = new ObjectRecord([
            'business_object_id' => $object->id,
            'code' => $code,
            'title' => $title,
            'payload' => ['name' => $title, ...$payload],
            'created_by' => $creator->id,
        ]);
        if ($id !== null) {
            $record->id = $id;
        }
        $record->save();

        return $record;
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
