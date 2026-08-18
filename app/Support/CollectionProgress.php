<?php

namespace App\Support;

use App\Models\ObjectRecord;
use Illuminate\Support\Collection;

final class CollectionProgress
{
    public function percentage(mixed $occurredAmount, mixed $paidAmount): ?float
    {
        $occurredCents = $this->cents($occurredAmount);
        $paidCents = $this->paidCents($paidAmount);

        if ($occurredCents === null || $occurredCents <= 0 || $paidCents === null) {
            return null;
        }

        return round($paidCents / $occurredCents * 100, 2);
    }

    /**
     * @param  Collection<int, ObjectRecord>  $records
     * @return array{ratio: ?float, paid_amount: float, occurred_amount: float, covered_records: int, total_records: int}
     */
    public function summarize(Collection $records): array
    {
        $paidCents = 0;
        $occurredCents = 0;
        $coveredRecords = 0;

        foreach ($records as $record) {
            $payload = $record->payload ?? [];
            $recordOccurredCents = $this->cents($payload['occurred_amount'] ?? null);
            $recordPaidCents = $this->paidCents($payload['paid_amount'] ?? null);

            if ($recordOccurredCents === null || $recordOccurredCents <= 0 || $recordPaidCents === null) {
                continue;
            }

            $occurredCents += $recordOccurredCents;
            $paidCents += $recordPaidCents;
            $coveredRecords++;
        }

        return [
            'ratio' => $occurredCents > 0 ? round($paidCents / $occurredCents * 100, 2) : null,
            'paid_amount' => round($paidCents / 100, 2),
            'occurred_amount' => round($occurredCents / 100, 2),
            'covered_records' => $coveredRecords,
            'total_records' => $records->count(),
        ];
    }

    private function paidCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $this->cents($value);
    }

    private function cents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;
        if (! is_finite($amount)) {
            return null;
        }

        return (int) round($amount * 100);
    }
}
