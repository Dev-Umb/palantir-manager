<?php

namespace App\Actions;

use App\Jobs\ProcessReimbursementInvoice;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use App\Models\ReimbursementDraft;
use App\Models\ReimbursementUpload;
use App\Models\StoredAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReimbursementBatchService
{
    public function authorize(User $user, ReimbursementBatch $batch): void
    {
        abort_unless($user->canDo('reimbursements.submit') && ! $user->isTimebookOperator() && $batch->user_id === $user->id, 403);
    }

    public function files(ReimbursementBatch $batch): Builder
    {
        return ReimbursementBatchFile::where('batch_id', $batch->id);
    }

    public function reserve(ReimbursementBatch $batch, array $data): ReimbursementBatchFile
    {
        return DB::transaction(function () use ($batch, $data) {
            $batch = ReimbursementBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($batch->status === 'uploading', 409, '批次已开始处理。');
            $existing = ReimbursementBatchFile::find($data['id']);
            if ($existing) {
                abort_unless($existing->batch_id === $batch->id && $existing->name === $data['name'] && $existing->size === $data['size'] && $existing->mime === $data['mime'], 409);

                return $existing;
            }
            abort_if($this->files($batch)->count() >= 300 || $this->files($batch)->sum('size') + $data['size'] > 1073741824, 422, '每批最多 300 份、累计 1 GB。');

            return ReimbursementBatchFile::create([...$data, 'batch_id' => $batch->id, 'disk' => config('services.business_files.disk', 'contract_tos'), 'object_key' => 'invoice-batches/'.$batch->id.'/'.$data['id']]);
        });
    }

    public function confirm(ReimbursementBatchFile $file): void
    {
        DB::transaction(function () use ($file): void {
            $batch = ReimbursementBatch::lockForUpdate()->findOrFail($file->batch_id);
            $file = ReimbursementBatchFile::lockForUpdate()->findOrFail($file->id);
            if ($file->status !== 'uploading') {
                return;
            }
            abort_unless($batch->status === 'uploading', 409);
            $disk = Storage::disk($file->disk);
            $staging = $file->object_key.'.upload';
            abort_unless($disk->exists($staging) && $disk->size($staging) === $file->size, 422, '上传未完成或文件大小不符。');
            $disk->getDriver()->copy($staging, $file->object_key, ['visibility' => 'private']);
            $stream = $disk->readStream($file->object_key);
            abort_unless(is_resource($stream), 422);
            try {
                $bytes = stream_get_contents($stream, 20971521);
            } finally {
                fclose($stream);
            }
            abort_unless(is_string($bytes) && strlen($bytes) === $file->size && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $file->mime, 422, '文件内容、格式或大小不符。');
            $hash = hash('sha256', $bytes);
            unset($bytes);
            $stored = StoredAttachment::create(['logical_path' => $file->object_key, 'disk' => $file->disk, 'object_key' => $file->object_key, 'original_name' => $file->name, 'mime_type' => $file->mime, 'size' => $file->size, 'sha256' => $hash, 'status' => StoredAttachment::STATUS_ATTACHED]);
            $upload = ReimbursementUpload::create(['user_id' => $batch->user_id, 'stored_attachment_id' => $stored->id]);
            $duplicate = $this->files($batch)->where('sha256', $hash)->where('id', '!=', $file->id)->whereNotIn('status', ['excluded', 'uploading'])->exists();
            $file->update(['status' => $duplicate ? 'excluded' : 'uploaded', 'sha256' => $hash, 'upload_id' => $upload->id, 'issues' => $duplicate ? ['完全相同的文件已在本批次上传，未重复计入。'] : []]);
        });
    }

    public function start(ReimbursementBatch $batch, array $ids): void
    {
        DB::transaction(function () use ($batch, $ids): void {
            $batch = ReimbursementBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'uploading') {
                return;
            }
            $files = $this->files($batch)->whereIn('id', $ids)->whereIn('status', ['uploaded', 'excluded'])->get();
            abort_unless(count($ids) === count(array_unique($ids)) && $files->count() === count($ids) && $files->contains('status', 'uploaded'), 422, '请选择已成功上传的发票。');
            $this->files($batch)->whereNotIn('id', $ids)->update(['status' => 'excluded']);
            $batch->update(['status' => 'processing']);
            foreach ($files->where('status', 'uploaded') as $file) {
                $file->update(['status' => 'queued']);
                ProcessReimbursementInvoice::dispatch($file->id)->afterCommit();
            }
        });
    }

    public function finish(ReimbursementBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            User::whereKey($batch->user_id)->lockForUpdate()->firstOrFail();
            $batch = ReimbursementBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'uploading' || $this->files($batch)->whereIn('status', ['queued', 'processing'])->exists()) {
                return;
            }
            $pending = $this->files($batch)->where('status', 'recognized')->whereNull('draft_id')->orderBy('id')->get();
            foreach ($pending->groupBy(fn ($f) => $f->result['type'] ?? '其他') as $type => $group) {
                foreach ($group->chunk(50) as $chunk) {
                    $draft = ReimbursementDraft::create(['user_id' => $batch->user_id, 'id' => (string) Str::uuid(), 'batch_id' => $batch->id, 'revision' => 1, 'payload' => ['title' => $type.'报销', 'type' => $type, 'note' => '', 'cc' => [], 'items' => $chunk->map(fn ($f) => ['description' => $f->result['description'] ?? '', 'amount' => $f->result['amount'] ?? '', 'invoice_date' => $f->result['invoice_date'] ?? null, 'attachments' => [$f->upload_id]])->values()->all()]]);
                    foreach ($chunk as $file) {
                        $file->update(['draft_id' => $draft->id]);
                        ReimbursementUpload::whereKey($file->upload_id)->update(['draft_id' => $draft->id]);
                    }
                }
            }
            $batch->update(['status' => $this->files($batch)->where(function ($q) {
                $q->where('status', 'failed')->orWhere(fn ($q) => $q->where('status', 'recognized')->where('reviewed', false));
            })->exists() ? 'attention' : 'completed']);
        });
    }

    public function present(ReimbursementBatch $batch, bool $details = true): array
    {
        $files = $this->files($batch)->orderBy('created_at')->get();

        return [
            'id' => $batch->id, 'status' => $batch->status, 'created_at' => $batch->created_at->toISOString(),
            'total' => $files->where('status', '!=', 'excluded')->count(),
            'processed' => $files->whereIn('status', ['recognized', 'failed'])->count(),
            'draft_count' => ReimbursementDraft::where('batch_id', $batch->id)->whereJsonLength('payload->items', '>', 0)->count(),
            'failed_names' => $files->where('status', 'failed')->pluck('name')->all(),
            'files' => $details ? $files->map(fn ($file) => $file->only(['id', 'name', 'size', 'mime', 'status']))->all() : [],
        ];
    }
}
