<?php

namespace App\Integrations\Feishu;

use App\Models\NotificationDelivery;
use App\Models\ProjectNotification;
use App\Models\TenderNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class FeishuMessageRenderer
{
    private const BATCH_VISIBLE_LIMIT = 20;

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
            'elements' => $this->aiReplyElements(
                $content === '' ? '查询完成，但没有可展示的结果。' : $content,
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function aiReplyElements(string $content): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $elements = [];
        $paragraph = [];

        $flushParagraph = function () use (&$elements, &$paragraph): void {
            $text = trim(implode("\n", $paragraph));
            if ($text !== '') {
                $elements[] = $this->textElement($text);
            }
            $paragraph = [];
        };

        for ($index = 0; $index < count($lines); $index++) {
            $line = trim($lines[$index]);
            if ($line === '') {
                $flushParagraph();

                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $heading) === 1) {
                $flushParagraph();
                $elements[] = $this->textElement('**'.trim($heading[1]).'**');

                continue;
            }

            if ($this->startsMarkdownTable($lines, $index)) {
                $flushParagraph();
                [$tableElements, $index] = $this->parseMarkdownTable($lines, $index);
                array_push($elements, ...$tableElements);

                continue;
            }

            $paragraph[] = $line;
        }

        $flushParagraph();

        return $elements === [] ? [$this->textElement('查询完成，但没有可展示的结果。')] : $elements;
    }

    private function startsMarkdownTable(array $lines, int $index): bool
    {
        if (! isset($lines[$index + 1]) || ! Str::contains($lines[$index], '|')) {
            return false;
        }

        $separator = trim($lines[$index + 1]);

        return preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/u', $separator) === 1;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: int} */
    private function parseMarkdownTable(array $lines, int $start): array
    {
        $headers = $this->tableCells($lines[$start]);
        $rows = [];
        $index = $start + 2;
        while (isset($lines[$index]) && Str::contains($lines[$index], '|') && trim($lines[$index]) !== '') {
            $rows[] = $this->tableCells($lines[$index]);
            $index++;
        }

        $elements = [];
        foreach ($rows as $rowIndex => $row) {
            $fields = [];
            foreach ($headers as $column => $header) {
                $value = trim((string) ($row[$column] ?? '—'));
                $fields[] = [
                    'is_short' => $column !== 0,
                    'text' => [
                        'tag' => 'lark_md',
                        'content' => '**'.trim($header)."**\n".($value !== '' ? $value : '—'),
                    ],
                ];
            }
            $elements[] = ['tag' => 'div', 'fields' => $fields];
            if ($rowIndex < count($rows) - 1) {
                $elements[] = ['tag' => 'hr'];
            }
        }

        return [$elements, $index - 1];
    }

    /** @return string[] */
    private function tableCells(string $line): array
    {
        return collect(explode('|', trim($line, " \t|")))
            ->map(fn (string $cell): string => trim($cell))
            ->all();
    }

    /** @return array{tag: string, text: array{tag: string, content: string}} */
    private function textElement(string $content): array
    {
        return [
            'tag' => 'div',
            'text' => ['tag' => 'lark_md', 'content' => $content],
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

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     * @return array<string, mixed>
     */
    public function renderBatchCard(Collection $deliveries): array
    {
        $total = $deliveries->count();
        $visible = $deliveries->take(self::BATCH_VISIBLE_LIMIT)
            ->values()
            ->map(fn (NotificationDelivery $delivery, int $index): string => $this->batchLine($delivery, $index + 1))
            ->implode("\n\n");
        $remaining = $total - min($total, self::BATCH_VISIBLE_LIMIT);
        if ($remaining > 0) {
            $visible .= "\n\n还有 **{$remaining}** 项未展开，请进入通知中心查看。";
        }

        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'orange',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => "Palantir · 待办提醒汇总（{$total} 项）",
                ],
            ],
            'elements' => [
                [
                    'tag' => 'div',
                    'text' => ['tag' => 'lark_md', 'content' => $visible],
                ],
                [
                    'tag' => 'action',
                    'actions' => [[
                        'tag' => 'button',
                        'type' => 'primary',
                        'text' => ['tag' => 'plain_text', 'content' => '查看通知中心'],
                        'url' => route('notifications.index'),
                    ]],
                ],
            ],
        ];
    }

    private function batchLine(NotificationDelivery $delivery, int $index): string
    {
        if ($delivery->source_type === 'project_notification') {
            $notification = ProjectNotification::with('project')->findOrFail($delivery->source_id);
            $project = $notification->project;
            $payload = $project?->payload ?? [];
            $labels = [
                ProjectNotification::TYPE_BID => '投标跟进',
                ProjectNotification::TYPE_PROCESSING_LETTER => '加工函跟进',
                ProjectNotification::TYPE_SIGNATURE => '合同签署',
                ProjectNotification::TYPE_PAYMENT => '项目回款',
            ];
            $title = $this->escapeMarkdown($project?->title ?? (string) $notification->project_id);
            $detailUrl = route('objects.index', [
                'object' => 'project',
                'record' => $project?->id ?? $notification->project_id,
                'mode' => 'detail',
            ]);
            $line = "**{$index}. [{$title}]({$detailUrl}) · ".($labels[$notification->type] ?? '项目提醒')."**\n";
            $line .= '状态：'.$this->escapeMarkdown((string) data_get($payload, 'overall_status', '待补充'));
            if ($notification->type === ProjectNotification::TYPE_PAYMENT) {
                $businessOwnerId = filter_var(data_get($payload, 'business_owner_user_id'), FILTER_VALIDATE_INT);
                $businessOwner = $businessOwnerId ? User::query()->whereKey($businessOwnerId)->value('name') : null;
                $progress = data_get($payload, 'payment_progress');
                $outstanding = data_get($payload, 'unpaid_amount', data_get($payload, 'arrears'));
                $line .= '｜业务员：'.$this->escapeMarkdown($businessOwner ?: '待补充');
                $line .= '｜回款进度：'.(is_numeric($progress) ? Number::percentage((float) $progress, maxPrecision: 2, locale: 'zh_CN') : '待补充');
                $line .= '｜欠款：'.(is_numeric($outstanding) ? Number::currency((float) $outstanding, in: 'CNY', locale: 'zh_CN') : '待补充');
            }

            return $line.'｜提醒次数：'.$delivery->occurrence;
        }

        $notification = TenderNotification::with('tender')->findOrFail($delivery->source_id);
        $title = $this->escapeMarkdown($notification->tender?->title ?? (string) $notification->tender_id);

        return "**{$index}. {$title} · 招投标提醒**\n节点："
            .$this->escapeMarkdown($notification->deadline_type)
            .'｜阶段：'.$this->escapeMarkdown($notification->stage)
            .'｜截止：'.($notification->deadline_at?->format('Y-m-d H:i') ?? '待补充');
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
