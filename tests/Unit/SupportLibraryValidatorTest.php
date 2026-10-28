<?php

namespace Tests\Unit;

use App\Support\SupportLibraryException;
use App\Support\SupportLibraryValidator;
use PHPUnit\Framework\TestCase;

class SupportLibraryValidatorTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_baseline_preserves_unknowns_and_enforces_natural_keys_and_references(): void
    {
        $db = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/support-library/baseline.json'), true);
        $validator = new SupportLibraryValidator;
        $valid = $validator->validate($db);
        $this->assertCount(52, $valid['parts']);
        $this->assertCount(30, $valid['frames']);
        $this->assertCount(35, array_filter($valid['parts'], fn ($p) => $p['unit_weight_kg'] === null));
        $mutations = [
            function (&$x): void {
                $x['parts'][] = [...$x['parts'][25], 'id' => 'different'];
            },
            function (&$x): void {
                $x['parts'][0]['unit_weight_kg'] = 0;
            },
            function (&$x): void {
                $x['composition'][0]['part_id'] = 'P002';
            },
            function (&$x): void {
                $x['composition'][0]['per_frame'] = 0;
            },
            function (&$x): void {
                $x['frames'][] = [...$x['frames'][0], 'id' => 'different'];
            },
            function (&$x): void {
                $x['composition'][] = $x['composition'][0];
            },
            function (&$x): void {
                $x['parts'][0]['bolts_per_piece_raw'] = 1.5;
            },
        ];
        foreach ($mutations as $mutate) {
            $copy = $db;
            $mutate($copy);
            try {
                $validator->validate($copy);
                $this->fail('Invalid library accepted');
            } catch (SupportLibraryException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
        $db['parts'][0]['bolts_per_piece_raw'] = 0;
        $this->assertSame(0, $validator->validate($db)['parts'][0]['bolts_per_piece_raw']);
    }
}
