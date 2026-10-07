<?php

namespace Tests\Unit;

use App\Support\QuotationCalculator;
use App\Support\QuotationMarketSearch;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuotationCalculatorTest extends TestCase
{
    public function test_decimal_arithmetic_rounds_money_at_the_stated_boundaries(): void
    {
        $calculator = new QuotationCalculator;
        $params = ['mode' => 'total', 'unit' => '吨', 'quantity' => '1.005', 'tax_rate' => '13', 'tax_basis' => '未税', 'extra_fee' => '0.10'];
        $result = $calculator->calculate($params, ['amount' => '0.10'], ['amount' => '0.20']);
        $this->assertSame(45, $result['total_cents']);
        $this->assertSame(40, $result['net_cents']);
        $this->assertSame(5, $result['tax_cents']);
        $this->assertSame(1235, $calculator->scaled('12.345', 100));
    }

    public function test_unknown_quantity_does_not_hide_unallocatable_fees(): void
    {
        $this->expectException(ValidationException::class);
        (new QuotationCalculator)->calculate(['mode' => 'unit', 'unit' => '吨', 'tax_rate' => 13, 'tax_basis' => '含税', 'extra_fee' => '100'], ['amount' => '1'], ['amount' => '2']);
    }

    public function test_large_quantity_rental_combination_fails_before_integer_overflow(): void
    {
        $this->expectException(ValidationException::class);
        (new QuotationCalculator)->calculate(['mode' => 'total', 'unit' => '吨日', 'quantity' => '100000', 'days' => 3650, 'tax_rate' => 13, 'tax_basis' => '含税', 'extra_fee' => 0], ['amount' => '10000000'], ['amount' => '10000000']);
    }

    public function test_market_price_requires_exact_quote_date_specification_and_tax_evidence(): void
    {
        $search = app(QuotationMarketSearch::class);
        $query = ['price_date' => '2026-10-05', 'market' => '太原', 'material' => 'Q235B', 'steel_spec' => '6mm', 'tax_basis' => '含税'];
        $source = ['text' => '2026年10月5日 太原 Q235B 6mm 板材 含税 3400元/吨'];
        $candidate = ['quote' => '含税 3400元/吨', 'amount' => '3400.00', 'published_date' => '2026-10-05', 'market' => '太原', 'material' => 'Q235B', 'spec' => '6mm', 'tax_basis' => '含税'];
        $this->assertTrue($search->verified($candidate, $source, $query));
        $this->assertFalse($search->verified([...$candidate, 'amount' => '400'], $source, $query));
        $this->assertFalse($search->verified([...$candidate, 'published_date' => '2026-10-04'], $source, $query));
        $this->assertFalse($search->verified([...$candidate, 'quote' => '捏造3400'], $source, $query));
        $this->assertFalse($search->verified($candidate, ['text' => str_replace('含税', '税口径未知', $source['text'])], $query));
        $this->assertFalse($search->verified($candidate, $source, [...$query, 'steel_spec' => '8mm']));
    }
}
