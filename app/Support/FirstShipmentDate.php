<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FirstShipmentDate
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/^([1-9][0-9]{3})年$/u', '$1', trim($value)) : $value;
    }

    public static function isYear(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{3}$/D', $value) === 1;
    }

    public static function valid(mixed $value): bool
    {
        if (self::isYear($value)) {
            return true;
        }
        if (! is_string($value) || ! preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function lower(string $value): string
    {
        return self::isYear($value) ? $value.'-01-01' : $value;
    }

    public static function upper(string $value): string
    {
        return self::isYear($value) ? $value.'-12-31' : $value;
    }

    public static function expression(bool $upper = false): string
    {
        $column = DB::connection()->getDriverName() === 'pgsql'
            ? "payload->>'first_shipment_date'"
            : "json_extract(payload, '$.first_shipment_date')";
        $suffix = $upper ? '-12-31' : '-01-01';

        return "CASE WHEN LENGTH({$column}) = 4 THEN {$column} || '{$suffix}' ELSE {$column} END";
    }

    public static function filter(Builder $query, string $operator, string $value): void
    {
        $values = array_map(self::normalize(...), $operator === 'between' ? explode('..', $value) : [$value]);
        if (count($values) !== ($operator === 'between' ? 2 : 1) || collect($values)->contains(fn (mixed $value): bool => ! self::valid($value))) {
            $query->whereRaw('1 = 0');

            return;
        }
        $lower = self::expression();
        $upper = self::expression(true);
        $query->whereNotNull('payload->first_shipment_date')->where('payload->first_shipment_date', '!=', '');
        if ($operator === 'equals' && ! self::isYear($values[0])) {
            $query->where('payload->first_shipment_date', $values[0]);
        } elseif (in_array($operator, ['equals', 'between'], true)) {
            $query->whereRaw("({$lower}) >= ? AND ({$upper}) <= ?", [self::lower($values[0]), self::upper(end($values))]);
        } else {
            [$expression, $comparison, $bound] = match ($operator) {
                'before' => [$upper, '<', self::lower($values[0])],
                'on_or_before' => [$upper, '<=', self::upper($values[0])],
                'after' => [$lower, '>', self::upper($values[0])],
                'on_or_after' => [$lower, '>=', self::lower($values[0])],
            };
            $query->whereRaw("({$expression}) {$comparison} ?", [$bound]);
        }
    }
}
