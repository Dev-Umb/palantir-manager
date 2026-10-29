<?php

namespace Tests\Feature;

use App\Models\StoredAttachment;
use App\Support\InvoicePdfRecognition;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class InvoicePdfRecognitionTest extends TestCase
{
    public function test_private_external_sample_with_real_tools_when_supplied(): void
    {
        $file = getenv('INVOICE_SAMPLE_PDF');
        if (! $file) {
            $this->markTestSkipped('Set INVOICE_SAMPLE_PDF and INVOICE_SAMPLE_CENTS for private real-PDF validation.');
        }
        config(['filesystems.disks.invoice_sample' => ['driver' => 'local', 'root' => dirname($file), 'throw' => true]]);
        $attachment = new StoredAttachment(['disk' => 'invoice_sample', 'object_key' => basename($file), 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)]);
        $result = app(InvoicePdfRecognition::class)->recognize($attachment);
        $this->assertSame((int) getenv('INVOICE_SAMPLE_CENTS'), $result['fields']['total_cents']);
        $this->assertSame('recognized', $result['status']);
    }

    private function attachment(): StoredAttachment
    {
        Storage::fake('local');
        $bytes = '%PDF synthetic test';
        Storage::disk('local')->put('invoice.pdf', $bytes);

        return new StoredAttachment(['disk' => 'local', 'object_key' => 'invoice.pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    }

    private function invoice(): string
    {
        return '电子发票（普通发票） 发票号码：12345678901234567890'."\n".'价税合计（小写）¥113.00';
    }

    public function test_text_pdf_uses_fast_path_and_never_runs_ocr(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: $process->command[0] === 'pdfinfo' ? "Pages: 1\n" : $this->invoice()));
        $result = app(InvoicePdfRecognition::class)->recognize($this->attachment());
        $this->assertSame('text', $result['method']);
        $this->assertSame(11300, $result['fields']['total_cents']);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[0], ['tesseract', 'pdftoppm']));
    }

    public function test_empty_text_layer_falls_back_to_bounded_chinese_ocr(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: match ($process->command[0]) {
            'pdfinfo' => "Pages: 1\n",
            'tesseract' => $this->invoice(),
            default => '',
        }));
        $result = app(InvoicePdfRecognition::class)->recognize($this->attachment());
        $this->assertSame('ocr', $result['method']);
        $this->assertSame(11300, $result['fields']['total_cents']);
        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'tesseract' && in_array('chi_sim+eng', $process->command) && $process->timeout <= 16);
    }

    public function test_invalid_digest_is_rejected_before_any_processing(): void
    {
        Process::fake();
        $attachment = $this->attachment();
        $attachment->sha256 = str_repeat('0', 64);
        try {
            app(InvoicePdfRecognition::class)->recognize($attachment);
            $this->fail('Corrupt invoice accepted');
        } catch (RuntimeException) {
            Process::assertNothingRan();
        }
    }

    public function test_excessive_pages_and_unavailable_tools_fail_without_deleting_attachment(): void
    {
        foreach ([Process::result(output: "Pages: 6\n"), Process::result(exitCode: 127)] as $result) {
            Process::fake(['*' => $result]);
            $attachment = $this->attachment();
            try {
                app(InvoicePdfRecognition::class)->recognize($attachment);
                $this->fail('Unsupported PDF accepted');
            } catch (RuntimeException) {
                Storage::disk('local')->assertExists('invoice.pdf');
            }
        }
    }
}
