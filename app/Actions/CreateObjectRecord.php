<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessObject;
use App\Models\ObjectRecord;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateObjectRecord
{
    public function __construct(
        private SyncProjectContractAmount $contractAmount,
        private SyncProjectInvoiceAmount $invoiceAmount,
        private SyncMaterialStockLedger $stockLedger,
    )
    {
    }

    public function handle(BusinessObject $object, array $payload, ?User $user = null, string $action = 'object.create'): ObjectRecord
    {
        $payload = $this->normalizePayload($object, $payload);

        $record = ObjectRecord::create([
            'business_object_id' => $object->id,
            'code' => $this->nextCode($object),
            'title' => $this->title($object, $payload),
            'payload' => $payload,
            'created_by' => $user?->id,
        ]);

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $object->key,
            'subject_id' => $record->id,
            'payload' => ['code' => $record->code, 'title' => $record->title],
        ]);

        if ($object->key === 'contract') {
            $this->contractAmount->handle($payload['project_id'] ?? null);
        }

        if ($object->key === 'invoice') {
            $this->invoiceAmount->handle($payload['project_id'] ?? null);
        }

        if (in_array($object->key, ['inbound', 'outbound', 'return_order', 'stocktake'], true)) {
            $this->stockLedger->handle($payload['material_id'] ?? null);
        }

        return $record;
    }

    public function normalizePayload(BusinessObject $object, array $payload): array
    {
        return match ($object->key) {
            'work_order' => $this->fillWorkOrderFromDrawing($payload),
            'team_log' => $this->fillTeamLogFromWorkOrder($payload),
            default => $payload,
        };
    }

    public function nextCode(BusinessObject $object): string
    {
        $date = now()->format('Ymd');
        $prefix = "{$object->code_prefix}-{$date}-";
        $last = $object->records()
            ->where('code', 'like', "{$prefix}%")
            ->pluck('code')
            ->map(function (string $code) use ($prefix) {
                preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $match);

                return (int) ($match[1] ?? 0);
            })
            ->max() ?? 0;

        return sprintf('%s%03d', $prefix, $last + 1);
    }

    private function title(BusinessObject $object, array $payload): string
    {
        if ($object->title_field === 'code') {
            return $object->label;
        }

        return (string) ($payload[$object->title_field] ?? $payload['name'] ?? $object->label);
    }

    private function fillWorkOrderFromDrawing(array $payload): array
    {
        $drawing = $this->linkedRecord($payload['drawing_id'] ?? null, 'drawing');
        if (! $drawing) {
            return $payload;
        }

        if (($drawing->payload['release_status'] ?? null) !== '已下放') {
            throw ValidationException::withMessages([
                'payload.drawing_id' => '接收图纸编号必须选择已下放的技术图纸。',
            ]);
        }

        $drawingPayload = $drawing->payload ?? [];
        $payload['project_id'] = $drawingPayload['project_id'] ?? ($payload['project_id'] ?? '');
        $payload['project_no_norm'] = $drawingPayload['project_no_norm'] ?? ($payload['project_no_norm'] ?? '');
        $payload['drawing_no'] = $drawingPayload['drawing_no'] ?? $drawing->code;
        $payload['drawing_name'] = $drawingPayload['name'] ?? $drawing->title;

        return $payload;
    }

    private function fillTeamLogFromWorkOrder(array $payload): array
    {
        $workOrder = $this->linkedRecord($payload['work_order_id'] ?? null, 'work_order');
        if (! $workOrder) {
            return $payload;
        }

        $workOrderPayload = $workOrder->payload ?? [];
        $payload['project_id'] = $workOrderPayload['project_id'] ?? ($payload['project_id'] ?? '');
        $payload['project_no_norm'] = $workOrderPayload['project_no_norm'] ?? ($payload['project_no_norm'] ?? '');
        $payload['drawing_no'] = $workOrderPayload['drawing_no'] ?? ($payload['drawing_no'] ?? '');
        $payload['team'] = ($payload['team'] ?? '') !== '' ? $payload['team'] : ($workOrderPayload['team'] ?? '');

        return $payload;
    }

    private function linkedRecord(?string $id, string $objectKey): ?ObjectRecord
    {
        if (! $id) {
            return null;
        }

        return ObjectRecord::whereKey($id)
            ->whereRelation('businessObject', 'key', $objectKey)
            ->first();
    }
}
