<?php

namespace App\Integrations\Feishu;

use App\Models\NotificationDelivery;
use App\Models\ProjectNotification;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Support\Number;

class FeishuMessageRenderer
{
    /** @return array<string, mixed> */
    public function renderAiReplyCard(string $markdown): array
    {
        $content = trim($markdown);

        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'blue',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => 'Palantir · 项目查询',
                ],
            ],
            'elements' => [[
                'tag' => 'div',
                'text' => [
                    'tag' => 'lark_md',
                    'content' => $content === '' ? '查询完成，但没有可展示的结果。' : $content,
                ],
            ]],
        ];
    }

    /** @return array<string, mixed>|null */
    public function renderCard(NotificationDelivery $delivery): ?array
    {
        if ($delivery->source_type !== 'project_notification') {
            return null;
        }

        $notification = ProjectNotification::with('project')->findOrFail($delivery->source_id);
        if ($notification->type !== ProjectNotification::TYPE_PAYMENT) {
            return null;
        }

        $project = $notification->project;
        $payload = $project?->payload ?? [];
        $businessOwnerId = filter_var(data_get($payload, 'business_owner_user_id'), FILTER_VALIDATE_INT);
        $businessOwner = $businessOwnerId
            ? User::query()->whereKey($businessOwnerId)->value('name')
            : null;
        $paymentProgress = data_get($payload, 'payment_progress');
        $outstandingAmount = data_get($payload, 'unpaid_amount', data_get($payload, 'arrears'));
        $detailUrl = route('objects.index', [
            'object' => 'project',
            'record' => $project?->id ?? $notification->project_id,
            'mode' => 'detail',
        ]);

        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'orange',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => $project?->title ?? (string) $notification->project_id,
                ],
            ],
            'elements' => [
                [
                    'tag' => 'div',
                    'text' => [
                        'tag' => 'lark_md',
                        'content' => '**Palantir · 项目回款提醒**',
                    ],
                ],
                [
                    'tag' => 'div',
                    'fields' => [
                        $this->field('负责业务员', $businessOwner ?: '待补充'),
                        $this->field('项目状态', (string) data_get($payload, 'overall_status', '待补充')),
                        $this->field('回款状态', (string) data_get($payload, 'payment_status', '待补充')),
                        $this->field('回款进度', is_numeric($paymentProgress)
                            ? Number::percentage((float) $paymentProgress, maxPrecision: 2, locale: 'zh_CN')
                            : '待补充'),
                        $this->field('欠款金额', is_numeric($outstandingAmount)
                            ? Number::currency((float) $outstandingAmount, in: 'CNY', locale: 'zh_CN')
                            : '待补充'),
                        $this->field('提醒次数', (string) $delivery->occurrence),
                    ],
                ],
                [
                    'tag' => 'action',
                    'actions' => [[
                        'tag' => 'button',
                        'type' => 'primary',
                        'text' => ['tag' => 'plain_text', 'content' => '查看项目详情'],
                        'url' => $detailUrl,
                    ]],
                ],
            ],
        ];
    }

    public function render(NotificationDelivery $delivery): string
    {
        if ($delivery->source_type === 'project_notification') {
            $notification = ProjectNotification::with('project')->findOrFail($delivery->source_id);
            $labels = [
                ProjectNotification::TYPE_BID => '项目投标跟进提醒',
                ProjectNotification::TYPE_PROCESSING_LETTER => '项目加工函提醒',
                ProjectNotification::TYPE_SIGNATURE => '项目合同签署提醒',
                ProjectNotification::TYPE_PAYMENT => '项目回款提醒',
            ];

            return sprintf(
                "【Palantir %s】\n项目：%s\n状态：%s\n回款状态：%s\n提醒次数：%d",
                $labels[$notification->type] ?? '项目提醒',
                $notification->project?->title ?? $notification->project_id,
                data_get($notification->project?->payload, 'overall_status', '—'),
                data_get($notification->project?->payload, 'payment_status', '—'),
                $delivery->occurrence,
            );
        }

        $notification = TenderNotification::with('tender')->findOrFail($delivery->source_id);

        return sprintf(
            "【Palantir 招投标提醒】\n招投标：%s\n节点：%s（%s）\n截止时间：%s",
            $notification->tender?->title ?? $notification->tender_id,
            $notification->deadline_type,
            $notification->stage,
            $notification->deadline_at?->format('Y-m-d H:i') ?? '—',
        );
    }

    /** @return array{is_short: bool, text: array{tag: string, content: string}} */
    private function field(string $label, string $value): array
    {
        return [
            'is_short' => true,
            'text' => [
                'tag' => 'lark_md',
                'content' => "**{$label}**\n".$this->escapeMarkdown($value),
            ],
        ];
    }

    private function escapeMarkdown(string $value): string
    {
        return str_replace(
            ['\\', '*', '_', '`', '[', ']'],
            ['\\\\', '\\*', '\\_', '\\`', '\\[', '\\]'],
            $value,
        );
    }
}
