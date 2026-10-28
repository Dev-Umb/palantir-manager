<?php

namespace App\Support;

class SupportLibraryMath
{
    public const MAX_COUNT = 9007199254740991;

    public static function count(mixed $value, string $label = '数量', bool $positive = false): int
    {
        if (! is_int($value) || $value < ($positive ? 1 : 0) || $value > self::MAX_COUNT) {
            throw new SupportLibraryException($label.'须为可精确计算范围内的'.($positive ? '正整数。' : '非负整数。'), 'QUANTITY_OVERFLOW');
        }

        return $value;
    }

    public static function add(int $left, int $right): int
    {
        if ($left > self::MAX_COUNT - $right) {
            throw new SupportLibraryException('合并数量超出可精确计算范围。', 'QUANTITY_OVERFLOW');
        }

        return self::count($left + $right);
    }

    public static function multiply(int $left, int $right): int
    {
        if ($right > 0 && $left > intdiv(self::MAX_COUNT, $right)) {
            throw new SupportLibraryException('数量超出可精确计算范围。', 'QUANTITY_OVERFLOW');
        }

        return self::count($left * $right);
    }

    public static function weight(float|int $value): float
    {
        if (! is_finite((float) $value)) {
            throw new SupportLibraryException('重量超出可计算范围。', 'QUANTITY_OVERFLOW');
        }

        return round($value, 6);
    }

    public static function sum(array $values, bool $known = false): ?float
    {
        $valid = array_filter($values, fn ($value) => is_int($value) || is_float($value));
        if (! $valid || (! $known && count($valid) !== count($values))) {
            return null;
        }

        return self::weight(array_sum($valid));
    }
}
