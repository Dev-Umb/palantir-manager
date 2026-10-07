<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\CollectionProgress;
use App\Support\ProjectVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildVisualization
{
    public function __construct(private ProjectVisibility $visibility, private CollectionProgress $collectionProgress) {}

    public function handle(User $user): array
    {
        $permissions = $user->permissionKeys();
        $read = function (string $key) use ($user, $permissions): ?Collection {
            if (! in_array("object.{$key}.view", $permissions, true)) {
                return null;
            }
            $object = BusinessObject::where('key', $key)->first();

            return $object ? $this->visibility->scopeRecords($object->records(), $object, $user)->get() : collect();
        };
        $projects = $read('project');
        $data = [
            'scope' => $user->roles->contains('name', 'admin') ? '公司全量' : '我的可见范围',
            'as_of' => now()->timezone('Asia/Taipei')->format('Y-m-d H:i'),
            'details_url' => $projects !== null ? route('objects.index', ['object' => 'project']) : null,
            'collection' => null,
            'projects' => null,
        ];
        if ($projects !== null) {
            $summary = $this->collectionProgress->summarize($projects);
            $occurred = $summary['occurred_amount'];
            $paid = $summary['paid_amount'];
            $data['collection'] = [
                'occurred' => $summary['covered_records'] ? $occurred : null,
                'paid' => $summary['covered_records'] ? $paid : null,
                'remaining' => $summary['covered_records'] ? $occurred - $paid : null,
                'ratio' => $summary['ratio'],
                'chartable' => $occurred > 0 && $paid >= 0 && $paid <= $occurred,
                'coverage' => $summary['covered_records'].'/'.$summary['total_records'],
            ];
        }
        if ($projects === null) {
            return $data;
        }
        $owners = User::whereKey($projects->pluck('payload.business_owner_user_id')->filter()->unique())->pluck('name', 'id');
        $rows = $projects->map(fn (ObjectRecord $r): array => [
            'id' => $r->id,
            'name' => $r->payload['name'] ?? $r->title,
            'code' => $r->payload['project_no'] ?? $r->code,
            'salesperson' => $owners->get($r->payload['business_owner_user_id'] ?? '', '业务员未维护'),
            'occurred' => $this->amount($r->payload['occurred_amount'] ?? null),
            'paid' => $this->amount($r->payload['paid_amount'] ?? null),
            'unpaid' => $this->amount($r->payload['unpaid_amount'] ?? null),
            'last_payment_date' => $r->payload['last_payment_date'] ?? null,
            'stage' => trim((string) ($r->payload['overall_status'] ?? '')) ?: '状态未维护',
            'status' => $r->payload['overall_status'] ?? '状态未维护',
        ]);
        $metrics = ['occurred', 'paid', 'unpaid'];
        $totals = [];
        foreach ($metrics as $metric) {
            $values = $rows->pluck($metric)->filter(fn ($v): bool => $v !== null);
            $totals[$metric] = [
                'value' => $values->isEmpty() ? null : round($values->sum(), 2),
                'coverage' => $values->count().'/'.$rows->count(),
                'abnormal' => $values->filter(fn ($v): bool => $v < 0)->count(),
            ];
        }
        $salespeople = $rows->groupBy('salesperson')->map(function (Collection $group, string $name) use ($metrics): array {
            $item = ['name' => $name];
            foreach ($metrics as $metric) {
                $values = $group->pluck($metric)->filter(fn ($v): bool => $v !== null);
                $item[$metric] = $values->isEmpty() ? null : round($values->sum(), 2);
            }

            return $item;
        })->sortBy('name')->values();
        $active = $rows->whereIn('status', ['投标中', '已中标', '已拿到加工函', '合同签署']);
        $inactive = $rows->whereNotIn('status', ['投标中', '已中标', '已拿到加工函', '合同签署'])->countBy('status');
        $data['projects'] = [
            'totals' => $totals,
            'salespeople' => $salespeople,
            'top_unpaid' => $rows->filter(fn (array $r): bool => $r['unpaid'] !== null && $r['unpaid'] > 0)
                ->sort(fn (array $a, array $b): int => ($b['unpaid'] <=> $a['unpaid']) ?: strcmp($a['id'], $b['id']))->take(5)->values(),
            'stages' => $active->countBy('stage')->map(fn (int $value, string $name): array => compact('name', 'value'))->values(),
            'active_count' => $active->count(),
            'inactive' => $inactive->map(fn (int $value, string $name): array => compact('name', 'value'))->values(),
            'aging' => $rows->filter(fn (array $r): bool => $r['unpaid'] !== null && $r['unpaid'] > 0)
                ->map(function (array $row): array {
                    $row['days'] = $this->elapsedDays($row['last_payment_date']);

                    return $row;
                })->filter(fn (array $r): bool => $r['days'] !== null)
                ->sort(fn (array $a, array $b): int => ($b['days'] <=> $a['days']) ?: strcmp($a['id'], $b['id']))->values(),
            'aging_basis' => '仅统计已维护有效最后回款日期的欠款项目',
            'aging_excluded' => $rows->filter(fn (array $r): bool => $r['unpaid'] !== null && $r['unpaid'] > 0 && $this->elapsedDays($r['last_payment_date']) === null)->count(),
        ];

        return $data;
    }

    private function elapsedDays(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Asia/Taipei');
        } catch (\Throwable) {
            return null;
        }
        $today = Carbon::now('Asia/Taipei')->startOfDay();
        if (! $date || $date->format('Y-m-d') !== $value || $date->greaterThan($today)) {
            return null;
        }

        return (int) $date->diffInDays($today);
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 2) : null;
    }
}
