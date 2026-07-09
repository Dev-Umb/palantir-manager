<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\ObjectRecord;

class SyncMaterialStockLedger
{
    public function handle(?string $materialId): void
    {
        if (! $materialId) {
            return;
        }

        $objects = BusinessObject::whereIn('key', [
            'inbound',
            'outbound',
            'return_order',
            'stock_ledger',
            'stocktake',
        ])->get()->keyBy('key');

        $ledgerObject = $objects->get('stock_ledger');
        if (! $ledgerObject) {
            return;
        }
        $material = BusinessObject::where('key', 'material')->first()?->records()->find($materialId);

        $ledger = $ledgerObject->records()
            ->where('payload->material_id', $materialId)
            ->oldest()
            ->first();

        $opening = (float) ($ledger?->payload['opening'] ?? 0);
        $inQty = $this->sumQty($objects->get('inbound'), $materialId, 'qty');
        $outQty = $this->sumQty($objects->get('outbound'), $materialId, 'qty');
        $retQty = $this->sumQty($objects->get('return_order'), $materialId, 'qty');
        $balance = $opening + $inQty - $outQty + $retQty;
        $balanceWeight = $this->sumQty($objects->get('inbound'), $materialId, 'weight')
            - $this->sumQty($objects->get('outbound'), $materialId, 'weight')
            + $this->sumQty($objects->get('return_order'), $materialId, 'weight');

        $stocktake = $objects->get('stocktake')?->records()
            ->where('payload->material_id', $materialId)
            ->latest()
            ->first();

        if ($stocktake && ($stocktake->payload['real_qty'] ?? '') !== '') {
            $balance = (float) $stocktake->payload['real_qty'];
        }

        $minimumStock = (float) (
            $ledger?->payload['minimum_stock']
            ?? $material?->payload['safety_stock']
            ?? $material?->payload['warning_qty']
            ?? 30
        );

        $payload = [
            'material_id' => $materialId,
            'category' => $ledger?->payload['category'] ?? $material?->payload['material_quality'] ?? $material?->payload['material_type'] ?? '',
            'material_model' => $ledger?->payload['material_model'] ?? '',
            'spec' => $ledger?->payload['spec'] ?? $material?->payload['spec_model'] ?? $material?->payload['spec'] ?? '',
            'unit' => $ledger?->payload['unit'] ?? $material?->payload['unit'] ?? '',
            'opening' => $opening,
            'in_qty' => $inQty,
            'out_qty' => $outQty,
            'ret_qty' => $retQty,
            'balance' => $balance,
            'balance_weight' => $balanceWeight,
            'bin' => $ledger?->payload['bin'] ?? '',
            'minimum_stock' => $minimumStock,
            'below_warn' => $minimumStock > 0 && $balance <= $minimumStock ? '是' : '否',
            'updated_date' => now()->format('Y-m-d'),
        ];

        if ($ledger) {
            $ledger->update(['payload' => $payload]);

            return;
        }

        ObjectRecord::create([
            'business_object_id' => $ledgerObject->id,
            'code' => 'KC-AUTO-'.substr(str_replace('-', '', $materialId), 0, 16),
            'title' => '自动库存台账',
            'payload' => $payload,
        ]);
    }

    private function sumQty(?BusinessObject $object, string $materialId, string $field): float
    {
        if (! $object) {
            return 0;
        }

        return $object->records()
            ->where('payload->material_id', $materialId)
            ->get()
            ->sum(fn (ObjectRecord $record) => (float) ($record->payload[$field] ?? 0));
    }
}
