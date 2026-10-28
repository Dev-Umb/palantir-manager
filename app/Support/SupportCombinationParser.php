<?php

namespace App\Support;

class SupportCombinationParser
{
    public function parse(string $expression): array
    {
        $text = trim(\Normalizer::normalize($expression, \Normalizer::FORM_KC));
        if ($text === '' || mb_strlen($text) > 20000) {
            throw new SupportLibraryException('请输入组合；每个搭配表达式最多20000字符。', 'INVALID_EXPRESSION');
        }
        $text = preg_replace('/[^\S\r\n]*([+@\/])[^\S\r\n]*/u', '$1', $text);
        $terms = [];
        foreach (preg_split('/[,，、;；]/u', $text) as $chunk) {
            array_push($terms, ...preg_split('/\s+/u', trim($chunk)));
        }
        if (count($terms) > 100) {
            throw new SupportLibraryException('每个搭配最多100个独立组合。', 'INVALID_EXPRESSION');
        }
        $groups = [];
        $total = 0;
        foreach ($terms as $index => $term) {
            $slash = explode('/', $term);
            $fields = explode('@', $slash[0]);
            $count = $slash[1] ?? '1';
            if (count($slash) === 1 && count($fields) === 4) {
                $count = array_pop($fields);
            }
            if (count($slash) > 2 || ! in_array(count($fields), [1, 3], true) || ! preg_match('/^[1-9]\d*$/D', $count) || bccomp($count, (string) SupportLibraryMath::MAX_COUNT) > 0) {
                throw new SupportLibraryException('第'.($index + 1).'项无法解析：'.$term.'；格式为高度@横向跨距@纵向跨距/正整数组数，或单柱高度/组数。', 'INVALID_EXPRESSION', context: ['item' => $index + 1]);
            }
            $heights = $this->dimensions($fields[0]);
            $single = count($fields) === 1;
            $xs = $single ? [] : $this->dimensions($fields[1]);
            $ys = $single ? [] : $this->dimensions($fields[2]);
            $total = SupportLibraryMath::add($total, (int) $count);
            $groups[] = ['column_only' => $single, 'height_segments_mm' => $heights, 'transverse_spans_mm' => $xs, 'longitudinal_spans_mm' => $ys,
                'height_mm' => $this->dimensionSum($heights), 'transverse_width_mm' => $this->dimensionSum($xs), 'longitudinal_length_mm' => $this->dimensionSum($ys),
                'transverse_spacing_mm' => count($xs) === 1 ? $xs[0] : null, 'longitudinal_spacing_mm' => count($ys) === 1 ? $ys[0] : null,
                'groups' => (int) $count, 'expression' => $term];
        }

        return $groups;
    }

    private function dimensions(string $field): array
    {
        $values = explode('+', $field);
        if (count($values) > 100) {
            throw new SupportLibraryException('每项最多100个高度段或方向跨距。', 'INVALID_EXPRESSION');
        }

        return array_map(function (string $value): int {
            if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
                throw new SupportLibraryException('高度和跨距须为正数，多段用+连接，单位为米。', 'INVALID_EXPRESSION');
            }
            $fraction = explode('.', $value)[1] ?? '';
            if (preg_match('/[1-9]/', substr($fraction, 3))) {
                throw new SupportLibraryException('尺寸须精确到毫米。', 'INVALID_EXPRESSION');
            }
            $mm = bcmul($value, '1000', 0);
            if (bccomp($mm, (string) SupportLibraryMath::MAX_COUNT) > 0 || bccomp($mm, '0') <= 0) {
                throw new SupportLibraryException('尺寸超出可精确计算范围，且必须为正数。', 'QUANTITY_OVERFLOW');
            }

            return (int) $mm;
        }, $values);
    }

    private function dimensionSum(array $values): int
    {
        return array_reduce($values, fn (int $sum, int $value) => SupportLibraryMath::add($sum, $value), 0);
    }
}
