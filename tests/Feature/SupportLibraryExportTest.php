<?php

namespace Tests\Feature;

use App\Support\SupportLibraryCalculator;
use App\Support\SupportLibraryExport;
use App\Support\SupportLibraryImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SupportLibraryFixtures;
use Tests\TestCase;
use ZipArchive;

class SupportLibraryExportTest extends TestCase
{
    use RefreshDatabase, SupportLibraryFixtures;

    private function withZip(string $bytes, callable $assertion): void
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        try {
            $assertion($zip, $path);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_single_sheet_nine_columns_preserves_numbers_scope_and_order(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser());
        foreach (['6@2@3/4' => ['构件明细', 10012.89392], '6+5+0.7@2+3@3+3/4' => ['构件明细（部分）', 52255.85892]] as $expression => [$name, $weight]) {
            $response = $this->postJson('/support-library/exports/result', [...$this->queryInput($expression), 'total' => 0])->assertOk();
            $this->withZip($response->getContent(), function (ZipArchive $zip, string $path) use ($name, $weight): void {
                $sheets = app(SupportLibraryImport::class)->read($path);
                $this->assertCount(1, $sheets);
                $this->assertSame($name, $sheets[0]['name']);
                $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                $this->assertStringContainsString('state="frozen"', $xml);
                $this->assertStringContainsString('I1', $xml);
                $this->assertStringNotContainsString('<f>', $xml);
                $this->assertStringContainsString('<v>'.$weight.'</v>', $xml);
                $this->assertSame('钢柱', app(SupportLibraryImport::class)->cell($sheets[0], 2, 1));
            });
        }
    }

    public function test_library_has_two_sheets_and_template_has_instructions(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser());
        foreach (['/support-library/exports/library?library_revision=1' => 2, '/support-library/template' => 3] as $url => $count) {
            $response = $this->get($url)->assertOk();
            $this->withZip($response->getContent(), function ($zip, $path) use ($count): void {
                $this->assertCount($count, app(SupportLibraryImport::class)->read($path));
            });
        }
        $this->get('/support-library/exports/library?library_revision=2')->assertConflict();
        $this->get('/support-library/exports/library')->assertUnprocessable();
    }

    public function test_nulls_zero_and_formula_looking_notes_are_literal_cells(): void
    {
        $result = app(SupportLibraryCalculator::class)->query($this->baseline(), $this->queryInput('6/4')['entries']);
        $result['merged'][0]['hole'] = '=1+1 & <hello>';
        $result['merged'][0]['accounted_unit_weight_kg'] = null;
        $this->withZip(app(SupportLibraryExport::class)->result($result), function ($zip, $path): void {
            $s = app(SupportLibraryImport::class)->read($path)[0];
            $this->assertSame('=1+1 & <hello>', app(SupportLibraryImport::class)->cell($s, 2, 4));
            $this->assertSame('待补', app(SupportLibraryImport::class)->cell($s, 2, 6));
            $this->assertEquals(0, app(SupportLibraryImport::class)->cell($s, 2, 8));
            $this->assertStringNotContainsString('<f>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        });
    }

    public function test_offline_snapshot_keeps_version_without_unsafe_script_or_credentials(): void
    {
        $this->initializeLibrary();
        $this->actingAs($this->supportUser());
        $db = $this->baseline();
        $db['parts'][0]['note'] = '</script><script>alert(1)</script>';
        $this->putJson('/support-library/library', $this->saveInput($db))->assertOk();
        $response = $this->get('/support-library/exports/html?library_revision=2')->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringNotContainsString('original_path', $html);
        $this->assertStringContainsString('共享支架库版本 2', $html);
        $this->assertStringContainsString('<title>钢箱梁工作台 · 离线快照</title>', $html);
        $this->assertStringContainsString('<h1>钢箱梁工作台</h1>', $html);
        $this->assertStringContainsString('离线修改不会自动同步平台', $html);
        $start = strpos($html, '/*LIBRARY_START*/let DB=') + strlen('/*LIBRARY_START*/let DB=');
        $end = strpos($html, ';/*LIBRARY_END*/', $start);
        $payload = json_decode(substr($html, $start, $end - $start), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $payload['platform_snapshot']['revision']);
        $this->assertSame(52, count($payload['parts']));
    }
}
