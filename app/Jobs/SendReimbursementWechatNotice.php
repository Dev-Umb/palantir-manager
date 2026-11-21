<?php

namespace App\Jobs;

use App\Actions\ReimbursementService;
use App\Integrations\Wechat\WechatClient;
use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use App\Models\User;
use App\Support\ReimbursementWechatMessage;
use App\Support\WechatBinding;
use App\Support\WechatSubscriptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    public function handle(WechatClient $client): void
    {
        $notice = ReimbursementNotice::find($this->noticeId);
        if (! $notice || $notice->delivery_day !== now('Asia/Shanghai')->toDateString() || ! in_array($notice->delivery_status, ['pending', 'retry'], true)) {
            return;
        }

        $claimed = ReimbursementNotice::whereKey($notice->id)->whereIn('delivery_status', ['pending', 'retry'])->update([
            'delivery_status' => 'sending',
            'claimed_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
        ]);
        if (! $claimed) {
            return;
        }

        $template = ReimbursementWechatMessage::templateFor($notice);
        if ($template === null) {
            $notice->update(['delivery_status' => 'not_required']);

            return;
        }
        $version = ReimbursementVersion::findOrFail($notice->version_id);
        if (! config('wechat.notify.enabled') || ! $client->configured()) {
            $notice->update(['delivery_status' => 'not_configured']);

            return;
        }

        $bindingVersion = ReimbursementWechatMessage::templateKeyFor($notice) === ReimbursementWechatMessage::RESULT
            ? $version->wechat_binding_version
            : null;
        $openid = WechatBinding::openid($notice->user_id, $bindingVersion);
        $user = User::find($notice->user_id);
        $service = app(ReimbursementService::class);
        $isApproval = ReimbursementWechatMessage::templateKeyFor($notice) === ReimbursementWechatMessage::APPROVAL;
        if ($openid === null || ! $user || $user->isTimebookOperator()
            || ! $service->visible($user)->whereKey($version->id)->exists()
            || ($isApproval && (! in_array($notice->event, ['approval:'.$version->node_index, 'approval:'.$version->node_index.':'.$notice->delivery_day], true) || ! $service->canDecide($user, $version)))
            || (! $isApproval && ($version->user_id !== $user->id || $bindingVersion === null))) {
            $notice->update(['delivery_status' => 'unavailable']);

            return;
        }

        if (! WechatSubscriptions::consume($notice->user_id, $template['id'])) {
            $notice->update(['delivery_status' => 'denied', 'delivery_error' => 'no_subscription']);

            return;
        }

        try {
            $result = $client->sendSubscribeMessage(
                $openid,
                $template['id'],
                ReimbursementWechatMessage::dataFor($notice, $version, $template),
                ReimbursementWechatMessage::pageFor($version, $template),
            );
        } catch (ConnectionException) {
            // The message may have reached WeChat; never resend a one-time subscription.
            $notice->update(['delivery_status' => 'unknown', 'delivery_error' => 'transport_uncertain']);

            return;
        } catch (Throwable $error) {
            WechatSubscriptions::restore($notice->user_id, $template['id']);
            $notice->update([
                'delivery_status' => 'failed',
                'delivery_error' => mb_substr($error->getMessage() ?: 'delivery_failed', 0, 500),
            ]);
            report($error);

            return;
        }

        $code = $result['errcode'];
        if ($code === 0) {
            $notice->update(['delivery_status' => 'sent', 'delivery_error' => null]);

            return;
        }

        if ($code === 43101) {
            WechatSubscriptions::clear($notice->user_id, $template['id']);
            $notice->update(['delivery_status' => 'denied', 'delivery_error' => '43101']);

            return;
        }

        // A non-zero errcode means WeChat did not deliver the message; the grant is still usable.
        WechatSubscriptions::restore($notice->user_id, $template['id']);
        if (in_array($code, [40001, 42001, 40014, -1], true) && $notice->fresh()->attempts < 3) {
            $notice->update(['delivery_status' => 'retry', 'delivery_error' => (string) $code]);

            return;
        }

        $notice->update(['delivery_status' => 'failed', 'delivery_error' => (string) $code]);
    }

    public function failed(?Throwable $exception): void
    {
        ReimbursementNotice::whereKey($this->noticeId)->whereIn('delivery_status', ['pending', 'retry'])->update([
            'delivery_status' => 'failed',
            'delivery_error' => 'job_failed',
        ]);
    }
}
