<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RequisitionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_and_public_submissions_preserve_existing_approval_and_rejection_flow(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $material = ObjectRecord::whereRelation('businessObject', 'key', 'material')->firstOrFail();
        $requisitions = BusinessObject::where('key', 'requisition')->firstOrFail();
        $purchases = BusinessObject::where('key', 'purchase')->firstOrFail();

        $this->actingAs($this->userWithRole('production'))->post('/requests', [
            'requester' => '生产', 'material_id' => $material->id, 'qty' => 2,
            'unit' => '吨', 'urgency' => '普通', 'reason' => '登录提交',
        ])->assertRedirect('/');
        $approved = $requisitions->records()->where('payload->reason', '登录提交')->firstOrFail();
        $purchaseCount = $purchases->records()->count();

        $this->actingAs($this->userWithRole('procurement'))
            ->post("/requests/{$approved->id}/approve")->assertRedirect();
        $this->assertSame('已转采购', $approved->fresh()->payload['status']);
        $this->assertSame($purchaseCount + 1, $purchases->records()->count());

        $this->post('/purchase-request', [
            'requester' => '现场', 'material_id' => $material->id, 'qty' => 1,
            'unit' => '张', 'urgency' => '紧急', 'reason' => '公开提交',
        ])->assertRedirect('/purchase-request');
        $rejected = $requisitions->records()->where('payload->reason', '公开提交')->firstOrFail();
        $this->actingAs($this->userWithRole('procurement'))
            ->post("/requests/{$rejected->id}/reject")->assertRedirect();
        $this->assertSame('已驳回', $rejected->fresh()->payload['status']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $rejected->id, 'action' => 'requisition.reject']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}-flow@example.com"],
            ['name' => $role, 'password' => Hash::make('password123')],
        );
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);

        return $user;
    }
}
