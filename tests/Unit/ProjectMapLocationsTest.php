<?php

namespace Tests\Unit;

use App\Models\ObjectRecord;
use App\Support\ProjectMapLocations;
use Tests\TestCase;

class ProjectMapLocationsTest extends TestCase
{
    public function test_wenshui_display_rename_preserves_factory_map_results(): void
    {
        $payload = ['name' => '武汉项目', 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['a.pdf'], 'factory_subject' => '山西鑫源昌'];
        $record = $this->record('p', 'P1', $payload);
        $resolver = app(ProjectMapLocations::class);
        $before = $resolver->summarize(collect([$record]));
        $record->payload = [...$payload, 'factory_subject' => '文水鑫源昌'];
        $this->assertSame($before, $resolver->summarize(collect([$record])));
        $this->assertSame('wenshui', $before['points'][0]['projects'][0]['factory']);
    }

    public function test_city_names_use_province_anchors_and_cross_region_projects_keep_both_regions(): void
    {
        $locations = app(ProjectMapLocations::class)->locate('武汉至成都铁路工程');
        $this->assertSame(['武汉市', '成都市'], array_column(array_values($locations), 'name'));
        $this->assertSame('城市级概略', array_values($locations)[0]['precision']);
    }

    public function test_unknown_places_and_customer_company_names_are_not_project_sites(): void
    {
        $resolver = app(ProjectMapLocations::class);
        $this->assertSame([], $resolver->locate('新建铁路标段'));
        $this->assertSame([], $resolver->locate('上海建工集团桥梁项目'));
        $this->assertSame([], $resolver->locate('江苏晋武重工桥梁项目'));
    }

    public function test_explicit_overseas_country_does_not_get_a_domestic_point(): void
    {
        $locations = app(ProjectMapLocations::class)->locate('尼泊尔隧道工程');
        $this->assertSame(['尼泊尔'], array_column(array_values($locations), 'name'));
        $this->assertTrue(array_values($locations)[0]['overseas']);
    }

    public function test_longer_country_alias_does_not_match_a_different_short_country(): void
    {
        $locations = app(ProjectMapLocations::class)->locate('印度尼西亚桥梁工程');
        $this->assertSame(['印度尼西亚'], array_column(array_values($locations), 'name'));
    }

    public function test_cross_border_project_keeps_both_explicit_regions(): void
    {
        $locations = app(ProjectMapLocations::class)->locate('广西至越南铁路工程');
        $this->assertSame(['越南', '广西壮族自治区'], array_column(array_values($locations), 'name'));
    }

    public function test_received_documents_only_and_nearby_largest_amount_are_independent_of_all_project_data(): void
    {
        config(['project_map.verified_projects' => [
            'P1' => ['project_name' => '小项目', 'name' => '地点甲', 'longitude' => 114, 'latitude' => 30, 'precision' => '城市级概略', 'overseas' => false, 'basis' => '合同工程地点'],
            'P2' => ['project_name' => '大项目', 'name' => '地点乙', 'longitude' => 114.2, 'latitude' => 30, 'precision' => '城市级概略', 'overseas' => false, 'basis' => '合同工程地点'],
            'P3' => ['project_name' => '远处项目', 'name' => '地点丙', 'longitude' => 117, 'latitude' => 30, 'precision' => '城市级概略', 'overseas' => false, 'basis' => '合同工程地点'],
        ]]);
        $projects = collect([
            $this->record('p1', 'P1', ['name' => '小项目']),
            $this->record('p2', 'P2', ['name' => '大项目']),
            $this->record('p3', 'P3', ['name' => '远处项目']),
            $this->record('p4', 'P4', ['name' => '北京未收到合同', 'contract_amount' => 99999999]),
        ]);
        $contracts = collect([
            $this->record('c1', 'C1', ['project_id' => 'p1', 'amount' => 100, 'contract_attachments' => ['one.pdf']]),
            $this->record('c2', 'C2', ['project_id' => 'p2', 'amount' => 200, 'processing_letter_attachments' => ['two.pdf']]),
            $this->record('c3', 'C3', ['project_id' => 'p2', 'amount' => 100, 'contract_attachments' => ['three.pdf']]),
            $this->record('c4', 'C4', ['project_id' => 'p3', 'amount' => null, 'contract_attachments' => ['four.pdf']]),
        ]);
        $before = $projects->toArray();
        $map = app(ProjectMapLocations::class)->summarize($projects, $contracts);
        $this->assertSame(3, $map['total_count']);
        $this->assertSame(1, $map['excluded_count']);
        $this->assertCount(2, $map['points']);
        $this->assertSame('大项目', $map['points'][0]['projects'][0]['name']);
        $this->assertSame(300.0, $map['points'][0]['projects'][0]['contract_amount']);
        $this->assertNull($map['points'][1]['projects'][0]['contract_amount']);
        $this->assertSame($before, $projects->toArray());
    }

