<?php

namespace App\Actions;

use App\Http\Requests\SaveFinanceShipmentRequest;
use App\Models\AuditLog;
use App\Models\FinanceShipmentLedger;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\FinanceShipmentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveFinanceShipments
{
    public function __construct(private FinanceShipmentAccess $access) {}

    public function current(ObjectRecord|FinanceShipmentLedger $project): array
    {
        $ledger = $project instanceof FinanceShipmentLedger ? $project->fresh() : FinanceShipmentLedger::where('project_id', $project->id)->oldest('id')->first();
        $orders = $ledger?->orders ?? [];

        return ['revision' => (int) ($ledger->revision ?? 0), 'orders' => $orders, 'summary' => $this->summary($orders)];
    }

    public function resolve(ObjectRecord|FinanceShipmentLedger $project, User $user): FinanceShipmentLedger
    {
        return DB::transaction(function () use ($project, $user): FinanceShipmentLedger {
            if ($project instanceof FinanceShipmentLedger) {
                $ledger = FinanceShipmentLedger::lockForUpdate()->findOrFail($project->id);
                $this->access->authorize($user, $ledger);

                return $ledger;
            }
            $project = ObjectRecord::lockForUpdate()->findOrFail($project->id);
            $this->access->authorize($user, $project);

            return FinanceShipmentLedger::where('project_id', $project->id)->oldest('id')->first()
                ?? FinanceShipmentLedger::create(['project_id' => $project->id]);
        });
    }

    public function summary(array $orders): array
    {
        $milli = 0;
        $cents = 0;
        $dates = [];
        $pending = 0;
        foreach ($orders as $order) {
            foreach ($order['rows'] as $row) {
                if (! trim($row['name'] ?? '') && ($row['quantity'] ?? null) === null && ($row['price'] ?? null) === null) {
                    continue;
                }
                $quantity = (int) round((float) ($row['quantity'] ?? 0) * 1000);
                if ($quantity > 0 && ($row['price'] ?? null) !== null && $row['price'] !== '') {
                    $cents += (int) round($quantity * (int) round((float) $row['price'] * 100) / 1000);
                }
                $date = array_key_exists('date', $row) ? $row['date'] : ($order['date'] ?? null);
                if (! trim($row['name'] ?? '') || (($row['unit'] ?? '') ?: '吨') !== '吨' || (float) ($row['quantity'] ?? 0) <= 0) {
                    $pending++;

                    continue;
                }
                $milli += $quantity;
                if ($date) {
                    $dates[] = $date;
                }
            }
        }

        return ['tonnes' => $milli / 1000, 'amount' => $cents / 100, 'last_date' => $dates ? max($dates) : null, 'pending' => $pending];
    }

    public function documentCount(array $orders): int
    {
        return collect($orders)->map(fn (array $order): string => ! empty($order['source'])
            ? preg_replace('/ \/ 第\d+行$/u', '', $order['source']) : '手工录入')->unique()->count();
    }

    public function save(ObjectRecord|FinanceShipmentLedger $project, array $input, User $user, bool $trustedImport = false): array
    {
        $this->access->authorize($user, $project);
        $input = Validator::make($input, SaveFinanceShipmentRequest::ledgerRules())->validate();

        return DB::transaction(function () use ($project, $input, $user, $trustedImport): array {
            $project = $this->resolve($project, $user);
            $ledger = FinanceShipmentLedger::lockForUpdate()->findOrFail($project->id);
            $this->access->authorize($user, $ledger);
            $before = $ledger->orders;
            $oldSources = collect($before)->pluck('source', 'id');
            foreach ($input['orders'] as &$order) {
                $order['source'] = $oldSources->has($order['id']) ? $oldSources[$order['id']] : ($trustedImport ? ($order['source'] ?? null) : null);
            }
            unset($order);
            $groups = collect($input['orders'])->groupBy(fn (array $order): string => ! empty($order['source']) ? preg_replace('/ \/ 第\d+行$/u', '', $order['source']) : '手工录入');
            foreach ($groups as $group) {
                $seen = [];
                foreach ($group->sortBy(function (array $order): int {
                    preg_match('/第(\d+)行$/u', $order['source'] ?? '', $match);

                    return (int) ($match[1] ?? 0);
                }) as $order) {
                    foreach ($order['rows'] as $row) {
                        $seen[$row['id']] = true;
                        foreach ($row['subtotal_starts'] ?? [] as $start) {
                            if (! isset($seen[$start])) {
                                throw ValidationException::withMessages(['orders' => '小计范围必须引用同一工作表中当前行或前面的明细。']);
                            }
                        }
                    }
                }
            }
            if ($before === $input['orders']) {
                return $this->current($project);
            }
            abort_unless((int) $ledger->revision === (int) $input['revision'], 409, '台账已被其他页面修改。当前输入已保留，请核对最新数据后再保存。');
            $summary = $this->summary($input['orders']);
            $changed = DB::table('finance_shipment_ledgers')->where('id', $ledger->id)->where('revision', $input['revision'])->update([
                'orders' => json_encode($input['orders'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'revision' => $ledger->revision + 1, 'updated_at' => now(),
            ]);
            abort_unless($changed === 1, 409, '台账版本已变化，未覆盖其他人的更新。');
            AuditLog::create(['user_id' => $user->id, 'action' => 'finance.shipments.save', 'subject_type' => 'finance_shipment_ledger', 'subject_id' => $ledger->id,
                'payload' => ['before' => $before, 'after' => $input['orders'], 'summary' => $summary, 'revision' => $ledger->revision + 1]]);

            return $this->current($project);
        });
    }
}
