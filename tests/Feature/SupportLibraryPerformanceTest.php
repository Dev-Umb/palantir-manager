<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\SupportLibraryFixtures;
use Tests\TestCase;

class SupportLibraryPerformanceTest extends TestCase
{
    use RefreshDatabase, SupportLibraryFixtures;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_agreed_twenty_entry_workload_has_bounded_latency_and_identical_counts(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser(['view']));
        $expression = implode('+', array_fill(0, 5, '6+5')).'@'.implode('+', array_fill(0, 5, '2+3')).'@'.implode('+', array_fill(0, 10, '3'));
        $input = $this->queryInput($expression);
        $input['entries'] = array_fill(0, 20, $input['entries'][0]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/support-library/query', $input)->assertOk();
        }
        $times = [];
        for ($i = 0; $i < 100; $i++) {
            $start = hrtime(true);
            $response = $this->postJson('/support-library/query', $input)->assertOk();
            $times[] = (hrtime(true) - $start) / 1e6;
            foreach (['total_column_positions' => 2420, 'total_columns' => 24200, 'total_faces' => 44000] as $key => $count) {
                $this->assertStringContainsString('"'.$key.'":'.$count.',', $response->getContent());
            }
            unset($response);
            gc_collect_cycles();
        }
        sort($times);
        $this->assertLessThanOrEqual(2000, $times[94], 'P95 query latency must be <=2 seconds');
        if (getenv('SUPPORT_LIBRARY_BENCHMARK_OUTPUT')) {
            file_put_contents(getenv('SUPPORT_LIBRARY_BENCHMARK_OUTPUT'), json_encode(['samples' => 100, 'warmups' => 5, 'entries' => 20,
                'height_segments' => 10, 'transverse_spans' => 10, 'longitudinal_spans' => 10,
                'p50_ms' => round($times[49], 3), 'p95_ms' => round($times[94], 3), 'max_ms' => round(max($times), 3),
                'php' => PHP_VERSION, 'database' => 'SQLite in-memory', 'scope' => 'Laravel HTTP feature test in one PHP process; not production or 10-client network concurrency'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
