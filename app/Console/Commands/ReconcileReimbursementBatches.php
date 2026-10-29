<?php

namespace App\Console\Commands;

use App\Actions\ReimbursementBatchService;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReconcileReimbursementBatches extends Command
{
    protected $signature = 'reimbursements:reconcile-batches';

    protected $description = 'Recover interrupted invoice tasks and remove expired unconfirmed objects';

    public function handle(ReimbursementBatchService $service): int
    {
        ReimbursementBatchFile::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(20))->each(function ($f): void {
            $f->update(['status' => 'failed', 'error' => '处理已中断，请重试。']);
        });
        ReimbursementBatch::where('status', 'processing')->each(fn ($b) => $service->finish($b));
        ReimbursementBatchFile::where('created_at', '<', now()->subDay())->each(function ($f): void {
            $disk = Storage::disk($f->disk);
            $disk->delete($f->object_key.'.upload');
            if ($f->upload_id === null && $f->updated_at->lt(now()->subDay())) {
                $disk->delete($f->object_key);
                $f->update(['status' => 'excluded', 'error' => '未完成的上传已过期，请重新添加文件。']);
            }
        });
        $root = realpath(config('reimbursements.batch_memory_path'));
        if ($root) {
            foreach (glob($root.'/job-*') as $dir) {
                if (is_dir($dir) && filemtime($dir) < time() - 3600) {
                    foreach (glob($dir.'/*') as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }rmdir($dir);
                }
            }
        }

        return self::SUCCESS;
    }
}
