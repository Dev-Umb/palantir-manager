<?php

namespace App\Jobs;

use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use App\Support\ReimbursementEvidenceRecognition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RecognizeReimbursementEvidence implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public function __construct(public string $uploadId)
    {
        $this->onConnection('invoice_batches')->onQueue('invoice-batches');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('evidence-'.$this->uploadId))->releaseAfter(30)->expireAfter(210)];
    }

    public function handle(ReimbursementEvidenceRecognition $recognizer): void
    {
        $upload = ReimbursementUpload::find($this->uploadId);
        if (! $upload || ! in_array($upload->evidence_status, ['queued', 'processing'], true)) {
            return;
        }
        $upload->forceFill(['evidence_status' => 'processing'])->save();
        $result = $recognizer->recognize(StoredAttachment::findOrFail($upload->stored_attachment_id), $upload->evidence_result ?? []);
        $upload->forceFill(['evidence_status' => $result['status'], 'evidence_result' => $result])->save();
        if ($result['status'] === 'processing') {
            self::dispatch($upload->id)->delay(now()->addSeconds(5));
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReimbursementUpload::whereKey($this->uploadId)->whereIn('evidence_status', ['queued', 'processing'])->update(['evidence_status' => 'failed']);
    }
}
