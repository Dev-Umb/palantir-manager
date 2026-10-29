<?php

namespace App\Jobs;

use App\Actions\ReimbursementBatchService;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementUpload;
use App\Models\ReimbursementVersion;
use App\Models\StoredAttachment;
use App\Models\User;
use App\Support\BatchInvoiceRecognition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessReimbursementInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public function __construct(public string $fileId)
    {
        $this->onConnection('invoice_batches')->onQueue('invoice-batches');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('invoice-file-'.$this->fileId))->releaseAfter(30)->expireAfter(210)];
    }

    public function handle(BatchInvoiceRecognition $recognizer, ReimbursementBatchService $service): void
    {
        $file = ReimbursementBatchFile::find($this->fileId);
        if (! $file || ! in_array($file->status, ['queued', 'processing'], true)) {
            return;
        }
        $file->update(['status' => 'processing']);
        try {
            $upload = ReimbursementUpload::findOrFail($file->upload_id);
            $stored = StoredAttachment::findOrFail($upload->stored_attachment_id);
            $r = $recognizer->recognize($stored);
            $issues = $r['warnings'];
            if (! $r['amount'] || (float) $r['amount'] <= 0) {
                $issues[] = '金额缺失或无效，请核对原件。';
            }
            if (! $r['invoice_date']) {
                $issues[] = '开票日期未识别，请核对。';
            }
            if ($r['uncertain']) {
                $issues[] = '分类不明确或明细混合，请确认类型。';
            }
            if (! $r['invoice_number']) {
                $issues[] = '发票号码缺失，无法完成号码查重。';
            }
            $batch = ReimbursementBatch::findOrFail($file->batch_id);
            DB::transaction(function () use ($file, $batch, $r, &$issues): void {
                User::whereKey($batch->user_id)->lockForUpdate()->firstOrFail();
                $other = ReimbursementBatchFile::where('id', '!=', $file->id)->whereNotIn('status', ['excluded', 'uploading'])->whereIn('batch_id', ReimbursementBatch::where('user_id', $batch->user_id)->select('id'))->get();
                if ($other->contains(fn ($f) => $f->sha256 === $file->sha256 || (! empty($r['invoice_number']) && ($f->result['invoice_number'] ?? null) === $r['invoice_number'] && ($f->result['invoice_code'] ?? null) === ($r['invoice_code'] ?? null)))) {
                    $issues[] = '与本人其他发票疑似重复，请确认或排除。';
                }
                if (ReimbursementUpload::where('user_id', $batch->user_id)->where('id', '!=', $file->upload_id)->whereIn('stored_attachment_id', StoredAttachment::where('sha256', $file->sha256)->select('id'))->exists()) {
                    $issues[] = '相同文件曾上传，请核对是否重复报销。';
                }
                if (! empty($r['invoice_number'])) {
                    $payloads = ReimbursementDraft::where('user_id', $batch->user_id)->pluck('payload')->concat(ReimbursementVersion::where('user_id', $batch->user_id)->get()->map(fn ($v) => ['items' => $v->items]));
                    foreach ($payloads as $payload) {
                        foreach ($payload['items'] ?? [] as $item) {
                            if (preg_match('/发票号码[：:]\s*'.preg_quote($r['invoice_number'], '/').'(?![0-9])/u', $item['description'] ?? '')) {
                                $issues[] = '本人草稿或历史报销包含相同发票号码，请核对。';
                                break 2;
                            }
                        }
                    }
                }
                $file->update(['status' => 'recognized', 'result' => $r, 'issues' => array_values(array_unique($issues)), 'reviewed' => count($issues) === 0, 'error' => null]);
            });
        } catch (\UnexpectedValueException $e) {
            $file->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
        $service->finish(ReimbursementBatch::findOrFail($file->batch_id));
    }

    public function failed(?Throwable $exception): void
    {
        $file = ReimbursementBatchFile::find($this->fileId);
        if (! $file || ! in_array($file->status, ['queued', 'processing'], true)) {
            return;
        }
        $file->update(['status' => 'failed', 'error' => '识别暂未完成，请重试或检查文件。']);
        app(ReimbursementBatchService::class)->finish(ReimbursementBatch::findOrFail($file->batch_id));
    }
}
