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
use App\Support\ReimbursementEvidenceRecognition;
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
            DB::transaction(function () use ($file, $batch, $upload, $r, &$issues): void {
                User::whereKey($batch->user_id)->lockForUpdate()->firstOrFail();
                $other = ReimbursementBatchFile::where('id', '!=', $file->id)->whereNotIn('status', ['excluded', 'uploading'])->whereIn('batch_id', ReimbursementBatch::where('user_id', $batch->user_id)->select('id'))->get();
                $numbers = collect($r['sources'])->pluck('number')->filter()->all();
                if ($other->contains(fn ($f) => $f->sha256 === $file->sha256 || collect($f->result['sources'] ?? [])->contains(fn ($source) => ! empty($source['number']) && in_array($source['number'], $numbers, true)) || (! empty($f->result['invoice_number']) && in_array($f->result['invoice_number'], $numbers, true)))) {
                    $issues[] = '与本人其他凭证疑似重复，请确认或排除。';
                }
                if (ReimbursementUpload::where('user_id', $batch->user_id)->where('id', '!=', $file->upload_id)->whereIn('stored_attachment_id', StoredAttachment::where('sha256', $file->sha256)->select('id'))->exists()) {
                    $issues[] = '相同文件曾上传，请核对是否重复报销。';
                }
                $payloads = ReimbursementDraft::where('user_id', $batch->user_id)->pluck('payload')->concat(ReimbursementVersion::where('user_id', $batch->user_id)->get()->map(fn ($v) => ['items' => $v->items]));
                foreach ($payloads as $payload) {
                    foreach ($payload['items'] ?? [] as $item) {
                        foreach ($numbers as $number) {
                            if (preg_match('/(?:发票号码|凭证号码)[：:]\s*'.preg_quote($number, '/').'(?![0-9])/u', $item['description'] ?? '')) {
                                $issues[] = '本人草稿或历史报销包含相同凭证号码，请核对。';
                                break 3;
                            }
                        }
                    }
                }
                $evidence = $r['recognition'];
                $known = array_map(fn ($issue) => '第 '.$issue['page'].' 页：'.$issue['message'], $evidence['issues']);
                foreach (array_diff($issues, $known) as $issue) {
                    $evidence['issues'][] = ['page' => 1, 'message' => $issue];
                }
                $evidence['status'] = $issues ? 'attention' : 'completed';
                $upload->forceFill(['evidence_status' => $evidence['status'], 'evidence_result' => $evidence])->save();
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
