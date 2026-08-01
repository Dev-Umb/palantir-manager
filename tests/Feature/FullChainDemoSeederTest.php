<?php

namespace Tests\Feature;

use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\User;
use Database\Seeders\FullChainDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FullChainDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_an_idempotent_full_chain_scenario_matrix_without_hidden_workflow_records(): void
    {
        Storage::fake('local');
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
        $this->assertSame(2, ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $project->id)->count());
        foreach (['drawing', 'teardown', 'work_order', 'team_log', 'requisition', 'purchase', 'shipment', 'receivable', 'invoice'] as $hiddenKey) {
            $this->assertFalse(ObjectRecord::whereRelation('businessObject', 'key', $hiddenKey)
                ->where('payload->project_id', $project->id)->exists());
        }
        $this->assertLinkedRecordExists('customer_contact', 'customer_id', $customerId);
        $this->assertSame(11, ObjectRecord::whereRelation('businessObject', 'key', 'project')
            ->whereLike('payload->name', FullChainDemoSeeder::PROJECT_PREFIX.'%')
            ->count());
        $this->assertSame(9, ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->whereLike('payload->_demo_key', 'full-chain-contract-%')
            ->count());
        $this->assertSame(3, ObjectRecord::whereRelation('businessObject', 'key', 'customer')
            ->whereLike('payload->_demo_key', 'full-chain-customer-%')
            ->count());
        $this->assertSame(6, ObjectRecord::whereRelation('businessObject', 'key', 'customer_contact')
            ->whereLike('payload->_demo_key', 'full-chain-contact-%')
            ->count());

        foreach (range(1, 3) as $index) {
            Storage::disk('local')->assertExists(sprintf('attachments/full-chain-demo-processing-letter-%02d.pdf', $index));
            Storage::disk('local')->assertExists(sprintf('attachments/full-chain-demo-contract-%02d.pdf', $index));
        }
        foreach (range(1, 10) as $index) {
            Storage::disk('local')->assertExists(sprintf('attachments/full-chain-demo-statement-%02d.pdf', $index));
        }

        $signedContract = ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->_demo_key', 'full-chain-contract-partial-main')
            ->firstOrFail();
        $this->assertCount(3, $signedContract->payload['contract_attachments']);
        $this->assertCount(5, $signedContract->payload['statement_attachments']);
        $this->actingAs(User::where('email', 'demo-notification-admin@xyc.test')->firstOrFail())
            ->get("/attachments/{$signedContract->id}/contract_attachments/0")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $processingContract = ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->_demo_key', 'full-chain-contract-partial-supplement')
            ->firstOrFail();
        $this->assertCount(3, $processingContract->payload['processing_letter_attachments']);
        $this->assertCount(5, $processingContract->payload['statement_attachments']);

        $manualAmount = $this->scenarioProject('manual_amount');
        $this->assertSame(1000000.0, (float) $manualAmount->payload['contract_amount']);
        $this->assertSame('manual', $manualAmount->payload['contract_amount_source']);
        $this->assertSame(1200000.0, ObjectRecord::whereRelation('businessObject', 'key', 'contract')
            ->where('payload->project_id', $manualAmount->id)
            ->get()
            ->sum(fn (ObjectRecord $contract): float => (float) $contract->payload['amount']));

        $this->assertTrue(ProjectNotification::query()
            ->where('project_id', $this->scenarioProject('bid_due')->id)
            ->where('type', ProjectNotification::TYPE_BID)
            ->active()
            ->exists());
        $this->assertFalse(ProjectNotification::query()
            ->where('project_id', $this->scenarioProject('bid_pending')->id)
            ->active()
            ->exists());
        $this->assertTrue(ProjectNotification::query()
            ->where('project_id', $this->scenarioProject('won_due')->id)
            ->where('type', ProjectNotification::TYPE_PROCESSING_LETTER)
            ->active()
            ->exists());
        $this->assertSame(
            [ProjectNotification::TYPE_SIGNATURE, ProjectNotification::TYPE_PAYMENT],
            ProjectNotification::query()
                ->where('project_id', $this->scenarioProject('letter_due')->id)
                ->active()
                ->pluck('type')
                ->unique()
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertTrue(ProjectNotification::query()
            ->where('project_id', $this->scenarioProject('completed')->id)
            ->where('status', ProjectNotification::STATUS_RESOLVED)
            ->exists());

        $inventoryKeys = collect(config('xyc.objects'))
            ->filter(fn (array $object) => $object['archived'] ?? false)
            ->pluck('key');
        $this->assertSame(0, ObjectRecord::whereRelation(
            'businessObject',
            fn ($query) => $query->whereIn('key', $inventoryKeys),
        )->count());
    }

    public function test_demo_is_visible_only_in_project_and_contract_workspaces(): void
    {
        $this->seed(FullChainDemoSeeder::class);
        $project = $this->demoProject();

        $this->assertCanOpenRecord('business@xyc.test', 'project', $project);
        $this->assertCanOpenRecord('finance@xyc.test', 'project', $project);
        $this->assertCanOpenRecord('business@xyc.test', 'contract', $this->linkedRecord('contract', $project));

        $engineering = User::where('email', 'engineering@xyc.test')->firstOrFail();
        $this->actingAs($engineering)
            ->get("/objects/project?record={$project->id}&mode=detail")
            ->assertForbidden();
        $this->actingAs(User::where('email', 'procurement@xyc.test')->firstOrFail())
            ->get('/objects/purchase')
            ->assertForbidden();
    }

    private function demoProject(): ObjectRecord
    {
        return ObjectRecord::whereRelation('businessObject', 'key', 'project')
            ->where('payload->name', FullChainDemoSeeder::PROJECT_NAME)
            ->firstOrFail();
    }

    private function scenarioProject(string $key): ObjectRecord
    {
        return ObjectRecord::whereRelation('businessObject', 'key', 'project')
            ->where('payload->_demo_key', "full-chain-project-{$key}")
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
