<?php

namespace App\Console\Commands;

use App\Actions\ReimbursementBatchService;
use App\Models\ReimbursementBatch;
use App\Models\ReimbursementBatchFile;
use Illuminate\Console\Command;

class ReconcileReimbursementBatches extends Command
{
    protected $signature = 'reimbursements:reconcile-batches';

    protected $description = 'Recover interrupted invoice tasks and clear temporary memory files';

    public function handle(ReimbursementBatchService $service): int
    {
        ReimbursementBatchFile::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(20))->each(function ($f): void {
            $f->update(['status' => 'failed', 'error' => '处理已中断，请重试。']);
        });
        ReimbursementBatch::where('status', 'processing')->each(fn ($b) => $service->finish($b));
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
