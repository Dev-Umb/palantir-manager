<?php

namespace Tests\Feature;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OntologyRecordCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_can_be_created_updated_exported_and_deleted_without_changing_payload_contract(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->actingAs($this->userWithRole('admin'));
        $customer = BusinessObject::where('key', 'customer')->firstOrFail();

        $this->post("/objects/{$customer->id}", ['payload' => ['name' => '回归客户', 'level' => 'A']])
            ->assertRedirect();
        $record = $customer->records()->where('payload->name', '回归客户')->firstOrFail();

        $this->put("/records/{$record->id}", ['payload' => ['name' => '回归客户更新', 'level' => 'B']])
            ->assertRedirect();
        $this->assertSame('回归客户更新', $record->fresh()->payload['name']);

        $this->get('/objects/customer/export.csv')->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->delete("/records/{$record->id}")->assertRedirect();
        $this->assertModelMissing($record);
    }

    public function test_validation_and_attachment_authorization_fail_closed(): void
    {
        Storage::fake('local');
        $this->seed(XycPrototypeSeeder::class);
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        $customer = BusinessObject::where('key', 'customer')->firstOrFail();
        $this->post("/objects/{$customer->id}", ['payload' => []])->assertSessionHasErrors('payload.name');

        $drawing = BusinessObject::where('key', 'drawing')->firstOrFail();
        Storage::disk('local')->put('attachments/contract.pdf', '%PDF-1.4');
        $record = ObjectRecord::create([
            'business_object_id' => $drawing->id,
            'code' => 'TZ-AUTH',
            'title' => '附件授权',
            'payload' => ['name' => '附件授权', 'attachment' => 'attachments/contract.pdf'],
            'created_by' => $admin->id,
        ]);
        $this->get("/attachments/{$record->id}/attachment")->assertOk();

        $this->actingAs($this->userWithRole('basic'));
        $this->get("/attachments/{$record->id}/attachment")->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => "{$role}-crud@example.com", 'password' => Hash::make('password123')]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
