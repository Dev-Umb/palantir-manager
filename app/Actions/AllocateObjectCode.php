<?php

namespace App\Actions;

use App\Models\BusinessObject;
use App\Models\CodeSequence;
use Illuminate\Support\Facades\DB;

class AllocateObjectCode
{
    public function handle(BusinessObject $object): string
    {
        return DB::transaction(function () use ($object): string {
            $sequenceDate = now()->toDateString();
            $dateToken = now()->format('Ymd');
            $codePrefix = "{$object->code_prefix}-{$dateToken}-";
            $historicalLastNumber = $this->historicalLastNumber($object, $codePrefix);
            $timestamp = now();

            CodeSequence::query()->insertOrIgnore([
                'prefix' => $object->code_prefix,
                'sequence_date' => $sequenceDate,
                'last_number' => $historicalLastNumber,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $sequence = CodeSequence::query()
                ->where('prefix', $object->code_prefix)
                ->whereDate('sequence_date', $sequenceDate)
                ->lockForUpdate()
                ->firstOrFail();
            $sequence->last_number = max($sequence->last_number, $historicalLastNumber) + 1;
            $sequence->save();

            return sprintf('%s%03d', $codePrefix, $sequence->last_number);
        }, attempts: 5);
    }

    private function historicalLastNumber(BusinessObject $object, string $codePrefix): int
    {
        return $object->records()
            ->where('code', 'like', "{$codePrefix}%")
            ->pluck('code')
            ->map(function (string $code) use ($codePrefix): int {
                preg_match('/^'.preg_quote($codePrefix, '/').'(\d+)$/', $code, $matches);

                return (int) ($matches[1] ?? 0);
            })
            ->max() ?? 0;
    }
}
