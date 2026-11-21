<?php

namespace App\Support;

use App\Models\ReimbursementNotice;
use App\Models\ReimbursementVersion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Renders the two OA reimbursement subscribe messages.
 *
 * Values are produced from the reimbursement version at send time; template
 * field keys come from config/wechat.php so console keyword changes stay a
 * configuration concern.
 */
class ReimbursementWechatMessage
{
    public const APPROVAL = 'reimbursement_approval';

    public const RESULT = 'reimbursement_result';

    public static function templateKeyFor(ReimbursementNotice $notice): ?string
    {
        if (str_starts_with($notice->event, 'approval:')) {
            return self::APPROVAL;
        }

        return str_starts_with($notice->event, 'progress:') || in_array($notice->event, ['approved', 'rejected'], true) ? self::RESULT : null;
    }

    /** @return array{id: string, label: string, page: string, fields: array<string, string>}|null */
    public static function templateFor(ReimbursementNotice $notice): ?array
    {
        $key = self::templateKeyFor($notice);
        if ($key === null) {
            return null;
        }
        $template = config('wechat.notify.templates.'.$key);
        if (! is_array($template) || ! filled($template['id'] ?? null)) {
            return null;
        }

        return [
            'id' => (string) $template['id'],
            'label' => (string) ($template['label'] ?? ''),
            'page' => (string) ($template['page'] ?? ''),
            'fields' => is_array($template['fields'] ?? null) ? $template['fields'] : [],
        ];
    }

    /** @param array{id: string, label: string, page: string, fields: array<string, string>} $template
     * @return array<string, array{value: string}>
     */
    public static function dataFor(ReimbursementNotice $notice, ReimbursementVersion $version, array $template): array
    {
        $data = [];
        foreach ($template['fields'] as $field => $source) {
            $value = self::valueFor($notice, $version, (string) $source, (string) $field);
            if ($value !== '') {
                $data[$field] = ['value' => $value];
            }
        }

        return $data;
    }

    /** @param array{id: string, label: string, page: string, fields: array<string, string>} $template */
    public static function pageFor(ReimbursementVersion $version, array $template): ?string
    {
        $page = trim($template['page']);
        if ($page === '') {
            return null;
        }

        return str_replace('{id}', (string) $version->id, $page);
    }

    private static function valueFor(ReimbursementNotice $notice, ReimbursementVersion $version, string $source, string $field): string
    {
        $value = match ($source) {
            'type' => (string) $version->type,
            'owner' => (string) (User::withTrashed()->find($version->user_id)?->name ?? '已删除用户'),
            'amount' => number_format($version->total_cents / 100, 2, '.', ''),
            'submitted_at' => self::time($version->created_at),
            'status' => match ($notice->event) {
                'approved' => '审批通过', 'rejected' => '已驳回', default => '审批中'
            },
            'title' => (string) $version->title,
            'time' => self::time($notice->created_at ?? now()),
            'reason' => $notice->event === 'rejected' ? self::reason($version) : '无',
            default => '',
        };

        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return self::limit($field, $value);
    }

    private static function reason(ReimbursementVersion $version): string
    {
        if ($version->status !== 'rejected') {
            return '无';
        }
        $last = collect($version->decisions)->last();
        $note = is_array($last) ? trim((string) ($last['note'] ?? '')) : '';

        return $note !== '' ? $note : '未填写原因';
    }

    private static function time(?Carbon $time): string
    {
        if (! $time instanceof Carbon) {
            return '';
        }

        return $time->copy()->setTimezone(config('xyc.tender_timezone', 'Asia/Shanghai'))->format('Y年n月j日 H:i');
    }

    private static function limit(string $field, string $value): string
    {
        $type = strtolower((string) preg_replace('/\d+$/', '', $field));

        return match ($type) {
            'thing' => mb_substr($value, 0, 20),
            'phrase' => mb_substr($value, 0, 5),
            'amount' => preg_replace('/[^0-9.]/', '', $value) ?: '',
            'character_string' => mb_substr($value, 0, 32),
            'time', 'date' => $value,
            default => mb_substr($value, 0, 20),
        };
    }
}