    public function test_zero_beats_missing_amount_and_equal_amount_selection_is_stable(): void
    {
        $projects = collect([
            $this->record('p3', 'P3', ['name' => '武汉缺金额', 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['a.pdf']]),
            $this->record('p2', 'P2', ['name' => '武汉零金额乙', 'contract_amount' => 0, 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['b.pdf']]),
            $this->record('p1', 'P1', ['name' => '武汉零金额甲', 'contract_amount' => 0, 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['c.pdf']]),
        ]);
        $map = app(ProjectMapLocations::class)->summarize($projects);
        $this->assertCount(1, $map['points']);
        $this->assertSame('P1', $map['points'][0]['projects'][0]['code']);
        $this->assertSame(0.0, $map['points'][0]['projects'][0]['contract_amount']);
    }

    public function test_verified_contract_site_overrides_an_ambiguous_route_name_only_for_the_matching_project(): void
    {
        $project = $this->record('p', 'XYC-20260815-241', ['name' => '宁盐一标  王总', 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['a.pdf']]);
        $resolver = app(ProjectMapLocations::class);
        $this->assertSame('泰州市', $resolver->summarize(collect([$project]))['points'][0]['name']);
        $project->payload = ['name' => '改名后未知项目', 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['a.pdf']];
        $this->assertSame([], $resolver->summarize(collect([$project]))['points']);
        $this->assertSame('线路级概略', array_values($resolver->locate('祁离2标'))[0]['precision']);
    }

    public function test_datong_and_active_overseas_anchors_use_verified_geography(): void
    {
        $resolver = app(ProjectMapLocations::class);
        $datong = array_values($resolver->locate('大同天黎高速 钢箱梁'))[0];
        $this->assertSame('大同市', $datong['name']);
        $this->assertSame(113.295259, $datong['longitude']);
        $this->assertSame(40.09031, $datong['latitude']);
        $innerMongolia = $resolver->locate('内蒙古鄂尔多斯');
        $this->assertCount(1, $innerMongolia);
        $this->assertFalse(array_values($innerMongolia)[0]['overseas']);
        $this->assertSame('鄂尔多斯市', array_values($innerMongolia)[0]['name']);
        $this->assertTrue(array_values($resolver->locate('蒙古国'))[0]['overseas']);
        $this->assertSame(20, array_values($resolver->locate('越南'))[0]['latitude']);
        $this->assertSame(116, array_values($resolver->locate('印尼'))[0]['longitude']);
    }

    public function test_confirmed_indonesia_factory_only_applies_to_exact_project_and_preserves_saved_factory(): void
    {
        $resolver = app(ProjectMapLocations::class);
        $payload = ['name' => '钢桁架（印尼）', 'contract_status' => '已有加工函', 'unassigned_processing_letter_attachments' => ['a.pdf']];
        $project = $this->record('p1', 'XYC-20260815-070', $payload);
        $this->assertSame('wenshui', $resolver->summarize(collect([$project]))['points'][0]['projects'][0]['factory']);
        $this->assertSame($payload, $project->payload);
        $other = $this->record('p2', 'OTHER', $payload);
        $this->assertNull($resolver->summarize(collect([$other]))['points'][0]['projects'][0]['factory']);
        $renamed = $this->record('p3', 'XYC-20260815-070', [...$payload, 'name' => '越南']);
        $this->assertNull($resolver->summarize(collect([$renamed]))['points'][0]['projects'][0]['factory']);
        $saved = $this->record('p4', 'XYC-20260815-070', [...$payload, 'factory_subject' => '江苏晋武']);
        $this->assertSame('suqian', $resolver->summarize(collect([$saved]))['points'][0]['projects'][0]['factory']);
    }

    private function record(string $id, string $code, array $payload): ObjectRecord
    {
        return (new ObjectRecord)->forceFill(['id' => $id, 'code' => $code, 'payload' => $payload]);
    }
}
