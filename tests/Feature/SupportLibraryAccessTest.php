<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\SupportLibraryFixtures;
use Tests\TestCase;

class SupportLibraryAccessTest extends TestCase
{
    use RefreshDatabase, SupportLibraryFixtures;

    /**
     * A basic feature test example.
     */
    public function test_all_entry_points_are_authenticated_and_permission_gated(): void
    {
        $this->initializeLibrary();
        foreach (['/support-library', '/support-library/library', '/support-library/exports/library?library_revision=1', '/support-library/exports/html?library_revision=1', '/support-library/template'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs($this->supportUser([]));
        foreach (['/support-library', '/support-library/library', '/support-library/template'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/support-library/query', $this->queryInput())->assertForbidden();
        $this->putJson('/support-library/library', $this->saveInput($this->baseline()))->assertForbidden();
        $this->postJson('/support-library/imports/preview')->assertForbidden();
        $this->postJson('/support-library/imports/commit')->assertForbidden();
        $this->postJson('/support-library/exports/result', $this->queryInput())->assertForbidden();
        $this->actingAs($this->supportUser(['view']));
        $this->getJson('/support-library/library')->assertOk()->assertJsonCount(52, 'library.parts');
        $this->postJson('/support-library/query', $this->queryInput())->assertOk()->assertJsonPath('result.total_braces', 80);
        $this->putJson('/support-library/library', $this->saveInput($this->baseline()))->assertForbidden();
        $this->postJson('/support-library/imports/preview')->assertForbidden();
        $this->postJson('/support-library/imports/commit')->assertForbidden();
        $this->get('/support-library/exports/library?library_revision=1')->assertForbidden();
        $this->get('/support-library/exports/html?library_revision=1')->assertForbidden();
        $this->get('/support-library/template')->assertForbidden();
    }

    public function test_engineering_grants_are_additive_and_navigation_is_scoped(): void
    {
        $this->withoutVite();
        $engineering = Role::create(['name' => 'engineering', 'label' => '技术']);
        $basic = Role::create(['name' => 'basic', 'label' => '普通']);
        $existing = Permission::create(['key' => 'dashboard.view', 'module' => 'dashboard', 'action' => 'view', 'label' => '大盘']);
        $engineering->permissions()->attach($existing);
        $basic->permissions()->attach($existing);
        $this->artisan('support-library:install-permissions')->assertSuccessful();
        $this->artisan('support-library:install-permissions')->assertSuccessful();
        $this->assertSame(4, $engineering->permissions()->count());
        $this->assertSame(1, $basic->permissions()->count());
        $user = User::factory()->create();
        $user->roles()->attach($engineering);
        $this->initializeLibrary();
        $this->actingAs($user)->get('/support-library')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SupportLibrary/Index')->where('capabilities.manage', true)->where('capabilities.export', true)
            ->where('nav', fn ($nav) => collect($nav)->contains(fn ($item) => $item['key'] === 'support-library' && $item['visible'])));
    }

    public function test_revoked_access_and_restrictive_mixed_roles_do_not_leak_library(): void
    {
        $this->initializeLibrary();
        $user = $this->supportUser();
        $this->actingAs($user)->getJson('/support-library/library')->assertOk();
        $user->roles()->first()->permissions()->detach();
        $this->actingAs($user->fresh())->getJson('/support-library/library')->assertForbidden();
        $operator = Role::create(['name' => 'timebook_operator', 'label' => '工日簿']);
        $user = $this->supportUser();
        $user->roles()->attach($operator);
        $this->actingAs($user->fresh())->getJson('/support-library/library')->assertForbidden();
    }

    public function test_mutation_and_query_endpoints_preserve_revision_and_disallow_client_totals(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser());
        $db = $this->baseline();
        $db['parts'][0]['unit_weight_kg'] = 500;
        $this->putJson('/support-library/library', $this->saveInput($db))->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/support-library/query', $this->queryInput())->assertConflict();
        $this->postJson('/support-library/query', $this->queryInput(revision: 2))->assertOk()->assertJsonPath('result.column_weight_kg', 8000);
        $this->postJson('/support-library/query', [...$this->queryInput(revision: 2), 'total' => 0])->assertOk()->assertJsonPath('result.total_columns', 16);
        $this->putJson('/support-library/library', $this->saveInput($db))->assertConflict();
    }
}
