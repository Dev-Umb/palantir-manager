<?php

namespace Tests\Feature;

use App\Ai\XycDataAccess;
use App\Ai\XycDataAgent;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\XycPrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Tests\TestCase;

class AiDataAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default' => 'ark',
            'ai.conversations.generate_title' => false,
            'ai.providers.ark.key' => 'test-key',
        ]);
    }

    public function test_ai_page_is_available_to_authenticated_users(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $this->actingAs($this->userWithRole('production'));

        $this->get('/ai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Index')
                ->has('conversations', 0));
    }

    public function test_visible_objects_follow_current_user_permissions(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $objects = collect(app(XycDataAccess::class)->visibleObjects($this->userWithRole('production')));

        $this->assertContains('project', $objects->pluck('key'));
        $this->assertContains('work_order', $objects->pluck('key'));
        $this->assertNotContains('receivable', $objects->pluck('key'));
    }

    public function test_object_query_rejects_objects_outside_user_permissions(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $result = app(XycDataAccess::class)->queryRecords($this->userWithRole('finance'), [
            'object' => 'material',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']);
    }

    public function test_project_query_reuses_project_visibility(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', 'project')->firstOrFail()->id,
            'code' => 'PRJ-AI-EARLY',
            'title' => 'AI 不应看到的早期项目',
            'payload' => [
                'name' => 'AI 不应看到的早期项目',
                'project_no' => 'AI-EARLY',
                'stage' => '合同录入',
            ],
        ]);

        $result = app(XycDataAccess::class)->queryRecords($this->userWithRole('production'), [
            'object' => 'project',
            'limit' => 20,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['南通厂房钢结构一期'], collect($result['rows'])->pluck('title')->all());
    }

    public function test_record_query_returns_only_selected_fields_and_derives_missing_arrears(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', 'project')->firstOrFail()->id,
            'code' => 'PRJ-AI-DEBT',
            'title' => '欠款补算项目',
            'payload' => [
                'name' => '欠款补算项目',
                'stage' => '项目完成',
                'contract_amount' => 1000,
                'paid_amount' => 250,
                'arrears' => null,
            ],
        ]);

        $result = app(XycDataAccess::class)->queryRecords($this->userWithRole('admin'), [
            'object' => 'project',
            'select' => ['title', 'contract_amount', 'paid_amount', 'arrears'],
            'filters' => [['field' => 'title', 'operator' => 'eq', 'value' => '欠款补算项目']],
            'limit' => 5,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['title', 'contract_amount', 'paid_amount', 'arrears'],
            array_keys($result['rows'][0]),
        );
        $this->assertSame(750.0, $result['rows'][0]['arrears']);
        $this->assertSame('derived', $result['data_quality'][0]['type']);
        $this->assertArrayNotHasKey('payload', $result['rows'][0]);
        $this->assertArrayNotHasKey('display', $result['rows'][0]);
    }

    public function test_record_query_rejects_unknown_fields(): void
    {
        $this->seed(XycPrototypeSeeder::class);

        $result = app(XycDataAccess::class)->queryRecords($this->userWithRole('admin'), [
            'object' => 'project',
            'select' => ['title', 'secret_column'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_field', $result['error']);
    }

    public function test_aggregate_keeps_missing_numeric_values_null_and_reports_data_quality(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        ObjectRecord::create([
            'business_object_id' => BusinessObject::where('key', 'project')->firstOrFail()->id,
            'code' => 'PRJ-AI-MISSING-AMOUNT',
            'title' => '金额缺失项目',
            'payload' => [
                'name' => '金额缺失项目',
                'stage' => '异常',
                'contract_amount' => null,
                'paid_amount' => null,
                'arrears' => null,
            ],
        ]);

        $result = app(XycDataAccess::class)->queryRecords($this->userWithRole('admin'), [
            'object' => 'project',
            'filters' => [['field' => 'title', 'operator' => 'eq', 'value' => '金额缺失项目']],
            'group_by' => 'stage',
            'metrics' => [['op' => 'sum', 'field' => 'arrears', 'label' => '欠款合计']],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['rows'][0]['欠款合计']);
        $this->assertSame('missing', $result['data_quality'][0]['type']);
        $this->assertSame('arrears', $result['data_quality'][0]['field']);
    }

    public function test_ai_message_uses_agent_and_stores_conversation(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Ai::fakeAgent(XycDataAgent::class, [[
            'answer' => '你可以查看项目和生产任务。',
            'table' => null,
            'chart' => null,
            'sources' => [['object_key' => 'project', 'record_count' => 1]],
        ]]);

        $user = $this->userWithRole('production');
        $this->actingAs($user);

        $this->postJson('/ai/messages', [
            'message' => '列出我能看的数据表',
        ])
            ->assertOk()
            ->assertJsonPath('answer', '你可以查看项目和生产任务。')
            ->assertJsonPath('sources.0.object_key', 'project');

        $this->assertDatabaseHas('agent_conversations', ['user_id' => $user->id]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'user_id' => $user->id,
            'role' => 'user',
            'content' => '列出我能看的数据表',
        ]);
    }

    public function test_ai_message_falls_back_to_text_when_structured_payload_is_empty(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Ai::fakeAgent(XycDataAgent::class, [
            new StructuredTextResponse(
                [],
                '您当前可查看项目和生产任务。',
                new Usage,
                new Meta('ark', 'ark-code-latest'),
            ),
        ]);

        $this->actingAs($this->userWithRole('production'));

        $this->postJson('/ai/messages', [
            'message' => '列出我能看的数据表',
        ])
            ->assertOk()
            ->assertJsonPath('answer', '您当前可查看项目和生产任务。');
    }

    public function test_ai_message_backfills_table_and_chart_from_query_tool_results(): void
    {
        $this->seed(XycPrototypeSeeder::class);
        Ai::fakeAgent(XycDataAgent::class, [
            new ToolCall('call_1', 'query_object_records', [
                'object' => 'project',
                'group_by' => 'stage',
                'metrics' => [['op' => 'count', 'label' => '数量']],
                'sort' => ['field' => '数量', 'direction' => 'desc'],
            ]),
            new StructuredTextResponse(
                [],
                '按项目阶段统计如下。',
                new Usage,
                new Meta('ark', 'ark-code-latest'),
            ),
        ]);

        $this->actingAs($this->userWithRole('production'));

        $this->postJson('/ai/messages', [
            'message' => '按项目阶段统计项目数量，返回表格和柱状图',
        ])
            ->assertOk()
            ->assertJsonPath('answer', '按项目阶段统计如下。')
            ->assertJsonPath('table.columns.0.key', 'group')
            ->assertJsonPath('chart.type', 'bar')
            ->assertJsonPath('chart.x', 'group')
            ->assertJsonPath('chart.y', '数量');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::create([
            'name' => Role::where('name', $roleName)->firstOrFail()->label,
            'email' => "{$roleName}-ai@example.com",
            'password' => Hash::make('password123'),
        ]);

        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
