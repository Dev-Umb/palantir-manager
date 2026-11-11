<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SteelGirderProject;
use App\Models\User;
use App\Support\BusinessFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class SteelGirderProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.business_files.disk' => 'contract_tos']);
        Storage::fake('contract_tos');
        $this->withoutVite();
    }

    private function user(array $actions = ['view', 'manage', 'export']): User
    {
        $this->artisan('steel-girder-projects:install-permissions')->assertSuccessful();
        $role = Role::create(['name' => 'steel-test-'.Str::uuid(), 'label' => '技术']);
        $role->permissions()->attach(Permission::whereIn('key', array_map(fn ($action) => 'steel-girder-projects.'.$action, $actions))->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function project(): SteelGirderProject
    {
        return SteelGirderProject::factory()->create();
    }

    private function url(SteelGirderProject $project, string $suffix = ''): string
    {
        return '/steel-girder-projects/projects/'.$project->id.$suffix;
    }

    private function workbook(bool $unsafe = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'steel-fixture-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="钢箱梁明细表" r:id="rId1"/><sheet name="材料汇总" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $cell = fn ($ref, $value) => is_numeric($value) ? '<c r="'.$ref.'"><v>'.$value.'</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row>'.$cell('A1', '名称').$cell('B1', '编号').$cell('C1', '规格').$cell('D1', '数量').'</row><row>'.$cell('A2', '剪力钉').$cell('B2', 'JD').$cell('C2', 'M20').$cell('D2', 4).'</row></sheetData></worksheet>');
        $summary = '<worksheet><sheetData><row>'.$cell('A1', '已计重量合计').$cell('B1', 10).'</row><row>'.$cell('A2', '净重合计').'<c r="B2"><f>SUM(B1)</f><v>0</v></c></row><row>'.$cell('A3', '内表面涂装').'<c r="B3"><f>SUM(B1)</f></c></row></sheetData><mergeCells><mergeCell ref="G5:H5"/></mergeCells></worksheet>';
        $zip->addFromString('xl/worksheets/sheet2.xml', ($unsafe ? '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]>' : '').$summary);
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('示例单幅.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_access_and_independent_ordered_navigation(): void
    {
        $this->get('/steel-girder-projects')->assertRedirect('/login');
        $this->get('/steel-girder-projects/workspace')->assertRedirect('/login');
        $this->actingAs($this->user([]))->get('/steel-girder-projects/workspace')->assertForbidden();
        $user = $this->user();
        $this->artisan('support-library:install-permissions')->assertSuccessful();
        $user->roles()->first()->permissions()->attach(Permission::where('key', 'support-library.view')->value('id'));
        $this->actingAs($user->fresh())->get('/steel-girder-projects')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SteelGirderProjects/Index')
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('key', 'support-library')['children'][0]['items'][0]['label'] === '项目管理'
                && collect($nav)->firstWhere('key', 'support-library')['children'][0]['items'][1]['label'] === '支架检索'));
        $this->get('/steel-girder-projects/workspace')->assertOk()->assertSee('服务器', false)->assertDontSee('indexedDB.open', false);
    }

    public function test_creation_is_idempotent_and_shared_but_not_public(): void
    {
        $user = $this->user();
        $input = ['id' => (string) Str::uuid(), 'name' => '测试桥'];
        $this->actingAs($user)->postJson('/steel-girder-projects/projects', $input)->assertOk();
        $this->postJson('/steel-girder-projects/projects', $input)->assertOk();
        $this->assertDatabaseCount('steel_girder_projects', 1);
        $this->actingAs($this->user(['view']))->getJson('/steel-girder-projects/projects')->assertJsonPath('0.name', '测试桥');
        $this->postJson('/steel-girder-projects/projects', ['id' => (string) Str::uuid(), 'name' => '无权'])->assertForbidden();
        $this->actingAs($this->user([]))->getJson('/steel-girder-projects/projects')->assertForbidden();
    }

    public function test_drawings_retain_bytes_deduplicate_and_isolate_project_downloads(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $bytes = '%PDF-1.7 original';
        $this->post($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('桥.pdf', $bytes)])->assertOk()->assertJsonPath('added', 1);
        $this->post($this->url($project, '/drawings'), ['expected_revision' => 2, 'file' => UploadedFile::fake()->createWithContent('另一名称.pdf', $bytes)])->assertOk()->assertJsonPath('skipped', 1);
        $this->assertDatabaseCount('steel_girder_drawings', 1);
        $drawing = $project->drawings()->firstOrFail();
        $this->assertSame($bytes, Storage::disk('contract_tos')->get($drawing->attachment->object_key));
        $this->assertSame($bytes, $this->get($this->url($project, '/drawings/'.$drawing->id))->assertOk()->streamedContent());
        $this->get($this->url($this->project(), '/drawings/'.$drawing->id))->assertNotFound();
        $this->actingAs($this->user(['view']))->get($this->url($project, '/drawings/'.$drawing->id))->assertForbidden();
    }

    public function test_drawing_delete_and_project_delete_preserve_tos_and_reject_stale_writes(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $this->post($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('桥.dwg', 'AC1027 test')])->assertOk();
        $drawing = $project->drawings()->firstOrFail();
        $this->deleteJson($this->url($project, '/drawings/'.$drawing->id), ['expected_revision' => 1])->assertConflict();
        $this->assertNotSoftDeleted($drawing);
        $this->deleteJson($this->url($project, '/drawings/'.$drawing->id), ['expected_revision' => 2])->assertOk();
        $this->assertSoftDeleted($drawing);
        Storage::disk('contract_tos')->assertExists($drawing->attachment->object_key);
        $this->deleteJson($this->url($project), ['expected_revision' => 3])->assertOk();
        $this->assertSoftDeleted($project);
        $this->getJson($this->url($project))->assertNotFound();
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 4, 'reviews' => [], 'unit' => 't'])->assertNotFound();
        Storage::disk('contract_tos')->assertExists($drawing->attachment->object_key);
    }

    public function test_invalid_drawing_and_non_tos_storage_fail_without_saving(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $this->postJson($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('bad.pdf', 'not PDF')])->assertUnprocessable();
        config(['services.business_files.disk' => 'local']);
        $this->postJson($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('ok.pdf', '%PDF-1.7 test')])->assertStatus(503);
        $this->assertDatabaseCount('steel_girder_drawings', 0);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_materials_are_parsed_on_server_and_originals_are_export_gated(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $file = $this->workbook();
        $bytes = file_get_contents($file->getRealPath());
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 1, 'file' => $file, 'reviews' => [], 'unit' => 't', 'parsed_payload' => ['forged' => true]])->assertOk();
        $material = $project->fresh()->material;
        $cells = array_column($material->parsed_payload[1]['cells'], null, 'ref');
        $this->assertSame(['A1', 'B1', 'A2', 'B2', 'A3', 'B3'], array_column($material->parsed_payload[1]['cells'], 'ref'));
        $this->assertSame(10, $cells['B1']['value']);
        $this->assertSame(0, $cells['B2']['value']);
        $this->assertTrue($cells['B3']['missingCache']);
        $this->assertSame(8, $material->parsed_payload[1]['merges'][0]['end']['col']);
        $this->assertSame($bytes, $this->get($this->url($project, '/original?material_id='.$material->id))->assertOk()->streamedContent());
        $this->get($this->url($project, '/original?material_id='.(string) Str::uuid()))->assertConflict();
        $this->actingAs($this->user(['view']))->getJson($this->url($project))->assertOk()->assertJsonPath('data.filename', '示例单幅.xlsx');
        $this->get($this->url($project, '/original?material_id='.$material->id))->assertForbidden();
    }

    public function test_review_saves_conflict_and_invalid_replacement_preserve_committed_material(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 1, 'file' => $this->workbook(), 'reviews' => [], 'unit' => 't'])->assertOk();
        $material = $project->fresh()->material;
        $input = ['expected_revision' => 2, 'material_id' => $material->id, 'reviews' => [['id' => 'design', 'checked' => true, 'note' => '复核通过', 'signature' => '{}']], 'unit' => 'kg'];
        $this->postJson($this->url($project, '/material'), $input)->assertOk();
        $this->postJson($this->url($project, '/material'), [...$input, 'reviews' => []])->assertConflict();
        $this->assertSame('复核通过', $material->fresh()->reviews[0]['note']);
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 3, 'file' => $this->workbook(true), 'reviews' => [], 'unit' => 't'])->assertUnprocessable();
        $this->assertSame($material->id, $project->fresh()->current_material_id);
        $this->assertDatabaseCount('steel_girder_materials', 1);
        Storage::disk('contract_tos')->assertExists($material->attachment->object_key);
    }

    public function test_tos_failure_never_commits_a_project_file(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $this->mock(BusinessFileStorage::class, function ($mock): void {
            $mock->shouldReceive('diskName')->andReturn('contract_tos');
            $mock->shouldReceive('writeVerified')->once()->andThrow(new \RuntimeException('TOS unavailable'));
            $mock->shouldNotReceive('deleteUploaded');
        });
        $this->postJson($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->createWithContent('桥.pdf', '%PDF-1.7 test')])->assertStatus(500);
        $this->assertDatabaseCount('steel_girder_drawings', 0);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_zip_keeps_same_name_different_content(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        foreach ([1, 2] as $revision) {
            $this->postJson($this->url($project, '/drawings'), ['expected_revision' => $revision, 'file' => UploadedFile::fake()->createWithContent('桥.pdf', '%PDF-1.7 '.$revision)])->assertOk();
        }
        $bytes = $this->get($this->url($project, '/drawings.zip'))->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'steel-result-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame('%PDF-1.7 1', $zip->getFromName('桥.pdf'));
        $this->assertSame('%PDF-1.7 2', $zip->getFromName('桥 (2).pdf'));
        $zip->close();
        unlink($path);
    }

    public function test_multipart_reviews_and_file_limits_are_validated_before_mutation(): void
    {
        $this->actingAs($this->user());
        $project = $this->project();
        $this->postJson($this->url($project, '/drawings'), ['expected_revision' => 1, 'file' => UploadedFile::fake()->create('large.pdf', 204801)])->assertUnprocessable();
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 1, 'file' => $this->workbook(), 'reviews_json' => '[]', 'unit' => 't'])->assertOk();
        $material = $project->fresh()->material;
        $this->postJson($this->url($project, '/material'), ['expected_revision' => 2, 'material_id' => $material->id, 'reviews_json' => '{broken', 'unit' => 't'])->assertUnprocessable();
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertSame([], $material->fresh()->reviews);
    }

    public function test_permission_installation_is_additive_and_restrictive_profile_stays_restricted(): void
    {
        $engineering = Role::create(['name' => 'engineering', 'label' => '技术']);
        $existing = Permission::create(['key' => 'support-library.view', 'module' => 'support-library', 'action' => 'view', 'label' => '支架']);
        $engineering->permissions()->attach($existing);
        $this->artisan('steel-girder-projects:install-permissions')->assertSuccessful();
        $this->assertCount(4, $engineering->fresh()->permissions);
        $user = User::factory()->create();
        $user->roles()->attach($engineering);
        $user->roles()->attach(Role::create(['name' => 'timebook_operator', 'label' => '工日簿']));
        $this->actingAs($user)->getJson('/steel-girder-projects/projects')->assertForbidden();
    }
}
