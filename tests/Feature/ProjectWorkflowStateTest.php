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

class ProjectWorkflowStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_workflow_metadata_is_retained_but_cannot_advance_projects_through_direct_crud(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $project = ObjectRecord::whereRelation('businessObject', 'key', 'project')->firstOrFail();
        $before = $project->payload;
        $drawingObject = BusinessObject::where('key', 'drawing')->firstOrFail();
        $drawing = ObjectRecord::create([
            'business_object_id' => $drawingObject->id,
            'code' => 'TZ-HISTORY-001',
            'title' => '历史图纸',
            'payload' => ['name' => '历史图纸', 'project_id' => $project->id, 'design_status' => '草稿'],
        ]);
        $admin = User::create(['name' => '管理员', 'email' => 'workflow@example.com', 'password' => Hash::make('password123')]);
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->put("/records/{$drawing->id}", [
            'payload' => [...$drawing->payload, 'design_status' => '已下放'],
        ])->assertNotFound();

        $this->assertSame('草稿', $drawing->fresh()->payload['design_status']);
        $this->assertSame($before, $project->fresh()->payload);
        $this->assertContains('已下放', collect($drawingObject->fields)->firstWhere('key', 'design_status')['options']);
    }
}
