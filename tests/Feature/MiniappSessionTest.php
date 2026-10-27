<?php

namespace Tests\Feature;

use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniappSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_bootstrap_and_invalid_credentials_return_private_json(): void
    {
        $this->get('/miniapp/session')->assertOk()->assertJsonPath('user', null)
            ->assertJsonStructure(['csrfToken', 'version'])->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/miniapp/session', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->post('/miniapp/session', ['email' => 'missing@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', '账号或密码不正确。');
        $this->assertGuest();
    }

    public function test_login_rotates_session_and_logout_clears_identity(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->get('/miniapp/session');
        $before = session()->getId();
        $response = $this->postJson('/miniapp/session', ['email' => $user->email, 'password' => 'password123']);
        $response->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.permissions', [])
            ->assertJsonMissingPath('user.password');
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($user);
        $this->getJson('/objects/project')->assertForbidden();
        $token = $response->json('csrfToken');
        $this->deleteJson('/miniapp/session')->assertOk()->assertJsonPath('user', null);
        $this->assertNotSame($token, session()->token());
        $this->assertGuest();
    }

    public function test_restricted_user_can_inspect_and_end_session_but_cannot_open_business_pages(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'timebook_operator', 'label' => '工日簿']);
        $user->roles()->attach($role);
        $this->actingAs($user)->getJson('/miniapp/session')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->getJson('/objects/project')->assertForbidden();
        $this->deleteJson('/miniapp/session')->assertOk()->assertJsonPath('user', null);
    }

    public function test_login_is_throttled_and_browser_login_keeps_redirect_contract(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/miniapp/session', ['email' => 'rate@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/miniapp/session', ['email' => 'rate@example.com', 'password' => 'wrong'])->assertTooManyRequests();
        $user = User::factory()->create(['password' => 'password123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect('/');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_admin_session_reads_existing_business_contracts_without_mutations(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create(['password' => 'password123']);
        $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->postJson('/miniapp/session', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.roles.0.name', 'admin');
        $this->assertContains('object.project.view', $this->getJson('/miniapp/session')->json('user.permissions'));
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->getJson('/miniapp/session')->json('version')];
        $projects = $this->getJson('/objects/project?per_page=1', $headers)->assertOk();
        $projects->assertJsonStructure(['props' => ['records' => ['data', 'total', 'last_page'], 'currentObject' => ['fields'], 'can', 'relationOptions']]);
        $id = $projects->json('props.records.data.0.id');
        $this->getJson('/objects/project?record='.$id.'&per_page=1', $headers)->assertOk()
            ->assertJsonPath('props.selectedRecord.id', $id)
            ->assertJsonStructure(['props' => ['selectedRecord' => ['payload', 'display', 'contracts']]]);
        $this->getJson(route('dashboard', absolute: false), $headers)->assertOk()
            ->assertJsonStructure(['props' => ['cockpit' => ['panels' => ['project_amounts' => ['company']]], 'recentProjects']]);
        $this->getJson('/objects/customer', $headers)->assertOk()
            ->assertJsonStructure(['props' => ['records' => ['data' => [['contacts', 'cooperation_projects']]]]]);
        $this->getJson('/notifications', $headers)->assertOk()->assertJsonStructure(['props' => ['notifications', 'tenderNotifications']]);
    }

    public function test_notification_json_acknowledgement_preserves_browser_redirect_and_owner_scope(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $user = User::factory()->create();
        $notification = ProjectNotification::create([
            'project_id' => ObjectRecord::whereHas('businessObject', fn ($query) => $query->where('key', 'project'))->firstOrFail()->id,
            'user_id' => $user->id,
            'type' => ProjectNotification::TYPE_PAYMENT,
            'status' => ProjectNotification::STATUS_ACTIVE,
            'triggered_at' => now(),
            'occurrences' => 1,
        ]);
        $path = '/notifications/'.$notification->id.'/read';
        $this->actingAs(User::factory()->create())->patchJson($path)->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($user)->withHeader('Referer', 'https://servicewechat.com/example/page.html')
            ->patchJson($path)->assertOk()->assertJsonPath('status', 'read')->assertHeaderMissing('Location');
        $this->assertNotNull($notification->fresh()->read_at);
        $this->flushHeaders();
        $this->from('/notifications')->patch($path)->assertRedirect('/notifications');
    }

    public function test_session_writes_require_csrf_including_guest_requests(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/miniapp/session', [])->assertStatus(419)->assertHeader('Content-Type', 'application/json');
        $this->delete('/miniapp/session')->assertStatus(419);
        $token = $this->get('/miniapp/session')->json('csrfToken');
        $this->withHeader('X-CSRF-TOKEN', $token)->post('/miniapp/session', [])->assertUnprocessable();
    }
}
