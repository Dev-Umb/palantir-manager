<?php

namespace Tests\Unit;

use App\Support\StandardInvoiceParser;
use PHPUnit\Framework\TestCase;

class StandardInvoiceParserTest extends TestCase
{
    private function invoice(string $total = '113.00'): string
    {
        return "电子发票（普通发票）\n发票号码：12345678901234567890\n开票日期：2026年10月29日\n购 名称：示例购买公司      销 名称：示例销售公司\n合计 ¥111.88   ¥1.12\n价税合计（大写）壹佰壹拾叁圆整  （小写）¥{$total}";
    }

    public function test_standard_invoice_extracts_tax_inclusive_integer_cents(): void
    {
        $result = (new StandardInvoiceParser)->parse($this->invoice());
        $this->assertSame('recognized', $result['status']);
        $this->assertSame(11300, $result['fields']['total_cents']);
        $this->assertSame('12345678901234567890', $result['fields']['invoice_number']);
        $this->assertSame('2026-10-29', $result['fields']['invoice_date']);
        $this->assertSame('示例购买公司', $result['fields']['buyer']);
        $this->assertSame('示例销售公司', $result['fields']['seller']);
    }

    public function test_ambiguous_credit_missing_and_non_invoice_amounts_are_not_applied(): void
    {
        foreach ([$this->invoice()."\n".$this->invoice('99.00'), $this->invoice('-113.00'), $this->invoice('－113.00'), $this->invoice('$113.00'), $this->invoice('0.00'), $this->invoice('113.001'), '报价单 合计113.00', str_replace('（小写）¥113.00', '', $this->invoice())] as $text) {
            $this->assertNull((new StandardInvoiceParser)->parse($text)['fields']['total_cents']);
        }
    }

    public function test_invoice_labels_support_an_unclear_scanned_title_without_guessing_date(): void
    {
        $text = str_replace(['电子发票（普通发票）', '开票日期：2026年10月29日'], ['模糊标题', '日期不可读'], $this->invoice());
        $result = (new StandardInvoiceParser)->parse($text);
        $this->assertSame(11300, $result['fields']['total_cents']);
        $this->assertNull($result['fields']['invoice_date']);
    }

    public function test_grouped_amount_and_invalid_date(): void
    {
        $result = (new StandardInvoiceParser)->parse(str_replace('10月29日', '02月30日', $this->invoice('1,113.01')));
        $this->assertSame(111301, $result['fields']['total_cents']);
        $this->assertNull($result['fields']['invoice_date']);
    }
}
