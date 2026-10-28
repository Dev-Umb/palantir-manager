<?php

namespace App\Jobs;

use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SendReimbursementWechatNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [60, 300];

    public function __construct(public int $noticeId)
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $notice = ReimbursementNotice::find($this->noticeId);
        if (! $notice || ! in_array($notice->delivery_status, ['pending', 'retry'], true)) {
            return;
        }
        $version = ReimbursementVersion::findOrFail($notice->version_id);
        $binding = DB::table('wechat_account_bindings')->where('user_id', $notice->user_id)->where('appid', config('wechat.appid'))->where('version', $version->wechat_binding_version)->whereNotNull('openid')->first();
        if (! $binding || ! User::find($notice->user_id)) {
            $notice->update(['delivery_status' => 'unavailable']);

            return;
        }
        if (! config('reimbursements.wechat_template') || ! config('wechat.secret')) {
            $notice->update(['delivery_status' => 'not_configured']);

            return;
        }
        $token = Cache::remember('reimbursement-wechat-token:'.config('wechat.appid'), 6000, function (): string {
            $result = Http::connectTimeout(5)->timeout(10)->get('https://api.weixin.qq.com/cgi-bin/token', ['grant_type' => 'client_credential', 'appid' => config('wechat.appid'), 'secret' => config('wechat.secret')])->json();
            if (empty($result['access_token'])) {
                throw new \RuntimeException('微信凭证获取失败');
            }

            return $result['access_token'];
        });
        $claimed = ReimbursementNotice::whereKey($notice->id)->whereIn('delivery_status', ['pending', 'retry'])->update(['delivery_status' => 'sending', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->post('https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token='.urlencode($token), [
                'touser' => $binding->openid, 'template_id' => config('reimbursements.wechat_template'),
                'page' => 'pages/reimbursements/detail?id='.$version->id, 'miniprogram_state' => config('reimbursements.wechat_state'),
                'data' => [config('reimbursements.wechat_title_field') => ['value' => mb_substr($version->title, 0, 20)], config('reimbursements.wechat_status_field') => ['value' => $version->status === 'approved' ? '已通过' : '已驳回']],
            ]);
            $code = $response->json('errcode');
            if ($response->successful() && $code === 0) {
                $notice->update(['delivery_status' => 'sent', 'delivery_error' => null]);
            } elseif (in_array($code, [40001, 42001, -1], true) && $notice->fresh()->attempts < 3) {
                Cache::forget('reimbursement-wechat-token:'.config('wechat.appid'));
                $notice->update(['delivery_status' => 'retry', 'delivery_error' => (string) $code]);
            } else {
                $notice->update(['delivery_status' => $code === 43101 ? 'denied' : 'failed', 'delivery_error' => is_int($code) ? (string) $code : 'invalid_response']);
            }
        } catch (\Throwable) {
            // A timed-out send may have reached WeChat; do not blindly resend a one-time subscription.
            $notice->update(['delivery_status' => 'unknown', 'delivery_error' => 'transport_uncertain']);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ReimbursementNotice::whereKey($this->noticeId)->whereIn('delivery_status', ['pending', 'retry'])->update(['delivery_status' => 'failed', 'delivery_error' => 'job_failed']);
    }
}
