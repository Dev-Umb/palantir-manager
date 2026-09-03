<?php

namespace Tests\Unit;

use App\Integrations\Feishu\FeishuClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FeishuClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.feishu.base_url', 'https://open.feishu.test/open-apis');
        config()->set('services.feishu.app_id', 'test-app-id');
        config()->set('services.feishu.app_secret', 'test-app-secret');
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_refreshes_an_invalid_tenant_token_and_retries_once(): void
    {
        Http::fakeSequence()
            ->push(['code' => 0, 'tenant_access_token' => 'expired-token'])
            ->push(['code' => 99991663, 'msg' => 'Invalid access token'], 400)
            ->push(['code' => 0, 'tenant_access_token' => 'fresh-token'])
            ->push(['code' => 0, 'data' => ['message_id' => 'om_123']]);

        $result = app(FeishuClient::class)->sendText('ou_123', 'hello');

        $this->assertSame(['message_id' => 'om_123'], $result);
        Http::assertSentCount(4);

        $requests = Http::recorded()->pluck(0)->values();
        $this->assertSame('Bearer expired-token', $requests[1]->header('Authorization')[0]);
        $this->assertSame('Bearer fresh-token', $requests[3]->header('Authorization')[0]);
    }

    public function test_it_does_not_retry_an_unrelated_feishu_error(): void
    {
        Http::fakeSequence()
            ->push(['code' => 0, 'tenant_access_token' => 'valid-token'])
            ->push(['code' => 230001, 'msg' => 'invalid receive id'], 400);

        try {
            app(FeishuClient::class)->sendText('ou_missing', 'hello');
            $this->fail('Expected the Feishu request to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('code=230001', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_it_preserves_the_cached_token_on_success(): void
    {
        Cache::put('feishu:tenant-token:'.sha1('test-app-id'), 'cached-token', now()->addHour());
        Http::fake([
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0,
                'data' => ['message_id' => 'om_cached'],
            ]),
        ]);

        $result = app(FeishuClient::class)->sendText('ou_123', 'hello');

        $this->assertSame(['message_id' => 'om_cached'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cached-token'));
    }
}
