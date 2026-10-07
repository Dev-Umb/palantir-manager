<?php

namespace Tests\Feature;

use App\Ai\AiArtifactFactory;
use App\Ai\Tools\PrepareFixedQuotationTool;
use App\Ai\XycDataAgent;
use App\Events\AiRunEventCreated;
use App\Models\AiRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\FixedQuotationDocument;
use App\Support\FixedQuotationPdf;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use ZipArchive;

class FixedQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.harness_v2' => true]);
        Storage::fake('local');
        Event::fake([AiRunEventCreated::class]);
    }

    private function salesperson(string $role = 'business'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $role], ['label' => $role]);
        $permission = Permission::firstOrCreate(['key' => 'ai.harness.view'], ['label' => 'AI', 'module' => 'ai', 'action' => 'view']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role);

        return $user;
    }

    private function input(): array
    {
        return ['title' => '测试项目报价单', 'date' => '2026-10-07', 'contact' => '测试业务员', 'phone' => '10000000000', 'tax_rate' => '13', 'shipping' => '含运费', 'items' => [
            ['name' => 'BBU洞室模板', 'unit' => '吨', 'price' => '6350', 'material_price' => null, 'processing_price' => null],
            ['name' => '矮边墙及回填堵头模板', 'unit' => '吨', 'price' => '6350', 'material_price' => null, 'processing_price' => null],
        ]];
    }

    private function quotationRun(User $user, string $status = 'completed'): AiRun
    {
        return AiRun::create(['user_id' => $user->id, 'conversation_id' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'status' => $status, 'input' => '做个报价单', 'artifacts' => [['id' => 'quote-1', 'type' => 'quotation_docx', 'revision' => 1, 'data' => ['generated' => false]]]]);
    }

    private function path(AiRun $run): string
    {
        return '/ai/runs/'.$run->id.'/quotations/quote-1';
    }

    private function package(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'quotation-test-');
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
            $zip->close();

            return $parts;
        } finally {
            unlink($path);
        }
    }

    private function xml(string $xml): DOMXPath
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        return $xpath;
    }

    public function test_real_template_parts_fixed_content_and_formatting_are_preserved_for_two_rows(): void
    {
        $original = $this->package(file_get_contents(resource_path('quotation-template.docx')));
        $generated = $this->package(app(FixedQuotationDocument::class)->generate($this->input()));
        $this->assertSame(array_keys($original), array_keys($generated));
        foreach ($original as $name => $bytes) {
            if ($name !== 'word/document.xml') {
                $this->assertSame(hash('sha256', $bytes), hash('sha256', $generated[$name]), $name);
            }
        }
        $source = $this->xml($original['word/document.xml']);
        foreach ($source->query('//w:rPr/w:color') as $color) {
            $color->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', '000000');
        }
        $output = $this->xml($generated['word/document.xml']);
        foreach (['//w:sectPr', '//w:tblPr', '//w:tblGrid'] as $query) {
            $this->assertSame($source->query($query)->item(0)?->C14N(), $output->query($query)->item(0)?->C14N(), $query);
        }
        $sourceDrawing = $source->query('//w:drawing')->item(0)->cloneNode(true);
        $targetDrawing = $output->query('//w:drawing')->item(0)->cloneNode(true);
        foreach ([$sourceDrawing, $targetDrawing] as $drawing) {
            $position = $drawing->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'positionV')->item(0);
            $position->textContent = '';
        }
        $this->assertSame($sourceDrawing->C14N(), $targetDrawing->C14N());
        $this->assertSame(8, $output->query('//w:tbl/w:tr')->length);
        foreach ([2, 4, 5, 6] as $row) {
            $query = "//w:tbl/w:tr[{$row}]";
            $this->assertSame($source->query($query)->item(0)->C14N(), $output->query($query)->item(0)->C14N());
        }
        foreach ([7, 8] as $row) {
            $query = "//w:tbl/w:tr[{$row}]";
            $this->assertSame($source->query('//w:tbl/w:tr[7]/w:tc[6]')->item(0)->C14N(), $output->query($query.'/w:tc[6]')->item(0)->C14N());
            foreach (['w:trPr', 'w:tc/w:tcPr', 'w:tc/w:p/w:pPr', 'w:tc/w:p/w:r/w:rPr'] as $properties) {
                $this->assertSame(array_map(fn ($n) => $n->C14N(), iterator_to_array($source->query('//w:tbl/w:tr[7]/'.$properties))), array_map(fn ($n) => $n->C14N(), iterator_to_array($output->query($query.'/'.$properties))));
            }
        }
        $text = $output->evaluate('string(//w:document)');
        $this->assertStringContainsString('BBU洞室模板', $text);
        $this->assertStringContainsString('矮边墙及回填堵头模板', $text);
        $this->assertStringContainsString('6350元/吨', $text);
        $this->assertStringContainsString('2026 年 10 月 7 日', $text);
        $this->assertStringNotContainsString('渠宗元', $text);
        $this->assertStringContainsString('—', $text);
        $this->assertSame(FixedQuotationDocument::TEMPLATE_SHA256, hash_file('sha256', resource_path('quotation-template.docx')));
    }

    public function test_single_row_seal_is_unchanged_and_three_rows_move_only_by_added_row_height(): void
    {
        $original = $this->xml($this->package(file_get_contents(resource_path('quotation-template.docx')))['word/document.xml']);
        $namespace = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
        $original->registerNamespace('wp', $namespace);
        $originalOffset = (int) $original->evaluate('string(//wp:anchor/wp:positionV/wp:posOffset)');
        foreach ([1, 3] as $count) {
            $input = $this->input();
            $input['items'] = array_fill(0, $count, $input['items'][0]);
            $output = $this->xml($this->package(app(FixedQuotationDocument::class)->generate($input))['word/document.xml']);
            $output->registerNamespace('wp', $namespace);
            $this->assertSame(6 + $count, $output->query('//w:tbl/w:tr')->length);
            $this->assertSame($originalOffset + ($count - 1) * 592 * 635, (int) $output->evaluate('string(//wp:anchor/wp:positionV/wp:posOffset)'));
            if ($count === 1) {
                $this->assertSame($original->query('//w:drawing')->item(0)->C14N(), $output->query('//w:drawing')->item(0)->C14N());
            }
        }
    }

    public function test_tool_routes_partial_prices_to_card_and_preserves_existing_ai_tools(): void
    {
        $user = $this->salesperson();
        $payload = json_decode((new PrepareFixedQuotationTool($user))->handle(new Request(['items' => [['name' => '模板', 'price' => '0', 'unit' => null]], 'date' => null, 'contact' => null])), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('0', $payload['artifact']['data']['items'][0]['price']);
        $this->assertNull($payload['artifact']['data']['items'][0]['unit']);
        $this->assertSame('', $payload['artifact']['data']['phone']);
        $this->assertSame([$payload['artifact']], (new AiArtifactFactory)->fromToolResult('prepare_fixed_quotation', $payload)['artifacts']);
        $agent = XycDataAgent::make(user: $user);
        $tools = collect($agent->tools())->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : get_class($tool));
        $this->assertTrue($tools->contains('prepare_fixed_quotation'));
        $this->assertTrue($tools->contains('publish_html_artifact'));
        $this->assertTrue($tools->contains('query_object_records'));
        $this->assertStringContainsString('不要求材料网价或拆价', $agent->instructions());
        $this->assertStringContainsString('不作为冲突重复追问', $agent->instructions());
        $denied = json_decode((new PrepareFixedQuotationTool($this->salesperson('finance')))->handle(new Request(['items' => []])), true);
        $this->assertFalse($denied['ok']);
    }

    public function test_downloads_black_copy_and_pdf_without_changing_legacy_frozen_file(): void
    {
        $user = $this->salesperson();
        $run = $this->quotationRun($user);
        $bytes = file_get_contents(resource_path('quotation-template.docx'));
        $hash = hash('sha256', $bytes);
        $run->update(['artifacts' => [['id' => 'quote-1', 'type' => 'quotation_docx', 'data' => ['generated' => true, 'document_sha256' => $hash]]]]);
        $storagePath = 'ai-quotations/'.$run->id.'/'.hash('sha256', 'quote-1').'.docx';
        Storage::disk('local')->put($storagePath, $bytes);
        $response = $this->actingAs($user)->get($this->path($run).'/download')->assertOk();
        $black = $response->streamedContent();
        $xml = $this->xml($this->package($black)['word/document.xml']);
        foreach ($xml->query('//w:rPr/w:color') as $color) {
            $this->assertSame('000000', $color->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val'));
        }
        $pdf = $this->mock(FixedQuotationPdf::class);
        $pdf->shouldReceive('convert')->once()->with($black)->andReturn("%PDF-1.7\nexample\n%%EOF");
        $download = $this->get($this->path($run).'/download?format=pdf')->assertOk()->assertDownload('quotation.pdf')->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());
        $this->assertSame($hash, $run->refresh()->artifacts[0]['data']['document_sha256']);
        $this->assertSame($bytes, Storage::disk('local')->get($storagePath));
        $this->get($this->path($run).'/download?format=exe')->assertStatus(302);
        $this->actingAs($this->salesperson('admin'))->get($this->path($run).'/download?format=pdf')->assertNotFound();
    }

    public function test_pdf_failure_preserves_docx_and_converter_cleans_temporary_files(): void
    {
        $user = $this->salesperson();
        $run = $this->quotationRun($user);
        $this->actingAs($user)->postJson($this->path($run), $this->input())->assertOk();
        $pdf = $this->mock(FixedQuotationPdf::class);
        $pdf->shouldReceive('convert')->once()->andThrow(new \RuntimeException('Conversion failed'));
        $this->get($this->path($run).'/download?format=pdf')->assertStatus(503);
        $this->get($this->path($run).'/download')->assertOk()->assertDownload('quotation.docx');
        $directory = null;
        Process::fake(function ($process) use (&$directory) {
            $directory = dirname(end($process->command));
            file_put_contents($directory.'/quotation.pdf', "%PDF-1.7\nexample\n%%EOF");

            return Process::result(output: 'converted');
        });
        $converter = new FixedQuotationPdf;
        $this->assertStringStartsWith('%PDF-', $converter->convert('temporary docx'));
        $this->assertDirectoryDoesNotExist($directory);
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->expectException(\RuntimeException::class);
        $converter->convert('temporary docx');
    }

    public function test_equivalent_user_tax_and_delivery_words_are_normalized_without_discarding_conflicts(): void
    {
        $tool = new PrepareFixedQuotationTool($this->salesperson());
        foreach (['13%', '13％', '13'] as $taxRate) {
            $payload = json_decode($tool->handle(new Request(['items' => [['name' => '模板', 'price' => '6350']], 'tax_rate' => $taxRate, 'shipping' => '含运送到价'])), true);
            $this->assertSame('13', $payload['artifact']['data']['tax_rate']);
            $this->assertSame('含运费', $payload['artifact']['data']['shipping']);
        }
        $conflict = json_decode($tool->handle(new Request(['items' => [['name' => '模板']], 'tax_rate' => '3%', 'shipping' => '不含运费'])), true);
        $this->assertSame('3%', $conflict['artifact']['data']['tax_rate']);
        $this->assertSame('不含运费', $conflict['artifact']['data']['shipping']);
    }

    public function test_generation_download_freeze_and_idempotency_do_not_write_business_records(): void
    {
        $user = $this->salesperson();
        $run = $this->quotationRun($user);
        $path = $this->path($run);
        $this->actingAs($user)->get($path.'/download')->assertNotFound();
        $response = $this->postJson($path, $this->input())->assertOk()->assertJsonPath('artifact.data.generated', true);
        $hash = $response->json('artifact.data.document_sha256');
        $this->postJson($path, $this->input())->assertOk()->assertJsonPath('artifact.data.document_sha256', $hash);
        $download = $this->get($path.'/download')->assertOk()->assertDownload('quotation.docx');
        $this->assertSame($hash, hash('sha256', $download->streamedContent()));
        $this->postJson($path, [...$this->input(), 'title' => '另一项目'])->assertStatus(409);
        $this->assertSame('测试项目报价单', $run->refresh()->artifacts[0]['data']['title']);
        $this->assertDatabaseCount('object_records', 0);
        $this->assertDatabaseCount('quotation_archives', 0);
        $this->assertSame('completed', $run->status);
    }

    public function test_owner_role_completion_and_missing_fields_boundaries(): void
    {
        $owner = $this->salesperson();
        $run = $this->quotationRun($owner);
        $path = $this->path($run);
        $this->actingAs($this->salesperson('admin'))->postJson($path, $this->input())->assertNotFound();
        $this->get($path.'/download')->assertNotFound();
        $this->actingAs($this->salesperson('finance'))->postJson($path, $this->input())->assertForbidden();
        $this->actingAs($owner)->postJson($path, [...$this->input(), 'phone' => ''])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson($path, [...$this->input(), 'tax_rate' => '3'])->assertUnprocessable();
        $this->postJson($path, [...$this->input(), 'shipping' => '不含运费'])->assertUnprocessable();
        $tooMany = $this->input();
        $tooMany['items'] = array_fill(0, 4, $tooMany['items'][0]);
        $this->postJson($path, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('items');
        $input = $this->input();
        $input['items'][0]['material_price'] = '1';
        $input['items'][0]['processing_price'] = '2';
        $this->postJson($path, $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.price');
        $input['items'][0]['unit'] = '';
        $this->postJson($path, $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.unit');
        $this->assertFalse($run->refresh()->artifacts[0]['data']['generated']);
        $running = $this->quotationRun($owner, 'running');
        $this->postJson($this->path($running), $this->input())->assertStatus(409);
        $zero = $this->input();
        $zero['items'][0]['price'] = '0';
        $this->postJson($path, $zero)->assertOk();
    }
}
