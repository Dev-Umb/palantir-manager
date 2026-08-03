<?php

namespace Tests\Feature;

use App\Actions\SyncXycMetadata;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Permission;
use App\Models\ProjectNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RbacUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SyncXycMetadata::class)->handle();
    }

    public function test_administrator_can_delete_a_user_without_removing_historical_records(): void
    {
        $administrator = $this->userWithRole('admin', 'actor');
        $target = $this->userWithRole('business', 'target', rememberToken: 'remember-me');
        $object = BusinessObject::query()->where('key', 'customer')->firstOrFail();
        $record = ObjectRecord::query()->create([
            'business_object_id' => $object->id,
            'code' => 'CUS-DELETION-HISTORY',
            'title' => '保留的客户记录',
            'payload' => ['name' => '保留的客户记录'],
            'created_by' => $target->id,
        ]);
        $run = AiRun::query()->create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $target->id,
            'client_request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'rbac-user-deletion'),
            'status' => 'completed',
            'input' => '历史 AI 请求',
            'context_snapshot' => [],
            'artifacts' => [],
            'sources' => [],
            'provenance' => [],
        ]);
        $previousAudit = AuditLog::query()->create([
            'user_id' => $target->id,
            'action' => 'record.create',
            'subject_type' => ObjectRecord::class,
            'subject_id' => $record->id,
            'payload' => ['code' => $record->code],
        ]);
        $notification = ProjectNotification::query()->create([
            'project_id' => $record->id,
            'type' => ProjectNotification::TYPE_BID,
            'user_id' => $target->id,
            'status' => ProjectNotification::STATUS_ACTIVE,
            'triggered_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'session-payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->delete(route('rbac.users.destroy', $target))
            ->assertRedirect()
            ->assertSessionHas('status', '用户已删除。');

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseHas('object_records', ['id' => $record->id, 'created_by' => $target->id]);
        $this->assertDatabaseHas('ai_runs', ['id' => $run->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('project_notifications', ['id' => $notification->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $previousAudit->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => 'rbac.user.delete',
            'subject_type' => User::class,
            'subject_id' => (string) $target->id,
        ]);
        $this->assertNull(User::query()->find($target->id));
        $this->assertNull(User::withTrashed()->findOrFail($target->id)->remember_token);
    }

    public function test_deleted_user_cannot_log_in_again(): void
    {
        $administrator = $this->userWithRole('admin', 'actor');
        $target = $this->userWithRole('business', 'login-target');

        $this->actingAs($administrator)->delete(route('rbac.users.destroy', $target));
        $this->post('/logout');

        $this->post('/login', [
            'email' => $target->email,
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_cannot_delete_their_own_account(): void
    {
        $administrator = $this->userWithRole('admin', 'self');

        $this->actingAs($administrator)
            ->delete(route('rbac.users.destroy', $administrator))
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted('users', ['id' => $administrator->id]);
    }

    public function test_last_administrator_cannot_be_deleted_or_lose_the_admin_role(): void
    {
        $administrator = $this->userWithRole('admin', 'last');
        $manager = $this->userWithRbacPermission('manager');

        $this->actingAs($manager)
            ->delete(route('rbac.users.destroy', $administrator))
            ->assertSessionHasErrors('user');

        $this->actingAs($manager)
            ->put(route('rbac.users.roles', $administrator), ['roles' => []])
            ->assertSessionHasErrors('roles');

        $this->assertNotSoftDeleted('users', ['id' => $administrator->id]);
        $this->assertTrue($administrator->fresh()->roles->contains('name', 'admin'));
    }

    public function test_one_of_multiple_administrators_can_be_deleted(): void
    {
        $administrator = $this->userWithRole('admin', 'actor');
        $targetAdministrator = $this->userWithRole('admin', 'target');

        $this->actingAs($administrator)
            ->delete(route('rbac.users.destroy', $targetAdministrator))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $targetAdministrator->id]);
        $this->assertNotSoftDeleted('users', ['id' => $administrator->id]);
    }

    public function test_admin_role_can_be_removed_when_another_administrator_remains(): void
    {
        $administrator = $this->userWithRole('admin', 'actor');
        $targetAdministrator = $this->userWithRole('admin', 'target');
        $businessRole = Role::query()->where('name', 'business')->firstOrFail();

        $this->actingAs($administrator)
            ->put(route('rbac.users.roles', $targetAdministrator), ['roles' => [$businessRole->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['business'], $targetAdministrator->fresh()->roles->pluck('name')->all());
        $this->assertTrue($administrator->fresh()->roles->contains('name', 'admin'));
    }

    public function test_user_without_rbac_permission_cannot_delete_an_account(): void
    {
        $administrator = $this->userWithRole('admin', 'protected');
        $businessUser = $this->userWithRole('business', 'actor');

        $this->actingAs($businessUser)
            ->delete(route('rbac.users.destroy', $administrator))
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $administrator->id]);
    }

    public function test_rbac_page_exposes_delete_availability_and_block_reason(): void
    {
        $administrator = $this->userWithRole('admin', 'last');
        $manager = $this->userWithRbacPermission('manager');

        $this->actingAs($manager)
            ->get(route('rbac.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users', function ($users) use ($administrator, $manager): bool {
                    $users = collect($users)->keyBy('id');

                    return $users[$administrator->id]['can_delete'] === false
                        && $users[$administrator->id]['delete_block_reason'] === '不能删除最后一个管理员'
                        && $users[$manager->id]['can_delete'] === false
                        && $users[$manager->id]['delete_block_reason'] === '不能删除当前登录账号';
                }));
    }

    private function userWithRole(string $roleName, string $suffix, string $rememberToken = ''): User
    {
        $user = User::query()->create([
            'name' => "{$roleName}-{$suffix}",
            'email' => "{$roleName}-{$suffix}@example.com",
            'password' => Hash::make('password123'),
            'remember_token' => $rememberToken ?: null,
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function userWithRbacPermission(string $suffix): User
    {
        $role = Role::query()->create([
            'name' => "rbac-manager-{$suffix}",
            'label' => '权限管理员',
            'locked' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('key', 'rbac.manage')->firstOrFail());
        $user = User::query()->create([
            'name' => "manager-{$suffix}",
            'email' => "manager-{$suffix}@example.com",
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
