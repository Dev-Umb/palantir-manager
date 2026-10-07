<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class QuotationCalculator
{
    /** @return array{unit_price_cents:int, quantity_millis:?int, total_cents:?int, net_cents:?int, tax_cents:?int, formula:string} */
    public function calculate(array $params, array $fee, array $steel): array
    {
        $unit = $this->scaled($fee['amount'], 100) + $this->scaled($steel['amount'], 100);
        $quantity = ($params['mode'] ?? '') === 'unit' ? null : $this->scaled($params['quantity'], 1000);
        $days = $params['unit'] === '吨日' ? (int) $params['days'] : 1;
        $rate = (int) $params['tax_rate'];
        $extra = $this->scaled($params['extra_fee'] ?? 0, 100);
        if ($quantity !== null && $quantity <= 0) {
            throw ValidationException::withMessages(['params.quantity' => '总价计算需要大于零的数量；未知数量请选择仅出单价。']);
        }
        if ($quantity === null && $extra !== 0) {
            throw ValidationException::withMessages(['params.extra_fee' => '仅出单价时无法分摊额外费用，请先提供数量。']);
        }
        if ($params['unit'] === '套' && $quantity !== null && $quantity % 1000 !== 0) {
            throw ValidationException::withMessages(['params.quantity' => '按套计价请输入整数数量。']);
        }
        if ($quantity !== null && $unit > intdiv(intdiv(PHP_INT_MAX, $days), $quantity)) {
            throw ValidationException::withMessages(['params.quantity' => '数量、单价或租期组合超过精确计算范围。']);
        }
        $base = $quantity === null ? null : $this->divide($unit * $quantity * $days, 1000);
        $grossUnit = $params['tax_basis'] === '含税' ? $unit : $this->divide($unit * (100 + $rate), 100);
        $total = $base === null ? null : ($params['tax_basis'] === '含税' ? $base + $extra : $this->divide(($base + $extra) * (100 + $rate), 100));
        $net = $total === null ? null : ($params['tax_basis'] === '含税' ? $this->divide($total * 100, 100 + $rate) : $base + $extra);

        return ['unit_price_cents' => $grossUnit, 'quantity_millis' => $quantity, 'total_cents' => $total, 'net_cents' => $net, 'tax_cents' => $total === null ? null : $total - $net, 'formula' => '基价=加工费+材料基价；金额=基价×数量'.($days !== 1 ? '×租期天数' : '').'+单列额外费用；按确认税口径计算，金额四舍五入到分。'];
    }

    public function scaled(string|int|float $value, int $scale): int
    {
        $text = (string) $value;
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $text)) {
            throw ValidationException::withMessages(['amount' => '请输入有效非负十进制数。']);
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $digits = strlen((string) $scale) - 1;

        return (int) $whole * $scale + (int) substr(str_pad($fraction, $digits + 1, '0'), 0, $digits) + ((int) substr(str_pad($fraction, $digits + 1, '0'), $digits, 1) >= 5 ? 1 : 0);
    }

    private function divide(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
