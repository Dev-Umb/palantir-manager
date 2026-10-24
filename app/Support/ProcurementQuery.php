<?php

namespace App\Support;

use App\Models\ProcurementNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class ProcurementQuery
{
    public static function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:200'], 'method' => ['nullable', 'string', 'max:100'], 'priority' => ['nullable', 'in:P1,P2,P3,待核实'], 'start' => ['nullable', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'], 'time' => ['nullable', 'in:published,registration,deadline'], 'page' => ['nullable', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50'], 'id' => ['nullable', 'integer', 'min:1']];
    }

    public function visible(User $user): Builder
    {
        $user = User::find($user->id);
        abort_unless(HubAccess::canRead($user), 403);
        $q = ProcurementNotice::query();
        if (! HubAccess::canManage($user)) {
            $q->where(fn (Builder $q) => $q->where('invited', false)->orWhere('user_id', $user->id));
        }

        return $q;
    }

    public function result(User $user, array $input): array
    {
        $f = Validator::make($input, self::rules())->validate();
        $q = $this->visible($user);
        if (! empty($f['id'])) {
            $q->whereKey($f['id']);
        }
        if (! empty($f['method'])) {
            $q->where('method', $f['method']);
        }
        if (! empty($f['priority'])) {
            $q->where('priority', $f['priority']);
        }
        if (! empty($f['q'])) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']);
            $grammar = $q->getQuery()->getGrammar();
            $unit = $grammar->wrap('payload->unit');
            $code = $grammar->wrap('payload->code');
            $q->where(fn (Builder $b) => $b->whereRaw("title LIKE ? ESCAPE '!'", ['%'.$term.'%'])->orWhereRaw("{$unit} LIKE ? ESCAPE '!'", ['%'.$term.'%'])->orWhereRaw("{$code} LIKE ? ESCAPE '!'", ['%'.$term.'%']));
        }
        $column = ['published' => 'published_date', 'registration' => 'dates->registration->date', 'deadline' => 'dates->deadline->date'][$f['time'] ?? 'published'];
        foreach (['start' => '>=', 'end' => '<='] as $key => $op) {
            if (! empty($f[$key])) {
                $q->whereDate($column, $op, $f[$key]);
            }
        }
        $total = (clone $q)->count();
        $counts = (clone $q)->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority')->all();
        $notices = $q->orderByDesc('published_date')->orderByDesc('id')->paginate((int) ($f['limit'] ?? 20), ['*'], 'page', (int) ($f['page'] ?? 1))->withQueryString();

        return ['notices' => $notices, 'filters' => $f, 'methods' => $this->visible($user)->select('method')->distinct()->orderBy('method')->pluck('method'), 'totals' => ['count' => $total, 'priority' => $counts], 'queried_at' => now()->toIso8601String(), 'scope' => '当前用户有权查看的采集端提交数据；内容未经过原文核验。'];
    }
}
