<?php

namespace Tests\Feature;

use App\Actions\CreateObjectRecord;
use App\Models\BusinessObject;
use App\Models\CodeSequence;
use App\Models\ObjectRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectCodeSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-31 12:00:00', 'Asia/Taipei'));
    }

    public function test_sequence_bootstraps_above_the_highest_historical_code(): void
    {
        $object = $this->sequenceObject();
        ObjectRecord::create([
            'business_object_id' => $object->id,
            'code' => 'SEQ-20260731-047',
            'title' => '历史高位编号',
            'payload' => ['name' => '历史高位编号'],
        ]);

        $code = app(CreateObjectRecord::class)->nextCode($object);

        $this->assertSame('SEQ-20260731-048', $code);
        $this->assertDatabaseHas('code_sequences', [
            'prefix' => 'SEQ',
            'sequence_date' => '2026-07-31',
            'last_number' => 48,
        ]);
    }

    public function test_deleting_the_latest_record_does_not_reuse_its_code(): void
    {
        $object = $this->sequenceObject();
        $writer = app(CreateObjectRecord::class);
        $first = $writer->handle($object, ['name' => '第一条']);
        $this->assertSame('SEQ-20260731-001', $first->code);

        $first->delete();
        $second = $writer->handle($object, ['name' => '第二条']);

        $this->assertSame('SEQ-20260731-002', $second->code);
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
    }

    public function test_repeated_allocations_are_distinct_and_share_one_locked_sequence_row(): void
    {
        $object = $this->sequenceObject();
        $writer = app(CreateObjectRecord::class);

        $firstCode = $writer->nextCode($object);
        $secondCode = $writer->nextCode($object);

        $this->assertSame('SEQ-20260731-001', $firstCode);
        $this->assertSame('SEQ-20260731-002', $secondCode);
        $this->assertSame(1, CodeSequence::where('prefix', 'SEQ')->count());
        $this->assertSame(2, CodeSequence::where('prefix', 'SEQ')->value('last_number'));
    }

    private function sequenceObject(): BusinessObject
    {
        return BusinessObject::create([
            'key' => 'sequence_test',
            'label' => '编号测试',
            'group' => '测试',
            'code_prefix' => 'SEQ',
            'title_field' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
            ],
            'roles' => ['admin'],
            'read_only' => false,
            'sort_order' => 999,
        ]);
    }
}
