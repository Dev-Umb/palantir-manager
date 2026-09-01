<?php

namespace App\Actions;

use App\Integrations\Feishu\FeishuNotificationDispatcher;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\Role;
use App\Models\TenderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncTenderNotifications
{
    public function __construct(private FeishuNotificationDispatcher $feishu) {}

    private const DEADLINES = [
        'register' => 'register_deadline',
        'purchase' => 'purchase_deadline',
        'submit' => 'submit_deadline',
    ];

    private const PASSED_STATUSES = [
        'register' => ['已报名', '已购标书', '制作中', '已递交', '已中标', '未中标', '已放弃'],
        'purchase' => ['已购标书', '制作中', '已递交', '已中标', '未中标', '已放弃'],
        'submit' => ['已递交', '已中标', '未中标', '已放弃'],
    ];

    /** @return array{created: int, reactivated: int, resolved: int} */
    public function handle(?Carbon $at = null): array
    {
        $now = ($at ?? now())->copy()->setTimezone(config('xyc.tender_timezone'));

        return DB::transaction(function () use ($now): array {
            $tenderObject = BusinessObject::query()->where('key', 'tender')->lockForUpdate()->first();
            if (! $tenderObject) {
                return $this->emptySummary();
            }

            $roleRecipients = Role::query()
                ->whereIn('name', ['tender', 'admin'])
                ->with('users:id')
                ->get()
                ->flatMap(fn (Role $role) => $role->users->pluck('id'))
                ->unique()
                ->values();
            $tenders = $tenderObject->records()->orderBy('id')->lockForUpdate()->get();

            /** @var array<string, array<string, mixed>> $desired */
            $desired = [];
            foreach ($tenders as $tender) {
                $payload = $tender->payload ?? [];
                $status = (string) ($payload['status'] ?? '跟踪中');
                $recipients = collect($roleRecipients);
                if ($tender->created_by) {
                    $recipients->push($tender->created_by);
                }
                $recipients = $recipients->unique()->values();

                foreach (self::DEADLINES as $deadlineType => $field) {
                    if (in_array($status, self::PASSED_STATUSES[$deadlineType], true)) {
                        continue;
                    }

                    $deadline = $this->deadline((string) ($payload[$field] ?? ''));
                    $stage = $deadline ? $this->stageFor($deadline, $now) : null;
                    if (! $stage) {
                        continue;
                    }

                    foreach ($recipients as $userId) {
                        $key = $this->key($tender->id, $deadlineType, $stage, $userId);
                        $desired[$key] = [
                            'tender_id' => $tender->id,
                            'type' => TenderNotification::TYPE_DEADLINE,
                            'deadline_type' => $deadlineType,
                            'stage' => $stage,
                            'project_id' => null,
                            'user_id' => $userId,
                            'deadline_at' => $deadline,
                        ];
                    }
                }
            }

            $existing = TenderNotification::query()
                ->where('type', TenderNotification::TYPE_DEADLINE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (TenderNotification $notification) => $this->key(
                    $notification->tender_id,
                    $notification->deadline_type,
                    $notification->stage,
                    $notification->user_id,
                ));

            $summary = $this->emptySummary();
            foreach ($desired as $key => $attributes) {
                $notification = $existing->get($key);
                if (! $notification) {
                    $notification = TenderNotification::create([
                        ...$attributes,
                        'status' => TenderNotification::STATUS_ACTIVE,
                        'triggered_at' => $now,
                        'occurrences' => 1,
                    ]);
                    $summary['created']++;
                    $this->audit('tender_notification.created', $notification);
                    $this->feishu->dispatch($notification);

                    continue;
                }

                if ($notification->status === TenderNotification::STATUS_RESOLVED) {
                    $notification->update([
                        'status' => TenderNotification::STATUS_ACTIVE,
                        'deadline_at' => $attributes['deadline_at'],
                        'read_at' => null,
                        'resolved_at' => null,
                        'triggered_at' => $now,
                        'occurrences' => $notification->occurrences + 1,
                    ]);
                    $summary['reactivated']++;
                    $this->audit('tender_notification.reactivated', $notification);
                    $this->feishu->dispatch($notification);
                }
            }

            foreach ($existing as $key => $notification) {
                if (isset($desired[$key]) || $notification->status !== TenderNotification::STATUS_ACTIVE) {
                    continue;
                }

                $notification->update([
                    'status' => TenderNotification::STATUS_RESOLVED,
                    'resolved_at' => $now,
                ]);
                $summary['resolved']++;
                $this->audit('tender_notification.resolved', $notification);
            }

            return $summary;
        });
    }

    public function stageFor(Carbon $deadline, Carbon $now): ?string
    {
        if ($deadline->lte($now)) {
            return null;
        }

        if ($deadline->isSameDay($now)) {
            return 'd0';
        }

        $minutes = $now->diffInMinutes($deadline);
        if ($minutes <= 24 * 60) {
            return 'd1';
        }

        return $minutes <= 72 * 60 ? 'd3' : null;
    }

    private function deadline(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, config('xyc.tender_timezone'));
    }

    /** @return array{created: int, reactivated: int, resolved: int} */
    private function emptySummary(): array
    {
        return ['created' => 0, 'reactivated' => 0, 'resolved' => 0];
    }

    private function key(string $tenderId, string $deadlineType, string $stage, int $userId): string
    {
        return "{$tenderId}|{$deadlineType}|{$stage}|{$userId}";
    }

    private function audit(string $action, TenderNotification $notification): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => 'tender_notification',
            'subject_id' => (string) $notification->id,
            'payload' => [
                'tender_id' => $notification->tender_id,
                'deadline_type' => $notification->deadline_type,
                'stage' => $notification->stage,
                'recipient_id' => $notification->user_id,
                'occurrences' => $notification->occurrences,
            ],
        ]);
    }
}
