<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkbenchPanelTest extends TestCase
{
    use RefreshDatabase;

    private function technician(): User
    {
        $this->seed(XycPrototypeSeeder::class);
        $role = Role::create(['name' => 'workbench_technician', 'label' => '技术员']);
        $role->permissions()->sync(Permission::whereIn('key', ['material-arrivals.view', 'template-accessories.view'])->pluck('id'));
        $user = User::factory()->create(['password' => 'password123']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_login_and_all_panel_entries_work_without_business_data(): void
    {
        $user = $this->technician();
        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect('/');
        foreach (['/', '/dashboard', '/visualization'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('WorkbenchPanel')->has('nav', 4)
                ->where('nav.0.label', '主面板')->where('nav.1.visible', true)->where('nav.2.visible', true)
                ->missing('cockpit')->missing('recentProjects')->missing('visualization')
                ->where('notificationUnreadCount', 0));
        }
        foreach (['/material-arrivals', '/template-accessories'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/admin/rbac', '/objects/project', '/ai', '/notifications'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertFalse($user->canDo('dashboard.view'));
    }

    public function test_revoked_workbench_is_hidden_and_denied(): void
    {
        $user = $this->technician();
        $user->roles->first()->permissions()->sync(Permission::where('key', 'material-arrivals.view')->pluck('id'));
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nav.1.visible', true)->where('nav.2.visible', false));
        $this->get('/template-accessories')->assertForbidden();
    }

    public function test_existing_admin_dashboard_and_unprivileged_access_are_preserved(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')->has('cockpit'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Visualization')->has('visualization'));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
    }
}
