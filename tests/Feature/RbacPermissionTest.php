<?php

namespace Tests\Feature;

use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbidden_routes_and_project_visibility_remain_server_enforced(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $basic = $this->userWithRole('basic');
        $this->actingAs($basic);
        $this->get('/objects')->assertForbidden();
        $this->get('/admin/rbac')->assertForbidden();
        $this->get('/procurement/approvals')->assertForbidden();

        $businessA = $this->userWithRole('business', 'a');
        $businessB = $this->userWithRole('business', 'b');
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $project->update(['created_by' => $businessA->id]);
        $this->actingAs($businessB)->get("/objects/project?record={$project->id}")
            ->assertOk()->assertDontSee($project->title);
    }

    private function userWithRole(string $role, string $suffix = ''): User
    {
        $user = User::create(['name' => $role, 'email' => "{$role}{$suffix}-rbac@example.com", 'password' => Hash::make('password123')]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
