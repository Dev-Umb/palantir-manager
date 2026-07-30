<?php

namespace Tests\Feature;

use App\Actions\AdvanceProjectWorkflow;
use App\Actions\CreateObjectRecord;
use App\Models\ObjectRecord;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectWorkflowStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_released_drawing_and_completed_work_order_use_configured_statuses(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $drawing = ObjectRecord::query()
            ->whereRelation('businessObject', 'key', 'drawing')
            ->firstOrFail();
        $project = ObjectRecord::query()
            ->whereKey($drawing->payload['project_id'])
            ->firstOrFail();
        $user = User::where('email', 'production_manager@xyc.test')->firstOrFail();
        $workflow = app(AdvanceProjectWorkflow::class);
        $writer = app(CreateObjectRecord::class);

        DB::transaction(function () use ($drawing, $user, $workflow, $writer): void {
            $workflow->handle($drawing, ['design_status' => '草稿'], $user, $writer);
        });

        $workOrder = ObjectRecord::query()
            ->where('workflow_key', "drawing:{$drawing->id}:work-order")
            ->firstOrFail();

        $this->assertSame('未开始', $workOrder->payload['status']);
        $this->assertContains(
            $workOrder->payload['status'],
            collect(config('xyc.objects'))
                ->firstWhere('key', 'work_order')['fields'][3]['options'],
        );

        $shipmentWorkflowKey = "work-order:{$workOrder->id}:shipment";
        $oldPayload = $workOrder->payload;
        $workOrder->update(['payload' => [...$oldPayload, 'status' => '生产中']]);

        DB::transaction(function () use ($workOrder, $oldPayload, $user, $workflow, $writer): void {
            $workflow->handle($workOrder->refresh(), $oldPayload, $user, $writer);
        });

        $this->assertFalse(ObjectRecord::where('workflow_key', $shipmentWorkflowKey)->exists());

        $oldPayload = $workOrder->refresh()->payload;
        $workOrder->update(['payload' => [...$oldPayload, 'status' => '已完成']]);

        DB::transaction(function () use ($workOrder, $oldPayload, $user, $workflow, $writer): void {
            $workflow->handle($workOrder->refresh(), $oldPayload, $user, $writer);
        });

        $shipment = ObjectRecord::where('workflow_key', $shipmentWorkflowKey)->firstOrFail();

        $this->assertSame($project->id, $shipment->payload['project_id']);
        $this->assertSame('成品发货', $project->refresh()->payload['stage']);
    }
}
