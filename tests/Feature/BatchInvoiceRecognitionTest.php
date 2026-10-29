<?php

namespace Tests\Feature;

use App\Ai\Agents\BatchInvoiceAgent;
use App\Models\StoredAttachment;
use App\Support\BatchInvoiceRecognition;
use App\Support\InvoiceMemoryWorkspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchInvoiceRecognitionTest extends TestCase
{
    private function response(array $replace = []): array
    {
        return array_replace(['supported' => true, 'invoice_count' => 1, 'type' => '住宿费', 'uncertain' => false, 'amount' => '100.01', 'invoice_date' => '2026-10-29', 'invoice_number' => '12345678', 'invoice_code' => null, 'buyer' => '测试购买方', 'seller' => '测试酒店', 'description' => '住宿费', 'warnings' => [], 'evidence' => ['住宿费 100.01']], $replace);
    }

    private function file(): StoredAttachment
    {
        Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        Storage::disk('local')->put('invoice.png', $bytes);

        return new StoredAttachment(['disk' => 'local', 'object_key' => 'invoice.png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'mime_type' => 'image/png']);
    }

    private function recognize(StoredAttachment $file): array
    {
        $dir = sys_get_temp_dir().'/batch-test-'.uniqid();
        mkdir($dir);
        $workspace = \Mockery::mock(InvoiceMemoryWorkspace::class);
        $workspace->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback($dir));
        try {
            return (new BatchInvoiceRecognition($workspace))->recognize($file);
        } finally {
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function test_image_extraction_validates_fields_and_preserves_literal_amount(): void
    {
        BatchInvoiceAgent::fake([json_encode($this->response())])->preventStrayPrompts();
        $result = $this->recognize($this->file());
        $this->assertSame('100.01', $result['amount']);
        $this->assertStringContainsString('发票号码：12345678', $result['description']);
        BatchInvoiceAgent::assertPrompted(fn ($prompt) => $prompt->contains('单张发票'));
    }

    public function test_uncertain_category_becomes_other(): void
    {
        BatchInvoiceAgent::fake([json_encode($this->response(['uncertain' => true]))]);
        $this->assertSame('其他', $this->recognize($this->file())['type']);
    }

    public function test_combined_invoices_are_rejected(): void
    {
        BatchInvoiceAgent::fake([json_encode($this->response(['invoice_count' => 2]))]);
        $this->expectException(\UnexpectedValueException::class);
        $this->recognize($this->file());
    }

    public function test_invalid_amount_is_not_accepted_as_model_output(): void
    {
        BatchInvoiceAgent::fake([json_encode($this->response(['amount' => '1e6']))]);
        $this->expectException(ValidationException::class);
        $this->recognize($this->file());
    }

    public function test_changed_file_is_rejected_before_agent_call(): void
    {
        BatchInvoiceAgent::fake()->preventStrayPrompts();
        $file = $this->file();
        $file->sha256 = str_repeat('0', 64);
        try {
            $this->recognize($file);
            $this->fail('Expected checksum rejection');
        } catch (\UnexpectedValueException) {
            BatchInvoiceAgent::assertNeverPrompted();
        }
    }

    public function test_memory_workspace_does_not_fall_back_to_disk(): void
    {
        config(['reimbursements.batch_memory_path' => '/does-not-exist-invoice-memory']);
        $this->expectException(\RuntimeException::class);
        (new InvoiceMemoryWorkspace)->run(fn () => true);
    }
}
