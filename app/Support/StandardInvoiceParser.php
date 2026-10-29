<?php

namespace App\Support;

class StandardInvoiceParser
{
    /** @return array<string, mixed> */
    public function parse(string $text): array
    {
        $compact = preg_replace('/[\x{00a0}\t ]+/u', '', $text);
        $fields = ['invoice_number' => null, 'invoice_code' => null, 'invoice_date' => null, 'buyer' => null, 'seller' => null, 'total_cents' => null];
        $warnings = [];
        if (! preg_match('/(?:电子发票|增值税.*发票)/u', $compact) && ! (str_contains($compact, '发票号码') && str_contains($compact, '价税合计'))) {
            return ['status' => 'unrecognized', 'fields' => $fields, 'warnings' => ['未识别为支持的标准发票，请手动填写。']];
        }
        preg_match_all('/发票号码[:：]?([0-9]{20}|[0-9]{8})(?![0-9])/u', $compact, $numbers);
        preg_match_all('/[（(]小写[）)][:：]?[¥￥]?([0-9]{1,9}(?:,[0-9]{3})*\.[0-9]{2})(?![0-9])/u', $compact, $totals);
        $uniqueNumbers = array_values(array_unique($numbers[1]));
        $uniqueTotals = array_values(array_unique($totals[1]));
        // A multi-invoice PDF needs a human to allocate expenses, even if totals coincide.
        if (count($numbers[1]) > 1 || count($totals[1]) > 1) {
            return ['status' => 'unrecognized', 'fields' => $fields, 'warnings' => ['PDF 包含多张发票或重复、冲突的金额，请拆分或手动核对。']];
        }
        $fields['invoice_number'] = $uniqueNumbers[0] ?? null;
        if (preg_match('/发票代码[:：]?([0-9]{12}|[0-9]{10})(?![0-9])/u', $compact, $code)) {
            $fields['invoice_code'] = $code[1];
        }
        if (preg_match('/开票日期[:：]?([0-9]{4})[年\/-]([0-9]{1,2})[月\/-]([0-9]{1,2})日?/u', $compact, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            $fields['invoice_date'] = sprintf('%04d-%02d-%02d', $date[1], $date[2], $date[3]);
        }
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/名\s*称\s*[:：]\s*(.+?)\s{2,}.*?名\s*称\s*[:：]\s*(.+)$/u', $line, $names)) {
                $fields['buyer'] = trim($names[1]);
                $fields['seller'] = trim($names[2]);
                break;
            }
        }
        if (isset($uniqueTotals[0]) && $fields['invoice_number'] !== null && ! preg_match('/红字|负数|小写[）)]?[:：]?[¥￥]?-/u', $compact)) {
            [$whole, $fraction] = explode('.', str_replace(',', '', $uniqueTotals[0]));
            $cents = (int) $whole * 100 + (int) $fraction;
            if ($cents > 0 && $cents <= 9999999999) {
                $fields['total_cents'] = $cents;
            }
        }
        if ($fields['total_cents'] === null) {
            $warnings[] = '未能唯一确定正数价税合计，请手动填写金额。';
        }
        $warnings[] = '识别结果仅供填写参考，不代表发票验真，请对照原件核对。';

        return ['status' => $fields['total_cents'] !== null ? 'recognized' : 'unrecognized', 'fields' => $fields, 'warnings' => $warnings];
    }
}
