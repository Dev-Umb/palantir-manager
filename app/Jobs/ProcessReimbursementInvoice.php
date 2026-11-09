<?php

namespace App\Jobs;

use App\Actions\ReimbursementBatchService;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use App\Support\ReimbursementEvidenceRecognition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function handle(ReimbursementEvidenceRecognition $recognizer, ReimbursementBatchService $service): void
    {
        $file = ReimbursementBatchFile::find($this->fileId);
        if (! $file || ! in_array($file->status, ['queued', 'processing'], true)) {
            return;
        }
        $file->update(['status' => 'processing']);
        try {
            $upload = ReimbursementUpload::findOrFail($file->upload_id);
            $stored = StoredAttachment::findOrFail($upload->stored_attachment_id);
            $result = $recognizer->recognize($stored, $file->result['recognition'] ?? [], skipNonEvidence: true);
            $upload->forceFill(['evidence_status' => $result['status'], 'evidence_result' => $result])->save();
            if ($result['status'] === 'processing') {
                $file->update(['result' => ['recognition' => $result]]);
                self::dispatch($file->id)->delay(now()->addSeconds(5));

                return;
            }
            if (count($result['items']) > 50) {
                throw new \UnexpectedValueException('此文件超过 50 项费用，请拆分后上传；原件已保留。');
            }
            $types = collect($result['items'])->pluck('type')->filter()->unique();
            $r = ['type' => $types->count() === 1 ? $types->first() : '其他', 'sources' => $result['items'], 'recognition' => $result];
            $issues = array_map(fn ($issue) => '第 '.$issue['page'].' 页：'.$issue['message'], $result['issues']);
            $batch = ReimbursementBatch::findOrFail($file->batch_id);
            if (! $r['sources'] && ! $issues) {
                $file->update(['status' => 'recognized', 'result' => $r, 'issues' => [], 'reviewed' => true, 'error' => null]);
                $service->finish($batch);

                return;
            }
            $file->update(['status' => 'recognized', 'result' => $r, 'issues' => array_values(array_unique($issues)), 'reviewed' => count($issues) === 0, 'error' => null]);
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
