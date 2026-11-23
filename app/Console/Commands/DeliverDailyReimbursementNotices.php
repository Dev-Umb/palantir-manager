<?php

namespace App\Console\Commands;

use App\Actions\ReimbursementService;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliverDailyReimbursementNotices extends Command
{
    protected $signature = 'reimbursements:prepare-daily-notices';

    protected $description = '为每位收件人选择当日最新的有效报销通知';

    public function handle(ReimbursementService $service): int
    {
        $now = now('Asia/Shanghai');
        $cutoff = $now->copy()->setTime(9, 0);
        if ($now->lt($cutoff) || ! config('wechat.notify.enabled')) {
            return self::SUCCESS;
        }
        $day = $cutoff->toDateString();
        $start = $cutoff->copy()->subDay()->utc();
        $cutoff->utc();
        $progress = ReimbursementNotice::where('created_at', '>', $start)->where('created_at', '<=', $cutoff)
            ->whereNotLike('event', 'approval:%')->where('delivery_status', 'pending')->whereNull('delivery_day');
        $assignees = DB::table('reimbursement_access')->where('kind', 'approver')
            ->whereIn('version_id', ReimbursementVersion::where('status', 'pending')->select('id'))->distinct()->pluck('user_id');
        $users = $assignees->merge((clone $progress)->distinct()->pluck('user_id'))->unique();
        foreach ($users as $userId) {
            DB::transaction(function () use ($progress, $userId, $service, $day, $cutoff): void {
                $user = User::lockForUpdate()->find($userId);
                if (! $user || $user->isTimebookOperator() || ReimbursementNotice::where('user_id', $userId)->where('delivery_day', $day)->exists()) {
                    return;
                }
                $changes = (clone $progress)->where('user_id', $userId)
                    ->whereIn('version_id', $service->visible($user)->where('user_id', $userId)->select('id'));
                $notice = $this->latestTask($user, $service, $cutoff, $day)
                    ?? (clone $changes)->latest('created_at')->latest('id')->first();
                if ($notice) {
                    $notice->forceFill(['delivery_day' => $day])->save();
                    $changes->update(['delivery_status' => 'not_required']);
                }
            }, 3);
        }

        return self::SUCCESS;
    }

    private function latestTask(User $user, ReimbursementService $service, Carbon $cutoff, string $day): ?ReimbursementNotice
    {
        $versions = $service->visible($user)->where('status', 'pending')->where('created_at', '<=', $cutoff)
            ->whereIn('id', DB::table('reimbursement_access')->where('kind', 'approver')->where('user_id', $user->id)->select('version_id'));
        $latest = null;
        $latestAt = null;
        foreach ($versions->lazyById() as $version) {
            if (! $service->canDecide($user, $version)) {
                continue;
            }
            $previous = collect($version->decisions)->where('node', '<', $version->node_index)->last();
            $assignedAt = isset($previous['at']) ? Carbon::parse($previous['at']) : $version->created_at;
            if ($assignedAt->gt($cutoff) || ($latestAt && $assignedAt->lt($latestAt))) {
                continue;
            }
            $latest = $version;
            $latestAt = $assignedAt;
        }
        if (! $latest) {
            return null;
        }

        return new ReimbursementNotice([
            'version_id' => $latest->id, 'user_id' => $user->id,
            'event' => 'approval:'.$latest->node_index.':'.$day, 'message' => '待你审批：'.$latest->title,
            'delivery_status' => 'pending',
        ]);
    }
}
