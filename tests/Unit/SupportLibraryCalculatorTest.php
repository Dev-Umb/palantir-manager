<?php

namespace Tests\Unit;

use App\Support\SupportCombinationParser;
use App\Support\SupportLibraryCalculator;
use App\Support\SupportLibraryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportLibraryCalculatorTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    private function baseline(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/support-library/baseline.json'), true);
    }

    private function calculate(string $expression, array $overrides = [], ?array $db = null): array
    {
        return (new SupportLibraryCalculator(new SupportCombinationParser))->query($db ?? $this->baseline(), [['expression' => $expression,
            'criteria' => [...['support_use' => '吊装', 'column_brace_model' => 'C14A', 'diameter_mm' => 273], ...$overrides]]]);
    }

    public static function goldenCases(): array
    {
        $golden = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/support-library/golden.json'), true);

        return array_combine(array_column($golden['cases'], 'name'), array_map(fn (array $case) => [$case], $golden['cases']));
    }

    #[DataProvider('goldenCases')]
    public function test_golden_quantities_bolts_and_full_vs_accounted_weight(array $case): void
    {
        $criteria = $case['conditions'];
        $entries = array_is_list($criteria) ? array_map(fn ($c) => ['expression' => $c['combination'], 'criteria' => $c], $criteria)
            : [['expression' => $case['expression'] ?? $criteria['combination'], 'criteria' => $criteria]];
        $r = (new SupportLibraryCalculator(new SupportCombinationParser))->query($this->baseline(), $entries);
        foreach (['groups' => 'total_groups', 'positions' => 'total_column_positions', 'columns' => 'total_columns', 'faces' => 'total_faces', 'matched' => 'matched_faces', 'braces' => 'total_braces'] as $expected => $field) {
            $this->assertSame($case[$expected], $r[$field], $field);
        }
        $this->assertSame($case['bolts'], $r['bolts']['quantity']);
        $this->assertSame($case['knownBolts'], $r['bolts']['known_quantity']);
        foreach (['full' => 'estimated_with_bolts_weight_kg', 'accounted' => 'accounted_with_bolts_weight_kg'] as $expected => $field) {
            if ($case[$expected] === null) {
                $this->assertNull($r[$field]);
            } else {
                $this->assertEqualsWithDelta($case[$expected], $r[$field], 0.000001);
            }
        }
        $this->assertSame(array_column($case['rows'], 0), array_column($r['merged'], 'part_id'));
        $this->assertSame(array_column($case['rows'], 2), array_column($r['merged'], 'quantity'));
        $this->assertSame(array_column($case['rows'], 3), array_column($r['merged'], 'bolt_quantity'));
    }

    public function test_all_sixty_model_frame_combinations_preserve_per_face_quantity(): void
    {
        $db = $this->baseline();
        foreach (['C14A', 'C16A'] as $model) {
            foreach ($db['frames'] as $frame) {
                $r = $this->calculate($frame['height_mm'] / 1000 .'@'.$frame['spacing_mm'] / 1000 .'@'.$frame['spacing_mm'] / 1000,
                    ['column_brace_model' => $model, 'diameter_mm' => $frame['diameter_mm']]);
                $this->assertSame(4, $r['total_columns']);
                foreach (array_filter($db['composition'], fn ($line) => $line['frame_id'] === $frame['id']) as $line) {
                    $row = array_find($r['merged'], fn ($row) => $row['part_id'] === $model.'-'.$line['part_id']);
                    $this->assertSame($line['per_frame'] * 4, $row['quantity']);
                }
            }
        }
    }

    public function test_original_weight_precedes_theory_without_filling_source_nulls(): void
    {
        $db = $this->baseline();
        $index = array_search('P028', array_column($db['parts'], 'id'));
        $this->assertNull($db['parts'][$index]['unit_weight_kg']);
        $base = $this->calculate('6@2@3/4', db: $db);
        $this->assertNull($base['total_weight_kg']);
        $db['parts'][$index]['unit_weight_kg'] = 30;
        $r = $this->calculate('6@2@3/4', db: $db);
        $row = array_find($r['merged'], fn ($p) => $p['part_id'] === 'C14A-P028');
        $this->assertEquals(720, $row['accounted_weight_kg']);
        $this->assertEqualsWithDelta(10148.93576, $r['estimated_with_bolts_weight_kg'], 1e-6);
    }

    public function test_independent_columns_do_not_create_joints_and_single_does_not_require_brace_pool(): void
    {
        $r = $this->calculate('6/2 6/4', ['column_brace_model' => null, 'support_use' => '顶推']);
        $this->assertSame(6, $r['total_columns']);
        $this->assertSame(0, $r['bolts']['quantity']);
        $this->assertNull($r['query_conditions']['column_brace_model']);
        $this->expectException(SupportLibraryException::class);
        $this->calculate('6@2@3', ['support_use' => '顶推']);
    }

    public function test_repeated_heights_keep_joints_and_hole_variants_remain_distinct(): void
    {
        $r = $this->calculate('6+6@2@3/4');
        $this->assertSame(32, $r['total_columns']);
        $this->assertSame(160, $r['total_braces']);
        $this->assertSame(128, $r['bolts']['column_bolt_quantity']);
        $r = $this->calculate('2@2@3');
        $this->assertCount(2, array_filter($r['merged'], fn ($row) => $row['kind'] === '柱撑' && $row['length_mm'] === 1674));
    }

    public function test_unknown_bolts_and_weights_are_not_zero(): void
    {
        $db = $this->baseline();
        $db['parts'][0]['unit_weight_kg'] = null;
        $index = array_search('P028', array_column($db['parts'], 'id'));
        $db['parts'][$index]['bolts_per_piece_raw'] = null;
        $r = $this->calculate('6@2@3/4', db: $db);
        $this->assertNull($r['column_weight_kg']);
        $this->assertNull($r['bolts']['quantity']);
        $this->assertSame(320, $r['bolts']['known_quantity']);
        $this->assertNull($r['accounted_with_bolts_weight_kg']);
        $db['parts'][$index]['bolts_per_piece_raw'] = 0;
        $r = $this->calculate('6@2@3/4', db: $db);
        $this->assertSame(320, $r['bolts']['quantity']);
    }

    public function test_joint_conflicts_block_the_query(): void
    {
        $db = $this->baseline();
        $db['parts'][1]['bolts_per_piece_raw'] = 6;
        $this->expectException(SupportLibraryException::class);
        $this->calculate('6+5/4', db: $db);
    }

    public function test_missing_column_and_count_overflow_are_hard_errors(): void
    {
        foreach (['6+7@2@3', '6@2@3/9007199254740991'] as $expression) {
            try {
                $this->calculate($expression);
                $this->fail('Should reject '.$expression);
            } catch (SupportLibraryException $e) {
                $this->assertContains($e->errorCode, ['COLUMN_NOT_UNIQUE', 'QUANTITY_OVERFLOW']);
            }
        }
    }

    public function test_missing_weight_profile_preserves_known_columns(): void
    {
        $db = $this->baseline();
        $db['weight_profiles'] = [];
        $r = $this->calculate('6@2@3/4', db: $db);
        $this->assertEquals(6884.8, $r['known_weight_kg']);
        $this->assertNull($r['estimated_weight_kg']);
        $this->assertNull($r['brace_theoretical_weight_kg']);
    }
}
