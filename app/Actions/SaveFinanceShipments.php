<?php

namespace App\Actions;

use App\Http\Requests\SaveFinanceShipmentRequest;
use App\Models\AuditLog;
use App\Models\ObjectRecord;
use App\Models\User;
use App\Support\FinanceShipmentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaveFinanceShipments
{
    public function __construct(private FinanceShipmentAccess $access) {}

    public function current(ObjectRecord $project): array
    {
        $ledger = DB::table('finance_shipment_ledgers')->where('project_id', $project->id)->first();
        $orders = $ledger ? json_decode($ledger->orders, true, flags: JSON_THROW_ON_ERROR) : [];

        return ['revision' => (int) ($ledger->revision ?? 0), 'orders' => $orders, 'summary' => $this->summary($orders)];
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
                if (empty($order['date']) || ! trim($row['name'] ?? '') || ($row['unit'] ?? null) !== '吨' || (float) ($row['quantity'] ?? 0) <= 0) {
                    $pending++;

                    continue;
                }
                $quantity = (int) round((float) $row['quantity'] * 1000);
                $milli += $quantity;
                $dates[] = $order['date'];
                if (($row['price'] ?? null) !== null && $row['price'] !== '') {
                    $cents += (int) round($quantity * (int) round((float) $row['price'] * 100) / 1000);
                }
            }
        }

        return ['tonnes' => $milli / 1000, 'amount' => $cents / 100, 'last_date' => $dates ? max($dates) : null, 'pending' => $pending];
    }

    public function save(ObjectRecord $project, array $input, User $user, bool $trustedImport = false): array
    {
        $this->access->authorize($user, $project);
        $input = Validator::make($input, SaveFinanceShipmentRequest::ledgerRules())->validate();

        return DB::transaction(function () use ($project, $input, $user, $trustedImport): array {
            $project = ObjectRecord::query()->lockForUpdate()->findOrFail($project->id);
            $this->access->authorize($user, $project);
            DB::table('finance_shipment_ledgers')->insertOrIgnore(['project_id' => $project->id, 'revision' => 0, 'orders' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            $ledger = DB::table('finance_shipment_ledgers')->where('project_id', $project->id)->lockForUpdate()->sole();
            $before = json_decode($ledger->orders, true, flags: JSON_THROW_ON_ERROR);
            $oldSources = collect($before)->pluck('source', 'id');
            foreach ($input['orders'] as &$order) {
                $order['source'] = $oldSources->has($order['id']) ? $oldSources[$order['id']] : ($trustedImport ? ($order['source'] ?? null) : null);
            }
            unset($order);
            if ($before === $input['orders']) {
                return $this->current($project);
            }
            abort_unless((int) $ledger->revision === (int) $input['revision'], 409, '台账已被其他页面修改。当前输入已保留，请核对最新数据后再保存。');
            $summary = $this->summary($input['orders']);
            $changed = DB::table('finance_shipment_ledgers')->where('id', $ledger->id)->where('revision', $input['revision'])->update([
                'orders' => json_encode($input['orders'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'revision' => $ledger->revision + 1, 'updated_at' => now(),
            ]);
            abort_unless($changed === 1, 409, '台账版本已变化，未覆盖其他人的更新。');
            AuditLog::create(['user_id' => $user->id, 'action' => 'finance.shipments.save', 'subject_type' => 'project', 'subject_id' => $project->id,
                'payload' => ['before' => $before, 'after' => $input['orders'], 'summary' => $summary, 'revision' => $ledger->revision + 1]]);

            return $this->current($project);
        });
    }
}
