<?php

namespace App\Support;

use App\Models\TimebookEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TimebookQuery
{
    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['start']) ? ['after_or_equal:start'] : [])],
            'q' => ['nullable', 'string', 'max:40'],
            'worker' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return ['start' => $data['start'] ?? '', 'end' => $data['end'] ?? '',
            'q' => TimebookName::normalize($data['q'] ?? ''), 'worker' => $data['worker'] ?? ''];
    }

    public function records(array $filters): Builder
    {
        return TimebookEntry::query()->where('deleted', 0)
            ->when($filters['start'], fn ($q, $day) => $q->where('day', '>=', $day))
            ->when($filters['end'], fn ($q, $day) => $q->where('day', '<=', $day))
            ->when($filters['worker'], fn ($q, $worker) => $q->where('worker_id', $worker))
            ->when($filters['q'] !== '', fn ($q) => $q->whereHas('worker', fn ($workers) => $workers
                ->whereRaw("name_key LIKE ? ESCAPE '!'", [$this->pattern($filters['q'])])));
    }

    public function result(array $filters, int $page = 1, int $perPage = 12): array
    {
        return DB::transaction(function () use ($filters, $page, $perPage): array {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $query = $this->records($filters);
            $totals = (clone $query)->selectRaw('COUNT(*) AS count, COUNT(DISTINCT worker_id) AS people, COALESCE(SUM(half_days), 0) AS half_days, COALESCE(SUM(overtime_minutes), 0) AS overtime_minutes')->first();
            $summary = (clone $query)->with('worker')->select('worker_id')
                ->selectRaw('SUM(half_days) AS half_days, SUM(overtime_minutes) AS overtime_minutes, COUNT(*) AS count, MIN(day) AS first_day, MAX(day) AS last_day')
                ->groupBy('worker_id')->get()->map(fn ($row) => [
                    'worker_id' => $row->worker_id, 'name' => $row->worker->name,
                    ...$this->numbers($row->toArray()), 'first_day' => $row->first_day, 'last_day' => $row->last_day,
                ])->sort(fn ($a, $b) => ($b['half_days'] <=> $a['half_days']) ?: strcmp($a['name'], $b['name']))->values();
            $lastPage = max(1, (int) ceil($totals->count / $perPage));
            $page = min(max(1, $page), $lastPage);

            return [
                'records' => (clone $query)->with('worker')->orderByDesc('day')->orderByDesc('id')
                    ->forPage($page, $perPage)->get()->map->snapshot()->all(),
                'summary' => $summary->all(), 'totals' => [...$this->numbers($totals->toArray()), 'people' => (int) $totals->people],
                'pagination' => ['page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => (int) $totals->count],
                'filters' => $filters, 'queried_at' => now()->toISOString(),
            ];
        });
    }

    public function names(string $query): array
    {
        return DB::table('timebook_workers as w')->leftJoin('timebook_entries as e', 'w.id', '=', 'e.worker_id')
            ->whereRaw("w.name_key LIKE ? ESCAPE '!'", [$this->pattern($query)])
            ->select('w.id', 'w.name')->selectRaw('MAX(e.day) AS last_day, COALESCE(SUM(CASE WHEN e.deleted = 0 THEN e.half_days ELSE 0 END), 0) AS half_days')
            ->groupBy('w.id', 'w.name')->orderByRaw('MAX(e.updated_at) DESC')->orderBy('w.name')->limit(20)
            ->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'last_day' => $row->last_day, 'days' => $row->half_days / 2])->all();
    }

    private function pattern(string $query): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], TimebookName::key($query)).'%';
    }

    private function numbers(array $row): array
    {
        return ['count' => (int) $row['count'], 'half_days' => (int) $row['half_days'],
            'overtime_minutes' => (int) $row['overtime_minutes'], 'days' => $row['half_days'] / 2,
            'overtime' => $row['overtime_minutes'] / 60];
    }
}
