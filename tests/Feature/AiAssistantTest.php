<?php

namespace Tests\Feature;

use App\Ai\Tools\QueryObjectRecordsTool;
use App\Ai\XycDataAgent;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_purchase_request_material_lookup_ignores_requisition_unit(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $materialObject = BusinessObject::where('key', 'material')->firstOrFail();
        $material = ObjectRecord::create([
            'business_object_id' => $materialObject->id,
            'code' => 'MAT-CHANNEL-200',
            'title' => '槽钢',
            'payload' => [
                'name' => '槽钢',
                'spec' => '200',
            ],
        ]);

        $result = $this->queryMaterials([
            'object' => 'material',
            'select' => ['id', 'name', 'spec', 'unit'],
            'filters' => [[
                'field' => 'name',
                'operator' => 'contains',
                'value' => '槽钢',
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $row = collect($result['rows'])->firstWhere('id', $material->id);
        $this->assertNotNull($row);
        $this->assertSame('槽钢', $row['name']);
        $this->assertSame('200', $row['spec']);
        $this->assertArrayNotHasKey('unit', $row);
        $this->assertSame(['unit'], $result['warnings'][0]['fields']);
        $this->assertStringContainsString('requisition.unit', $result['warnings'][0]['message']);
    }

    public function test_material_lookup_still_rejects_unrelated_unknown_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $result = $this->queryMaterials([
            'object' => 'material',
            'select' => ['id', 'supplier_price'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_field', $result['error']);
        $this->assertStringContainsString('supplier_price', $result['message']);
    }

    public function test_agent_keeps_purchase_unit_out_of_material_queries(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        $agent = XycDataAgent::make(user: User::where('email', 'procurement@xyc.test')->firstOrFail());

        $this->assertStringContainsString(
            '物料主档 material 没有 unit 字段',
            (string) $agent->instructions(),
        );
        $this->assertStringContainsString(
            '数量单位属于 requisition.unit',
            (string) $agent->instructions(),
        );
    }

    private function queryMaterials(array $arguments): array
    {
        $user = User::where('email', 'procurement@xyc.test')->firstOrFail();
        $response = (new QueryObjectRecordsTool($user))->handle(new Request($arguments));

        return json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
    }
}
