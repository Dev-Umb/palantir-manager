<?php

namespace Tests\Feature;

use App\Actions\CreateFeishuAiRun;
use App\Actions\SyncProjectNotifications;
use App\Actions\SyncTenderNotifications;
use App\Ai\AiFailureClassifier;
use App\Ai\AiRunEventPublisher;
use App\Ai\AiToolEventProjector;
use App\Ai\FeishuDataAgent;
use App\Ai\Tools\PrepareObjectRecordUpdateTool;
use App\Integrations\Feishu\FeishuClient;
use App\Integrations\Feishu\FeishuMessageRenderer;
use App\Integrations\Feishu\FeishuNotificationDispatcher;
use App\Integrations\Feishu\FeishuProcessingReaction;
use App\Jobs\DeliverFeishuNotification;
use App\Jobs\ProcessFeishuInboundEvent;
use App\Jobs\RunAiHarness;
use App\Jobs\SendFeishuAiReply;
use App\Models\AiRun;
use App\Models\BusinessObject;
use App\Models\FeishuInboundEvent;
use App\Models\FeishuUserBinding;
use App\Models\NotificationDelivery;
use App\Models\ObjectRecord;
use App\Models\ProjectNotification;
use App\Models\Role;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Mockery;
use Tests\TestCase;

class FeishuBotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('xyc:sync-metadata')->assertSuccessful();
        config()->set('services.feishu', [
            'enabled' => true,
            'base_url' => 'https://open.feishu.test/open-apis',
            'app_id' => 'test-app-id',
            'app_secret' => 'test-app-secret',
            'verification_token' => 'test-verification-token',
            'tenant_key' => 'test-tenant',
        ]);
        config()->set('app.url', 'https://palantir.example.test');
        URL::forceRootUrl('https://palantir.example.test');
        URL::forceScheme('https');
        Cache::clear();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        parent::tearDown();
    }

    public function test_project_test_data_flows_from_payment_reminder_to_feishu_message_request(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->userWithRole('admin');
        $this->userWithRole('finance');
        $owner = $this->userWithRole('business');
        FeishuUserBinding::factory()->for($owner)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_owner',
        ]);
        $project = $this->project($owner, [
            'overall_status' => '合同签署',
            'overall_status_changed_at' => now()->subMonthNoOverflow()->toISOString(),
            'contract_status' => '已签署',
            'payment_status' => '部分回款',
            'paid_amount' => 25000,
            'unpaid_amount' => 75000,
            'payment_progress' => 25,
        ]);

        $result = app(SyncProjectNotifications::class)->handleProjects([$project->id]);

        $this->assertSame(1, $result['triggered']);
        $notification = ProjectNotification::where('project_id', $project->id)
            ->where('user_id', $owner->id)
            ->where('type', ProjectNotification::TYPE_PAYMENT)
            ->sole();
        $delivery = NotificationDelivery::where('source_type', 'project_notification')
            ->where('source_id', (string) $notification->id)
            ->sole();
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_test_reminder'],
            ]),
        ]);

        app(DeliverFeishuNotification::class, ['deliveryId' => $delivery->id])
            ->handle(app(FeishuClient::class), app(FeishuMessageRenderer::class));

        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame('om_test_reminder', $delivery->fresh()->external_message_id);
        $messageRequest = collect(Http::recorded())
            ->first(fn (array $exchange): bool => str_contains($exchange[0]->url(), '/im/v1/messages'))[0];
        $card = json_decode((string) $messageRequest['content'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ou_owner', $messageRequest['receive_id']);
        $this->assertSame('interactive', $messageRequest['msg_type']);
        $this->assertSame($project->title, data_get($card, 'header.title.content'));
        $this->assertStringContainsString($owner->name, (string) data_get($card, 'elements.1.fields.0.text.content'));
        $this->assertStringContainsString('合同签署', (string) data_get($card, 'elements.1.fields.1.text.content'));
        $this->assertStringContainsString('部分回款', (string) data_get($card, 'elements.1.fields.2.text.content'));
        $this->assertStringContainsString('25%', (string) data_get($card, 'elements.1.fields.3.text.content'));
        $this->assertStringContainsString('75,000', (string) data_get($card, 'elements.1.fields.4.text.content'));
        $this->assertSame(
            "https://palantir.example.test/objects/project?record={$project->id}&mode=detail",
            data_get($card, 'elements.2.actions.0.url'),
        );

        app(SyncProjectNotifications::class)->handleProjects([$project->id]);
        $this->assertSame(1, NotificationDelivery::where('source_id', (string) $notification->id)->count());
    }

    public function test_payment_card_marks_missing_business_and_financial_values_without_inference(): void
    {
        $owner = $this->userWithRole('business');
        $project = $this->project($owner, [
            'business_owner_user_id' => '',
            'overall_status' => '合同签署',
            'payment_status' => '未回款',
        ]);
        $notification = ProjectNotification::create([
            'project_id' => $project->id,
            'type' => ProjectNotification::TYPE_PAYMENT,
            'user_id' => $owner->id,
            'status' => ProjectNotification::STATUS_ACTIVE,
            'triggered_at' => now(),
            'occurrences' => 1,
        ]);
        $delivery = NotificationDelivery::factory()->for($owner)->create([
            'source_type' => 'project_notification',
            'source_id' => (string) $notification->id,
        ]);

        $card = app(FeishuMessageRenderer::class)->renderCard($delivery);

        $this->assertNotNull($card);
        $this->assertStringContainsString('待补充', (string) data_get($card, 'elements.1.fields.0.text.content'));
        $this->assertStringContainsString('待补充', (string) data_get($card, 'elements.1.fields.3.text.content'));
        $this->assertStringContainsString('待补充', (string) data_get($card, 'elements.1.fields.4.text.content'));
    }

    public function test_non_payment_project_reminder_keeps_the_text_message_contract(): void
    {
        $owner = $this->userWithRole('business');
        FeishuUserBinding::factory()->for($owner)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_bid_owner',
        ]);
        $project = $this->project($owner, ['overall_status' => '投标中']);
        $notification = ProjectNotification::create([
            'project_id' => $project->id,
            'type' => ProjectNotification::TYPE_BID,
            'user_id' => $owner->id,
            'status' => ProjectNotification::STATUS_ACTIVE,
            'triggered_at' => now(),
            'occurrences' => 1,
        ]);
        $delivery = app(FeishuNotificationDispatcher::class)->dispatch($notification);
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_test_bid'],
            ]),
        ]);

        (new DeliverFeishuNotification($delivery->id))->handle(
            app(FeishuClient::class),
            app(FeishuMessageRenderer::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/im/v1/messages')
            && $request['msg_type'] === 'text'
            && str_contains((string) $request['content'], '项目投标跟进提醒'));
    }

    public function test_verified_private_message_flows_to_read_only_ai_run_and_feishu_reply(): void
    {
        $user = $this->userWithRole('business');
        $binding = FeishuUserBinding::factory()->for($user)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_query_user',
        ]);
        $payload = $this->messagePayload('event-query-1', 'ou_query_user', '帮我查看当前欠款和项目进度');

        $this->postJson('/webhooks/feishu/events', $payload)->assertOk()->assertJson(['code' => 0]);
        $this->postJson('/webhooks/feishu/events', $payload)->assertOk()->assertJson(['code' => 0]);
        $this->assertDatabaseCount('feishu_inbound_events', 1);
        $event = FeishuInboundEvent::sole();

        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages/*/reactions' => Http::response([
                'code' => 0, 'data' => ['reaction_id' => 'reaction_typing_1'],
            ]),
        ]);

        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );

        $run = $event->fresh()->aiRun;
        $this->assertNotNull($run);
        $this->assertSame('feishu', $run->origin);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame('processing', $event->fresh()->status);
        $this->assertSame('reaction_typing_1', $event->fresh()->processing_reaction_id);
        $this->assertNull($event->fresh()->processing_reaction_error);
        $this->assertSame($binding->fresh()->conversation_id, $run->conversation_id);
        $toolNames = collect(FeishuDataAgent::make(user: $user)->tools())
            ->map(fn ($tool): string => $tool::class)->all();
        $this->assertCount(3, $toolNames);
        $this->assertNotContains(PrepareObjectRecordUpdateTool::class, $toolNames);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), "/im/v1/messages/{$event->message_id}/reactions")
            && data_get($request->data(), 'reaction_type.emoji_type') === 'Typing');

        $stream = new StreamableAgentResponse((string) Str::uuid7(), function () {
            yield new TextDelta(
                id: (string) Str::uuid7(),
                messageId: (string) Str::uuid7(),
                delta: "## 查询结果\n\n当前可见项目欠款合计 **75,000 元**，项目处于合同签署阶段。",
                timestamp: time(),
            );
        }, new Meta(provider: 'fake', model: 'fake-model'));
        $agent = Mockery::mock(FeishuDataAgent::class);
        $agent->shouldReceive('continue')->once()->andReturnSelf();
        $agent->shouldReceive('stream')->once()->andReturn($stream);
        $this->app->bind(FeishuDataAgent::class, fn (): FeishuDataAgent => $agent);
        (new RunAiHarness($run->id))->handle(
            app(AiRunEventPublisher::class),
            app(AiToolEventProjector::class),
            app(AiFailureClassifier::class),
        );
        $this->assertSame('completed', $run->fresh()->status);

        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages/*/reactions/*' => Http::response([
                'code' => 0, 'data' => [],
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_test_ai_reply'],
            ]),
        ]);
        (new SendFeishuAiReply($run->id))->handle(
            app(FeishuClient::class),
            app(FeishuMessageRenderer::class),
            app(FeishuProcessingReaction::class),
        );

        $this->assertSame('completed', $event->fresh()->status);
        $this->assertSame('om_test_ai_reply', $event->fresh()->reply_message_id);
        $this->assertNotNull($event->fresh()->processing_reaction_removed_at);
        $this->assertNull($event->fresh()->processing_reaction_error);
        $replyRequest = collect(Http::recorded())
            ->first(fn (array $exchange): bool => $exchange[0]->method() === 'POST'
                && $exchange[0]['msg_type'] === 'interactive')[0];
        $replyCard = json_decode((string) $replyRequest['content'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ou_query_user', $replyRequest['receive_id']);
        $this->assertStringContainsString('receive_id_type=open_id', $replyRequest->url());
        $this->assertSame('interactive', $replyRequest['msg_type']);
        $this->assertSame('Palantir · 项目查询', data_get($replyCard, 'header.title.content'));
        $this->assertSame('lark_md', data_get($replyCard, 'elements.0.text.tag'));
        $this->assertStringContainsString('## 查询结果', (string) data_get($replyCard, 'elements.0.text.content'));
        $this->assertStringContainsString('**75,000 元**', (string) data_get($replyCard, 'elements.0.text.content'));
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), "/im/v1/messages/{$event->message_id}/reactions/reaction_typing_1"));
    }

    public function test_empty_success_uses_a_card_placeholder_and_failed_run_keeps_plain_text_reply(): void
    {
        $renderer = app(FeishuMessageRenderer::class);
        $emptyCard = $renderer->renderAiReplyCard('   ');
        $this->assertSame(
            '查询完成，但没有可展示的结果。',
            data_get($emptyCard, 'elements.0.text.content'),
        );

        $user = $this->userWithRole('business');
        $event = FeishuInboundEvent::factory()->create([
            'tenant_key' => 'test-tenant',
            'sender_open_id' => 'ou_failed_query_user',
            'status' => 'processing',
            'payload' => [
                'event' => [
                    'message' => [
                        'chat_type' => 'group',
                        'chat_id' => 'oc_failed_group_query',
                    ],
                ],
            ],
        ]);
        $run = AiRun::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'client_request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'failed-feishu-query'),
            'status' => 'failed',
            'origin' => 'feishu',
            'channel_context' => ['inbound_event_id' => $event->id],
            'input' => '查询项目',
            'artifacts' => [],
            'sources' => [],
            'provenance' => [],
            'data_quality' => [],
            'failure_category' => 'provider_error',
        ]);
        $event->update(['ai_run_id' => $run->id]);
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_test_failed_ai_reply'],
            ]),
        ]);

        (new SendFeishuAiReply($run->id))->handle(
            app(FeishuClient::class),
            $renderer,
            app(FeishuProcessingReaction::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/im/v1/messages')
            && str_contains($request->url(), 'receive_id_type=chat_id')
            && $request['receive_id'] === 'oc_failed_group_query'
            && $request['msg_type'] === 'text'
            && str_contains((string) $request['content'], '本次查询暂时失败'));
    }

    public function test_callback_rejects_invalid_identity_and_ignores_unbound_group_or_non_text_messages(): void
    {
        $invalid = $this->messagePayload('event-invalid', 'ou_unknown', '查询项目');
        $invalid['header']['token'] = 'wrong';
        $this->postJson('/webhooks/feishu/events', $invalid)->assertForbidden();
        $this->assertDatabaseCount('feishu_inbound_events', 0);

        $group = $this->messagePayload('event-group', 'ou_unknown', '查询项目');
        $group['event']['message']['chat_type'] = 'group';
        $this->postJson('/webhooks/feishu/events', $group)->assertOk();
        $event = FeishuInboundEvent::sole();
        Http::fake();
        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );
        $this->assertSame('ignored', $event->fresh()->status);
        $this->assertDatabaseCount('ai_runs', 0);
        Http::assertNothingSent();

        $challenge = ['token' => 'test-verification-token', 'challenge' => 'challenge-value'];
        $this->postJson('/webhooks/feishu/events', $challenge)->assertOk()->assertJson([
            'challenge' => 'challenge-value',
        ]);
    }

    public function test_unbound_private_query_does_not_add_processing_reaction(): void
    {
        $payload = $this->messagePayload('event-unbound-reaction', 'ou_unbound', '查询项目');
        $this->postJson('/webhooks/feishu/events', $payload)->assertOk();
        $event = FeishuInboundEvent::sole();
        Http::fake();

        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );

        $this->assertSame('rejected', $event->fresh()->status);
        $this->assertSame('user_not_bound', $event->fresh()->error);
        $this->assertNull($event->fresh()->processing_reaction_id);
        $this->assertDatabaseCount('ai_runs', 0);
        Http::assertNothingSent();
    }

    public function test_group_mention_without_chat_id_is_ignored_before_reaction_or_ai_run(): void
    {
        $user = $this->userWithRole('business');
        FeishuUserBinding::factory()->for($user)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_group_without_chat_id',
        ]);
        $payload = $this->messagePayload('event-group-without-chat-id', 'ou_group_without_chat_id', '@_user_1 查询项目');
        $payload['event']['message']['chat_type'] = 'group';
        $payload['event']['message']['mentions'] = [[
            'key' => '@_user_1',
            'name' => 'Palantir 助理',
            'id' => ['open_id' => 'ou_bot'],
        ]];
        $this->postJson('/webhooks/feishu/events', $payload)->assertOk();
        $event = FeishuInboundEvent::sole();
        Http::fake();

        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );

        $this->assertSame('ignored', $event->fresh()->status);
        $this->assertNull($event->fresh()->processing_reaction_id);
        $this->assertDatabaseCount('ai_runs', 0);
        Http::assertNothingSent();
    }

    public function test_group_mention_runs_the_full_private_ai_reply_and_reaction_lifecycle(): void
    {
        $user = $this->userWithRole('business');
        FeishuUserBinding::factory()->for($user)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_group_task_owner',
        ]);
        $payload = $this->messagePayload('event-group-mention', 'ou_group_task_owner', '@_user_1 查询当前欠款');
        $payload['event']['message']['chat_type'] = 'group';
        $payload['event']['message']['chat_id'] = 'oc_group_query';
        $payload['event']['message']['mentions'] = [[
            'key' => '@_user_1',
            'name' => 'Palantir 助理',
            'id' => ['open_id' => 'ou_bot'],
        ]];

        $this->postJson('/webhooks/feishu/events', $payload)->assertOk()->assertJson(['code' => 0]);
        $event = FeishuInboundEvent::sole();
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages/*/reactions' => Http::response([
                'code' => 0, 'data' => ['reaction_id' => 'reaction_group_typing'],
            ]),
        ]);

        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );

        $event->refresh();
        $run = $event->aiRun;
        $this->assertNotNull($run);
        $this->assertSame('查询当前欠款', $run->input);
        $this->assertSame('reaction_group_typing', $event->processing_reaction_id);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), "/im/v1/messages/{$event->message_id}/reactions")
            && data_get($request->data(), 'reaction_type.emoji_type') === 'Typing');

        $stream = new StreamableAgentResponse((string) Str::uuid7(), function () {
            yield new TextDelta(
                id: (string) Str::uuid7(),
                messageId: (string) Str::uuid7(),
                delta: '当前可见项目欠款合计 **75,000 元**。',
                timestamp: time(),
            );
        }, new Meta(provider: 'fake', model: 'fake-model'));
        $agent = Mockery::mock(FeishuDataAgent::class);
        $agent->shouldReceive('continue')->once()->andReturnSelf();
        $agent->shouldReceive('stream')->once()->andReturn($stream);
        $this->app->bind(FeishuDataAgent::class, fn (): FeishuDataAgent => $agent);
        (new RunAiHarness($run->id))->handle(
            app(AiRunEventPublisher::class),
            app(AiToolEventProjector::class),
            app(AiFailureClassifier::class),
        );

        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages/*/reactions/*' => Http::response([
                'code' => 0, 'data' => [],
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_group_private_reply'],
            ]),
        ]);
        (new SendFeishuAiReply($run->id))->handle(
            app(FeishuClient::class),
            app(FeishuMessageRenderer::class),
            app(FeishuProcessingReaction::class),
        );

        $event->refresh();
        $this->assertSame('completed', $event->status);
        $this->assertSame('om_group_private_reply', $event->reply_message_id);
        $this->assertNotNull($event->processing_reaction_removed_at);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'receive_id_type=chat_id')
            && $request['receive_id'] === 'oc_group_query'
            && $request['msg_type'] === 'interactive');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'receive_id_type=open_id')
            && $request['receive_id'] === 'ou_group_task_owner');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), "/im/v1/messages/{$event->message_id}/reactions/reaction_group_typing"));
    }

    public function test_processing_reaction_failure_does_not_block_authorized_ai_run(): void
    {
        $user = $this->userWithRole('business');
        FeishuUserBinding::factory()->for($user)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_reaction_failure',
        ]);
        $payload = $this->messagePayload('event-reaction-failure', 'ou_reaction_failure', '查询项目');
        $this->postJson('/webhooks/feishu/events', $payload)->assertOk();
        $event = FeishuInboundEvent::sole();
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages/*/reactions' => Http::response([
                'code' => 230001, 'msg' => 'reaction denied',
            ], 403),
        ]);

        (new ProcessFeishuInboundEvent($event->id))->handle(
            app(CreateFeishuAiRun::class),
            app(FeishuProcessingReaction::class),
        );

        $event->refresh();
        $this->assertSame('processing', $event->status);
        $this->assertNotNull($event->ai_run_id);
        $this->assertNull($event->processing_reaction_id);
        $this->assertSame('reaction_create_failed', $event->processing_reaction_error);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_operator_can_bind_a_user_with_a_known_open_id_without_external_lookup(): void
    {
        $user = User::factory()->create(['email' => 'binding@example.test']);

        $this->artisan('feishu:bind-user', [
            'email' => 'binding@example.test',
            '--open-id' => 'ou_bound_by_operator',
            '--tenant-key' => 'test-tenant',
        ])->assertSuccessful();

        $this->assertDatabaseHas('feishu_user_bindings', [
            'user_id' => $user->id,
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_bound_by_operator',
        ]);
        Http::assertNothingSent();
    }

    public function test_unbound_delivery_is_skipped_without_calling_feishu(): void
    {
        $user = User::factory()->create();
        $delivery = NotificationDelivery::factory()->for($user)->create();

        (new DeliverFeishuNotification($delivery->id))->handle(
            app(FeishuClient::class),
            app(FeishuMessageRenderer::class),
        );

        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $delivery->fresh()->status);
        $this->assertSame('recipient_not_bound', $delivery->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_tender_deadline_test_data_creates_a_feishu_delivery_without_changing_station_notification(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $owner = $this->userWithRole('tender');
        FeishuUserBinding::factory()->for($owner)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_tender_owner',
        ]);
        $tender = BusinessObject::where('key', 'tender')->firstOrFail()->records()->create([
            'code' => 'TND-'.Str::uuid(),
            'title' => '飞书招投标测试',
            'created_by' => $owner->id,
            'payload' => [
                'name' => '飞书招投标测试',
                'status' => '跟踪中',
                'register_deadline' => now()->addHours(20)->toISOString(),
            ],
        ]);

        $result = app(SyncTenderNotifications::class)->handle();

        $this->assertGreaterThanOrEqual(1, $result['created']);
        $notification = TenderNotification::where('tender_id', $tender->id)->where('user_id', $owner->id)->sole();
        $this->assertSame(TenderNotification::STATUS_ACTIVE, $notification->status);
        $this->assertDatabaseHas('notification_deliveries', [
            'source_type' => 'tender_notification',
            'source_id' => (string) $notification->id,
            'user_id' => $owner->id,
            'occurrence' => 1,
            'status' => NotificationDelivery::STATUS_PENDING,
        ]);
        $delivery = NotificationDelivery::where('source_type', 'tender_notification')
            ->where('source_id', (string) $notification->id)
            ->sole();
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 0, 'data' => ['message_id' => 'om_test_tender'],
            ]),
        ]);

        (new DeliverFeishuNotification($delivery->id))->handle(
            app(FeishuClient::class),
            app(FeishuMessageRenderer::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/im/v1/messages')
            && $request['msg_type'] === 'text'
            && str_contains((string) $request['content'], 'Palantir 招投标提醒'));
    }

    public function test_disabled_integration_records_a_skip_and_terminal_api_errors_are_sanitized(): void
    {
        $owner = $this->userWithRole('business');
        $project = $this->project($owner, [
            'overall_status' => '合同签署',
            'payment_status' => '部分回款',
        ]);
        $notification = ProjectNotification::create([
            'project_id' => $project->id,
            'type' => ProjectNotification::TYPE_PAYMENT,
            'user_id' => $owner->id,
            'status' => ProjectNotification::STATUS_ACTIVE,
            'triggered_at' => now(),
            'occurrences' => 1,
        ]);
        config()->set('services.feishu.enabled', false);
        $skipped = app(FeishuNotificationDispatcher::class)->dispatch($notification);
        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $skipped->status);

        config()->set('services.feishu.enabled', true);
        FeishuUserBinding::factory()->for($owner)->create([
            'tenant_key' => 'test-tenant',
            'open_id' => 'ou_error_target',
        ]);
        $notification->update(['occurrences' => 2]);
        $delivery = app(FeishuNotificationDispatcher::class)->dispatch($notification);
        Http::fake([
            'https://open.feishu.test/open-apis/auth/v3/tenant_access_token/internal' => Http::response([
                'code' => 0, 'tenant_access_token' => 'fake-token', 'expire' => 7200,
            ]),
            'https://open.feishu.test/open-apis/im/v1/messages*' => Http::response([
                'code' => 50001, 'msg' => 'api_secret=must-not-leak; upstream rejected',
            ], 500),
        ]);
        $job = new DeliverFeishuNotification($delivery->id);

        try {
            $job->handle(
                app(FeishuClient::class),
                app(FeishuMessageRenderer::class),
            );
            $this->fail('Expected the Feishu client to reject the API response.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringNotContainsString('must-not-leak', (string) $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $eventId, string $openId, string $text): array
    {
        return [
            'schema' => '2.0',
            'header' => [
                'event_id' => $eventId,
                'event_type' => 'im.message.receive_v1',
                'app_id' => 'test-app-id',
                'tenant_key' => 'test-tenant',
                'token' => 'test-verification-token',
            ],
            'event' => [
                'sender' => ['sender_type' => 'user', 'sender_id' => ['open_id' => $openId]],
                'message' => [
                    'message_id' => 'om_'.Str::random(8),
                    'chat_type' => 'p2p',
                    'message_type' => 'text',
                    'content' => json_encode(['text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];
    }

    private function project(User $owner, array $overrides): ObjectRecord
    {
        $projectObject = BusinessObject::where('key', 'project')->firstOrFail();

        return $projectObject->records()->create([
            'code' => 'PRJ-'.Str::uuid(),
            'title' => '飞书全链路测试项目',
            'created_by' => $owner->id,
            'payload' => [
                'name' => '飞书全链路测试项目',
                'business_owner_user_id' => (string) $owner->id,
                'collection_count' => 0,
                ...$overrides,
            ],
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => "飞书测试{$role}",
            'email' => $role.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
        ]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
