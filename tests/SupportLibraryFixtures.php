<?php

namespace Tests;

use App\Actions\SaveSupportLibrary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait SupportLibraryFixtures
{
    protected function baseline(): array
    {
        return json_decode(file_get_contents(resource_path('support-library/baseline.json')), true);
    }

    protected function supportUser(array $actions = ['view', 'manage', 'export']): User
    {
        $this->artisan('support-library:install-permissions')->assertSuccessful();
        $role = Role::create(['name' => 'support-test-'.Str::uuid(), 'label' => '支架测试']);
        $role->permissions()->attach(Permission::whereIn('key', array_map(fn ($action) => 'support-library.'.$action, $actions))->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    protected function initializeLibrary(): void
    {
        app(SaveSupportLibrary::class)->initialize();
    }

    protected function queryInput(string $expression = '6@2@3/4', int $revision = 1): array
    {
        return ['library_revision' => $revision, 'request_fingerprint' => 'test-query',
            'entries' => [['expression' => $expression, 'criteria' => ['support_use' => '吊装', 'column_brace_model' => 'C14A', 'diameter_mm' => 273]]]];
    }

    protected function saveInput(array $db, int $revision = 1, ?string $requestId = null): array
    {
        return ['expected_revision' => $revision, 'request_id' => $requestId ?? (string) Str::uuid(), ...array_intersect_key($db, array_flip(['parts', 'frames', 'composition']))];
    }
}
