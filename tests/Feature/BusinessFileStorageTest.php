<?php

namespace Tests\Feature;

use App\Models\StoredAttachment;
use App\Support\BusinessFileStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use RuntimeException;
use Tests\TestCase;

class BusinessFileStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('contract_tos');
        config(['services.business_files.disk' => 'contract_tos']);
    }

    public function test_timing_reports_failed_verification_without_bypassing_digest_check(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'abc');
        rewind($stream);
        $stages = [];
        try {
            app(BusinessFileStorage::class)->writeVerified('attachments/test.pdf', $stream, 3, hash('sha256', 'different'), 'application/pdf', function ($stage, $duration, $success) use (&$stages): void {
                $stages[$stage] = $success;
                $this->assertGreaterThanOrEqual(0, $duration);
            });
            $this->fail('Invalid hash accepted');
        } catch (RuntimeException) {
            $this->assertSame(['storage_write' => true, 'storage_verify' => false], $stages);
            $this->assertDatabaseCount('stored_attachments', 0);
        } finally {
            fclose($stream);
        }
    }

    public function test_signed_download_preserves_filename_type_and_expires_without_public_acl(): void
    {
        $this->freezeTime();
        $stored = app(BusinessFileStorage::class)->upload(UploadedFile::fake()->createWithContent('合同.pdf', '%PDF test'));
        $test = $this;
        Storage::disk('contract_tos')->buildTemporaryUrlsUsing(function ($key, $expires, $options) use ($stored, $test): string {
            $test->assertSame($stored->object_key, $key);
            $test->assertTrue($expires->equalTo(now()->addMinutes(5)));
            $test->assertSame('application/pdf', $options['ResponseContentType']);
            $test->assertStringContainsString("filename*=utf-8''", $options['ResponseContentDisposition']);
            $test->assertSame('private, no-store', $options['ResponseCacheControl']);

            return 'https://private-bucket.example/signed';
        });
        $this->assertSame('https://private-bucket.example/signed', app(BusinessFileStorage::class)->downloadUrl($stored->logical_path, '合同.pdf', 'application/pdf'));
        $stored->update(['disk' => 'local']);
        $this->assertNull(app(BusinessFileStorage::class)->downloadUrl($stored->logical_path, '合同.pdf', 'application/pdf'));
    }

    public function test_verification_finishes_at_declared_length_without_waiting_for_network_eof(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[0], 'abc');
        stream_set_timeout($pair[1], 1);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with('attachments/live.pdf')->andReturn(true);
        $disk->shouldReceive('size')->with('attachments/live.pdf')->andReturn(3);
        $disk->shouldReceive('readStream')->with('attachments/live.pdf')->andReturn($pair[1]);
        Storage::shouldReceive('disk')->with('contract_tos')->andReturn($disk);
        try {
            app(BusinessFileStorage::class)->verify('contract_tos', 'attachments/live.pdf', 3, hash('sha256', 'abc'));
            $this->assertFalse(is_resource($pair[1]));
            $this->assertTrue(is_resource($pair[0]));
        } finally {
            fclose($pair[0]);
        }
    }

    public function test_design_file_upload_uses_tos_and_preserves_original_name_and_hash(): void
    {
        $file = UploadedFile::fake()->createWithContent('施工图.dxf', 'DXF drawing bytes');
        $stored = app(BusinessFileStorage::class)->upload($file);
        $this->assertSame('contract_tos', $stored->disk);
        $this->assertSame('施工图.dxf', $stored->original_name);
        $this->assertSame(hash('sha256', 'DXF drawing bytes'), $stored->sha256);
        Storage::disk('contract_tos')->assertExists($stored->object_key);
        Storage::disk('local')->assertDirectoryEmpty('attachments');
        app(BusinessFileStorage::class)->deleteUploaded($stored->logical_path);
        Storage::disk('contract_tos')->assertMissing($stored->object_key);
        $this->assertDatabaseMissing('stored_attachments', ['id' => $stored->id]);
    }

    public function test_failed_storage_has_no_local_fallback_or_success_record(): void
    {
        Storage::disk('contract_tos')->getAdapter()->write('attachments/existing.pdf', 'original', new Config);
        $this->expectException(RuntimeException::class);
        app(BusinessFileStorage::class)->verify('contract_tos', 'attachments/existing.pdf', 8, hash('sha256', 'tampered'));
    }

    public function test_write_failure_never_registers_or_falls_back_locally(): void
    {
        $service = \Mockery::mock(BusinessFileStorage::class)->makePartial();
        $service->shouldReceive('writeVerified')->once()->andThrow(new RuntimeException('TOS unavailable'));
        try {
            $service->upload(UploadedFile::fake()->createWithContent('合同.pdf', '%PDF test'));
            $this->fail('Storage failure must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('TOS unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('stored_attachments', 0);
        Storage::disk('local')->assertDirectoryEmpty('attachments');
        Storage::disk('contract_tos')->assertDirectoryEmpty('attachments');
    }

    public function test_migration_uploads_before_switch_and_rechecks_every_file_without_deleting_sources(): void
    {
        Storage::disk('local')->put('attachments/legacy.pdf', '%PDF legacy');
        Storage::disk('local')->put('ai-quotations/legacy.docx', 'quotation');
        Storage::disk('local')->put('backups/private.json', 'DO NOT UPLOAD');
        $stored = StoredAttachment::factory()->create(['logical_path' => 'attachments/legacy.pdf', 'object_key' => 'attachments/legacy.pdf', 'disk' => 'local', 'original_name' => '原合同.pdf', 'size' => 11, 'sha256' => hash('sha256', '%PDF legacy')]);
        $manifest = sys_get_temp_dir().'/tos-test-'.bin2hex(random_bytes(8)).'.json';
        try {
            $this->artisan('business-files:tos', ['--phase' => 'inventory', '--manifest' => $manifest])->assertSuccessful();
            $this->artisan('business-files:tos', ['--phase' => 'upload', '--manifest' => $manifest])->assertSuccessful();
            $this->assertSame('local', $stored->fresh()->disk);
            $this->artisan('business-files:tos', ['--phase' => 'switch', '--manifest' => $manifest])->assertSuccessful();
            $this->assertSame('contract_tos', $stored->fresh()->disk);
            $this->assertSame('原合同.pdf', $stored->fresh()->original_name);
            $this->artisan('business-files:tos', ['--phase' => 'switch', '--manifest' => $manifest])->assertSuccessful();
            $this->assertDatabaseCount('audit_logs', 2);

            $this->artisan('business-files:tos', ['--phase' => 'verify', '--manifest' => $manifest])->assertSuccessful();
            $entries = json_decode(file_get_contents($manifest), true)['entries'];
            $this->assertCount(2, $entries);
            $this->assertCount(2, array_filter($entries, fn ($e) => ! empty($e['post_switch_verified_at'])));
            Storage::disk('local')->assertExists('attachments/legacy.pdf');
            Storage::disk('contract_tos')->assertMissing('backups/private.json');
            Storage::disk('contract_tos')->put('ai-quotations/legacy.docx', 'corrupt');
            $this->artisan('business-files:tos', ['--phase' => 'verify', '--manifest' => $manifest])->assertFailed();
        } finally {
            @unlink($manifest);
        }
    }

    public function test_concurrent_filename_change_is_not_overwritten_by_migration(): void
    {
        Storage::disk('local')->put('attachments/concurrent.pdf', 'original');
        $stored = StoredAttachment::factory()->create(['logical_path' => 'attachments/concurrent.pdf', 'object_key' => 'attachments/concurrent.pdf', 'disk' => 'local', 'original_name' => 'before.pdf', 'size' => 8, 'sha256' => hash('sha256', 'original')]);
        $manifest = sys_get_temp_dir().'/tos-test-'.bin2hex(random_bytes(8)).'.json';
        try {
            $this->artisan('business-files:tos', ['--phase' => 'inventory', '--manifest' => $manifest])->assertSuccessful();
            $this->artisan('business-files:tos', ['--phase' => 'upload', '--manifest' => $manifest])->assertSuccessful();
            $stored->update(['original_name' => 'changed.pdf']);
            try {
                $this->artisan('business-files:tos', ['--phase' => 'switch', '--manifest' => $manifest]);
                $this->fail('Concurrent edits must block switch');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Concurrent metadata change', $exception->getMessage());
            }
            $this->assertSame('changed.pdf', $stored->fresh()->original_name);
            $this->assertSame('local', $stored->fresh()->disk);
        } finally {
            @unlink($manifest);
        }
    }

    public function test_one_failed_upload_blocks_entire_metadata_switch(): void
    {
        Storage::disk('local')->put('attachments/one.pdf', 'one');
        $manifest = sys_get_temp_dir().'/tos-test-'.bin2hex(random_bytes(8)).'.json';
        try {
            $this->artisan('business-files:tos', ['--phase' => 'inventory', '--manifest' => $manifest])->assertSuccessful();
            $this->artisan('business-files:tos', ['--phase' => 'switch', '--manifest' => $manifest])->assertFailed();
            $this->assertDatabaseCount('stored_attachments', 0);
            Storage::disk('local')->assertExists('attachments/one.pdf');
        } finally {
            @unlink($manifest);
        }
    }
}
