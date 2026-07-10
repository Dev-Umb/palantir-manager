<?php

namespace Tests\Unit;

use App\Ai\AiArtifactFactory;
use Tests\TestCase;

class AiArtifactFactoryTest extends TestCase
{
    public function test_query_result_becomes_a_table_and_numeric_chart(): void
    {
        $result = app(AiArtifactFactory::class)->fromToolResult('query_object_records', [
            'ok' => true,
            'object' => ['key' => 'project', 'label' => '项目主档'],
            'fields' => [
                ['key' => 'title', 'label' => '项目名称', 'type' => 'text'],
                ['key' => 'arrears', 'label' => '欠款', 'type' => 'number'],
            ],
            'rows' => [
                ['title' => '项目 A', 'arrears' => 800],
                ['title' => '项目 B', 'arrears' => 300],
            ],
            'sources' => [['object_key' => 'project', 'object_label' => '项目主档', 'record_count' => 2]],
            'data_quality' => [['type' => 'derived', 'field' => 'arrears', 'message' => '补算欠款']],
        ], ['sort' => ['field' => 'arrears', 'direction' => 'desc']]);

        $this->assertSame(['table', 'chart'], collect($result['artifacts'])->pluck('type')->all());
        $this->assertSame('项目名称', $result['artifacts'][0]['data']['columns'][0]['label']);
        $this->assertSame('bar', $result['artifacts'][1]['data']['type']);
        $this->assertSame('title', $result['artifacts'][1]['data']['x']);
        $this->assertSame('arrears', $result['artifacts'][1]['data']['y']);
        $this->assertSame('project', $result['sources'][0]['object_key']);
        $this->assertSame('derived', $result['data_quality'][0]['type']);
    }

    public function test_grouped_result_hides_internal_group_by_metadata(): void
    {
        $result = app(AiArtifactFactory::class)->fromToolResult('query_object_records', [
            'ok' => true,
            'object' => ['key' => 'project', 'label' => '项目主档'],
            'group_by' => 'stage',
            'fields' => [
                ['key' => 'stage', 'label' => '项目阶段', 'type' => 'select'],
            ],
            'rows' => [
                ['group_by' => 'stage', 'group' => '项目完成', '项目数' => 4],
                ['group_by' => 'stage', 'group' => '生产加工', '项目数' => 2],
            ],
        ]);

        $this->assertSame(['group', '项目数'], collect($result['artifacts'][0]['data']['columns'])->pluck('key')->all());
        $this->assertArrayNotHasKey('group_by', $result['artifacts'][0]['data']['rows'][0]);
        $this->assertSame('group', $result['artifacts'][1]['data']['x']);
        $this->assertSame('项目数', $result['artifacts'][1]['data']['y']);
    }
}
