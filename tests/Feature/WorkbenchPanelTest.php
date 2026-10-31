<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                ->component('WorkbenchPanel')->has('nav', 3)
                ->where('nav.0.label', '主面板')->where('nav.1.key', 'production-workbench')->where('nav.1.visible', true)
                ->missing('cockpit')->missing('recentProjects')->missing('visualization')
                ->where('notificationUnreadCount', 0));
        }
        foreach (['/material-arrivals', '/template-accessories'] as $url) {
            $this->get($url)->assertRedirect();
        }
        foreach (['/admin/rbac', '/objects/project', '/ai', '/notifications'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertFalse($user->canDo('dashboard.view'));
    }

    public function test_unified_page_preserves_scoped_entries_and_external_shared_libraries(): void
    {
        $this->actingAs($this->technician())->get('/production-workbench')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ProductionWorkbench')
                ->has('workbenches', 2)->where('workbenches.material.manage', false)
                ->where('workbenches.material.url', route('material-arrivals.workbench', ['embedded' => 1]))
                ->where('panelUrl', route('dashboard')));
        foreach (['material-arrivals', 'template-accessories'] as $module) {
            $response = $this->get('/'.$module.'/workbench?embedded=1')->assertOk();
            $response->assertSee('production-workbench-state', false)->assertSee('.sidebar{display:none!important}', false);
            $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
            $this->assertLessThan(200000, strlen($response->getContent()));
            preg_match_all('~"(/workbench-assets/[^"\s]+)"~', $response->getContent(), $matches);
            $this->assertCount(2, $matches[1]);
            foreach ($matches[1] as $asset) {
                $this->assertFileExists(public_path($asset));
            }
        }
    }

    public function test_data_requests_do_not_build_unrelated_business_navigation(): void
    {
        $this->actingAs($this->technician());
        DB::enableQueryLog();
        foreach (['material-arrivals', 'template-accessories'] as $module) {
            $this->getJson('/'.$module.'/state')->assertOk()->assertJsonStructure(['dataset', 'preferences']);
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('business_objects', $query['query']);
            $this->assertStringNotContainsString('project_notifications', $query['query']);
        }
    }

    public function test_revoked_workbench_is_hidden_and_denied(): void
    {
        $user = $this->technician();
        $user->roles->first()->permissions()->sync(Permission::where('key', 'material-arrivals.view')->pluck('id'));
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nav.1.visible', true));
        $this->get('/template-accessories')->assertForbidden();
        $this->get('/production-workbench')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ProductionWorkbench')->has('workbenches.material')->missing('workbenches.template'));
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
