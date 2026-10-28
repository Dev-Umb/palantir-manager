<?php

namespace Tests\Feature;

use App\Actions\SaveSupportLibrary;
use App\Support\SupportLibraryCalculator;
use App\Support\SupportLibraryException;
use App\Support\SupportLibraryExport;
use App\Support\SupportLibraryImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\SupportLibraryFixtures;
use Tests\TestCase;
use ZipArchive;

class SupportLibraryImportTest extends TestCase
{
    use RefreshDatabase, SupportLibraryFixtures;

    private function upload(): UploadedFile
    {
        return new UploadedFile(__DIR__.'/../Fixtures/support-library/baseline.xlsx', '支架库.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }

    private function withFile(string $bytes, callable $assertion): void
    {
        $file = tempnam(sys_get_temp_dir(), 'support-test-');
        file_put_contents($file, $bytes);
        try {
            $assertion($file);
        } finally {
            unlink($file);
        }
    }

    public function test_actual_workbook_preview_and_confirmation_do_not_duplicate_records(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser());
        $p = $this->post('/support-library/imports/preview', ['expected_revision' => 1, 'file' => $this->upload()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('stats.partsAdded', 0)->assertJsonPath('stats.partsUpdated', 52)->assertJsonPath('stats.framesUpdated', 30)->json();
        $this->assertDatabaseCount('support_library_versions', 1);
        $input = ['preview_token' => $p['preview_token'], 'expected_revision' => 1, 'request_id' => (string) Str::uuid()];
        $this->postJson('/support-library/imports/commit', $input)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/support-library/imports/commit', $input)->assertOk()->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('support_library_versions', 2);
        $this->postJson('/support-library/query', $this->queryInput(revision: 2))->assertOk()->assertJsonPath('result.total_braces', 80);
        $this->assertCount(58, app(SaveSupportLibrary::class)->current()->payload_json['composition']);
    }

    public function test_preview_is_actor_and_revision_bound_and_expires(): void
    {
        $this->initializeLibrary();
        $first = $this->supportUser();
        $second = $this->supportUser();
        $this->actingAs($first);
        $p = $this->post('/support-library/imports/preview', ['expected_revision' => 1, 'file' => $this->upload()], ['Accept' => 'application/json'])->assertOk()->json();
        $input = ['preview_token' => $p['preview_token'], 'expected_revision' => 1, 'request_id' => (string) Str::uuid()];
        $this->actingAs($second)->postJson('/support-library/imports/commit', $input)->assertForbidden();
        $this->putJson('/support-library/library', $this->saveInput($this->baseline()))->assertOk();
        $this->actingAs($first)->postJson('/support-library/imports/commit', $input)->assertConflict();
        $this->travel(31)->minutes();
        $this->postJson('/support-library/imports/commit', $input)->assertGone();
        $this->assertDatabaseCount('support_library_versions', 2);
    }

    public function test_database_export_roundtrip_preserves_null_zero_holes_and_notes(): void
    {
        $db = $this->baseline();
        $db['parts'][0]['unit_weight_kg'] = null;
        $db['parts'][0]['bolts_per_piece_raw'] = 0;
        $db['parts'][25]['note'] = '=普通备注 & <test>';
        $bytes = app(SupportLibraryExport::class)->library($db);
        $this->withFile($bytes, function ($file) use ($db): void {
            $preview = app(SupportLibraryImport::class)->preview($file, '往返.xlsx', $this->baseline());
            $next = $preview['payload'];
            $this->assertNull($next['parts'][0]['unit_weight_kg']);
            $this->assertSame(0, $next['parts'][0]['bolts_per_piece_raw']);
            $this->assertSame($db['parts'][25]['note'], $next['parts'][25]['note']);
            $this->assertSame(52, $preview['stats']['partsUpdated']);
            $this->assertCount(58, $next['composition']);
        });
    }

    public function test_partial_file_keeps_absent_frames_and_updates_shared_part_fields(): void
    {
        $base = $this->baseline();
        $partial = $base;
        $partial['frames'] = [$base['frames'][0]];
        $partial['composition'] = array_values(array_filter($base['composition'], fn ($d) => $d['frame_id'] === $base['frames'][0]['id']));
        $partial['parts'][0]['unit_weight_kg'] = 500;
        $this->withFile(app(SupportLibraryExport::class)->library($partial), function ($file) use ($base): void {
            $p = app(SupportLibraryImport::class)->preview($file, '局部.xlsx', $base);
            $this->assertCount(30, $p['payload']['frames']);
            $this->assertSame(1, $p['stats']['framesUpdated']);
            $r = app(SupportLibraryCalculator::class)->query($p['payload'], $this->queryInput()['entries']);
            $this->assertEquals(8000, $r['column_weight_kg']);
        });
    }

    public function test_blank_template_bad_archive_formula_and_entity_are_rejected(): void
    {
        $import = app(SupportLibraryImport::class);
        foreach ([file_get_contents(resource_path('support-library/template.xlsx')), 'not a ZIP'] as $bytes) {
            $this->withFile($bytes, function ($file) use ($import): void {
                try {
                    $import->preview($file, 'invalid.xlsx', $this->baseline());
                    $this->fail('Invalid workbook accepted');
                } catch (SupportLibraryException $e) {
                    $this->assertSame('IMPORT_INVALID', $e->errorCode);
                }
            });
        }
        $this->withFile(file_get_contents(__DIR__.'/../Fixtures/support-library/baseline.xlsx'), function ($file) use ($import): void {
            $zip = new ZipArchive;
            $zip->open($file);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/(<c r="C2"[^>]*>)/', '$1<f>1+1</f>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
            try {
                $import->preview($file, 'formula.xlsx', $this->baseline());
                $this->fail('Formula accepted');
            } catch (SupportLibraryException $e) {
                $this->assertStringContainsString('C2', $e->getMessage());
                $this->assertStringContainsString('公式', $e->getMessage());
            }
            $zip->open($file);
            $zip->addFromString('xl/workbook.xml', '<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///etc/passwd">]><workbook/>');
            $zip->close();
            $this->expectException(SupportLibraryException::class);
            $import->read($file);
        });
    }
}
