<?php

namespace App\Http\Controllers;

use App\Support\WechatBinding;
use App\Support\WechatSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WechatNotificationController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->state($request))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('wechat.notify.enabled'), 422, '微信通知尚未启用。');
        $data = $request->validate([
            'accepted' => ['required', 'array', 'max:5'],
            'accepted.*' => ['string', 'max:40'],
        ]);
        $accepted = array_values(array_unique(array_intersect($data['accepted'], array_keys(WechatSubscriptions::templates()))));
        foreach ($accepted as $key) {
            WechatSubscriptions::grant($request->user()->id, $key);
        }

        return response()->json([
            ...$this->state($request),
            'accepted' => $accepted,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $user = $request->user();
        $templates = WechatSubscriptions::templates();
        $binding = DB::table('wechat_account_bindings')
            ->where('user_id', $user->id)
            ->where('appid', (string) config('wechat.appid'))
            ->first();

        return [
            'configured' => (bool) config('wechat.notify.enabled')
                && filled(config('wechat.appid'))
                && filled(config('wechat.secret'))
                && $templates !== [],
            'bound' => $binding !== null && filled($binding->openid),
            'addressable' => $binding !== null && WechatBinding::addressable($user->id),
            'templates' => collect($templates)
                ->map(fn (array $template, string $key): array => [
                    'key' => $key,
                    'label' => $template['label'],
                    'template_id' => $template['id'],
                ])
                ->values()
                ->all(),
            'grants' => WechatSubscriptions::counts($user->id),
        ];
    }
}
