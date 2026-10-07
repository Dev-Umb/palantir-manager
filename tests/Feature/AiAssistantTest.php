<?php

namespace Tests\Feature;

use App\Ai\Tools\QueryObjectRecordsTool;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retired_purchase_request_material_lookup_ignores_requisition_unit(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('material', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_material_lookup_still_rejects_unrelated_unknown_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('material', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    public function test_retired_agent_keeps_purchase_unit_out_of_material_queries(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $this->assertNotContains('material', array_column(config('xyc.objects'), 'key'));
        $this->get('/purchase-request')->assertNotFound();
        $this->assertFalse(Route::has('team-logs.public.create'));
    }

    private function queryMaterials(array $arguments): array
    {
        $user = User::where('email', 'procurement@xyc.test')->firstOrFail();
        $response = (new QueryObjectRecordsTool($user))->handle(new Request($arguments));

        return json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
    }
}
