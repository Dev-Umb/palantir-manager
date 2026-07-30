<?php

namespace Tests\Feature;

use App\Models\ObjectRecord;
use App\Models\User;
use Database\Seeders\FullChainDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FullChainDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_idempotent_non_inventory_full_chain(): void
    {
        $this->seed(FullChainDemoSeeder::class);
        $this->seed(FullChainDemoSeeder::class);

        $project = $this->demoProject();
        $customerId = $project->payload['customer_id'];

        $this->assertSame(1, ObjectRecord::whereRelation('businessObject', 'key', 'project')
            ->where('payload->name', FullChainDemoSeeder::PROJECT_NAME)
            ->count());
        $this->assertNotEmpty($project->payload['customer_contact_ids']);
        $this->assertSame(5800000.0, (float) $project->payload['contract_amount']);
        $this->assertSame(2900000.0, (float) $project->payload['paid_amount']);
        $this->assertSame(1300000.0, (float) $project->payload['unpaid_amount']);
        $this->assertLinkedRecordExists('contract', 'project_id', $project->id);
        $this->assertLinkedRecordExists('drawing', 'project_id', $project->id);
        $this->assertLinkedRecordExists('teardown', 'project_id', $project->id);
        $this->assertLinkedRecordExists('work_order', 'project_id', $project->id);
        $this->assertLinkedRecordExists('team_log', 'project_id', $project->id);
        $this->assertLinkedRecordExists('requisition', 'project_id', $project->id);
        $this->assertLinkedRecordExists('purchase', 'project_id', $project->id);
        $this->assertLinkedRecordExists('shipment', 'project_id', $project->id);
        $this->assertLinkedRecordExists('receivable', 'project_id', $project->id);
        $this->assertLinkedRecordExists('invoice', 'project_id', $project->id);
        $this->assertLinkedRecordExists('customer_contact', 'customer_id', $customerId);

        $inventoryKeys = collect(config('xyc.objects'))
            ->filter(fn (array $object) => $object['archived'] ?? false)
            ->pluck('key');
        $this->assertSame(0, ObjectRecord::whereRelation(
            'businessObject',
            fn ($query) => $query->whereIn('key', $inventoryKeys),
        )->count());
    }

    public function test_full_chain_is_visible_only_through_each_roles_existing_permissions(): void
    {
        $this->seed(FullChainDemoSeeder::class);
        $project = $this->demoProject();

        $this->assertCanOpenRecord('business@xyc.test', 'project', $project);
        $this->assertCanOpenRecord('procurement@xyc.test', 'project', $project);
        $this->assertCanOpenRecord('procurement@xyc.test', 'purchase', $this->linkedRecord('purchase', $project));
        $this->assertCanOpenRecord('production_manager@xyc.test', 'work_order', $this->linkedRecord('work_order', $project));
        $this->assertCanOpenRecord('production@xyc.test', 'team_log', $this->linkedRecord('team_log', $project));
        $this->assertCanOpenRecord('finance@xyc.test', 'receivable', $this->linkedRecord('receivable', $project));
        $this->assertCanOpenRecord('finance@xyc.test', 'invoice', $this->linkedRecord('invoice', $project));
        $this->assertCanOpenRecord('engineering@xyc.test', 'drawing', $this->linkedRecord('drawing', $project));

        $engineering = User::where('email', 'engineering@xyc.test')->firstOrFail();
        $this->actingAs($engineering)
            ->get("/objects/project?record={$project->id}&mode=detail")
            ->assertForbidden();
    }

    private function demoProject(): ObjectRecord
    {
        return ObjectRecord::whereRelation('businessObject', 'key', 'project')
            ->where('payload->name', FullChainDemoSeeder::PROJECT_NAME)
            ->firstOrFail();
    }

    private function assertLinkedRecordExists(string $objectKey, string $field, string $id): void
    {
        $this->assertTrue(ObjectRecord::whereRelation('businessObject', 'key', $objectKey)
            ->where("payload->{$field}", $id)
            ->exists(), "{$objectKey} is not linked by {$field}.");
    }

    private function linkedRecord(string $objectKey, ObjectRecord $project): ObjectRecord
    {
        return ObjectRecord::whereRelation('businessObject', 'key', $objectKey)
            ->where('payload->project_id', $project->id)
            ->firstOrFail();
    }

    private function assertCanOpenRecord(string $email, string $objectKey, ObjectRecord $record): void
    {
        $user = User::where('email', $email)->firstOrFail();

        $this->actingAs($user)
            ->get("/objects/{$objectKey}?record={$record->id}&mode=detail")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ontology/Index')
                ->where('currentObject.key', $objectKey)
                ->where('selectedRecord.id', $record->id));
    }
}
