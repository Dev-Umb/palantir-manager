<?php

namespace App\Console\Commands;

use App\Models\ReimbursementNotice;
use Illuminate\Console\Command;

class RetryReimbursementNotices extends Command
{
    protected $signature = 'reimbursements:retry-notice {id : 报销通知 ID}';

    protected $description = '重新排队明确发送失败或缺少配置的微信结果通知；不会重复发送已成功或结果不明的消息';

    public function handle(): int
    {
        $changed = ReimbursementNotice::whereKey($this->argument('id'))->whereIn('delivery_status', ['failed', 'not_configured'])->update(['delivery_status' => 'pending', 'attempts' => 0, 'claimed_at' => null, 'delivery_error' => null]);
        if (! $changed) {
            $this->error('通知不存在或不属于可安全重试状态。未发送消息。');

            return self::FAILURE;
        }
        $this->info('已重新排队，由调度器发送；请单独核验实际送达。');

        return self::SUCCESS;
    }
}
