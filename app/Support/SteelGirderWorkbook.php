<?php

namespace App\Support;

use DOMDocument;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class SteelGirderWorkbook
{
    public function read(string $path): array
    {
        if (filesize($path) > 10 * 1024 * 1024) {
            $this->fail('文件超过10MiB。');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('无法读取Excel，请使用未加密的.xlsx文件。');
        }
        if ($zip->numFiles > 5000) {
            $zip->close();
            $this->fail('Excel文件项过多。');
        }
        $xmlFiles = [];
        $expanded = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_contains($name, '../') || str_starts_with($name, '/') || ($stat['encryption_method'] ?? 0) !== 0) {
                    $this->fail('不支持加密或异常路径的Excel。');
                }
                if ($stat['size'] > 40 * 1024 * 1024 - $expanded) {
                    $this->fail('Excel展开数据超过40MiB。');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $this->fail('Excel内部文件不可读。');
                }
                $capture = str_ends_with($name, '.xml') || str_ends_with($name, '.rels');
                $data = '';
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            $this->fail('Excel解压失败。');
                        }
                        $expanded += strlen($chunk);
                        if ($expanded > 40 * 1024 * 1024) {
                            $this->fail('Excel实际展开数据超过40MiB。');
                        }
                        if ($capture) {
                            $data .= $chunk;
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($capture) {
                    $xmlFiles[$name] = $data;
                }
            }
        } finally {
            $zip->close();
        }
        $workbook = $this->xml($xmlFiles['xl/workbook.xml'] ?? '', '工作簿');
        $rels = $this->xml($xmlFiles['xl/_rels/workbook.xml.rels'] ?? '', '工作表关系');
        $targets = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') !== 'External') {
                $target = $rel->getAttribute('Target');
                $segments = explode('/', str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target);
                $clean = [];
                foreach ($segments as $segment) {
                    if ($segment === '..') {
                        array_pop($clean);
                    } elseif ($segment !== '' && $segment !== '.') {
                        $clean[] = $segment;
                    }
                }
                $targets[$rel->getAttribute('Id')] = implode('/', $clean);
            }
        }
        $strings = [];
        if (isset($xmlFiles['xl/sharedStrings.xml'])) {
            foreach ($this->xml($xmlFiles['xl/sharedStrings.xml'], '共享文本')->getElementsByTagName('si') as $node) {
                $strings[] = $this->texts($node);
            }
        }
        $sheets = [];
        foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $target = $targets[$sheet->getAttribute('r:id')] ?? null;
            if ($target === null) {
                $this->fail($name.'：工作表链接无效。');
            }
            $doc = $this->xml($xmlFiles[$target] ?? '', $name);
            $cells = $merges = [];
            $maxRow = 1;
            $maxCol = 1;
            foreach ($doc->getElementsByTagName('c') as $cell) {
                $ref = $cell->getAttribute('r');
                [$row, $column] = $this->position($ref);
                $type = $cell->getAttribute('t');
                $raw = $cell->getElementsByTagName('v')->item(0)?->textContent;
                $value = null;
                if ($type === 'inlineStr') {
                    $value = $this->texts($cell);
                } elseif ($type === 's') {
                    if ($raw === null || ! ctype_digit($raw) || ! array_key_exists((int) $raw, $strings)) {
                        $this->fail($name.'!'.$ref.'：共享文本索引无效。');
                    }
                    $value = $strings[(int) $raw];
                } elseif ($raw !== null) {
                    $value = in_array($type, ['str', 'e'], true) ? $raw : (is_numeric($raw) ? (float) $raw : $raw);
                }
                $formula = $cell->getElementsByTagName('f')->length > 0;
                if ($value !== null || $formula) {
                    if (is_float($value) && ! is_finite($value)) {
                        $this->fail($name.'!'.$ref.'：数值超出范围。');
                    }
                    $cells[$ref] = ['ref' => $ref, 'row' => $row, 'col' => $column, 'value' => $type === 'b' ? $raw === '1' : $value, 'formula' => $formula, 'error' => $type === 'e', 'missingCache' => $formula && $type !== 'str' && ($raw === null || $raw === '')];
                    $maxCol = max($maxCol, $column);
                    $maxRow = max($maxRow, $row);
                }
            }
            foreach ($doc->getElementsByTagName('mergeCell') as $merge) {
                $refs = explode(':', $merge->getAttribute('ref'));
                [$r1, $c1] = $this->position($refs[0]);
                [$r2, $c2] = $this->position($refs[1] ?? $refs[0]);
                if ($r2 < $r1 || $c2 < $c1) {
                    $this->fail($name.'：合并单元格范围无效。');
                }
                $merges[] = ['start' => ['row' => $r1, 'col' => $c1], 'end' => ['row' => $r2, 'col' => $c2], 'ref' => $refs[0]];
                if (count($merges) > 20000) {
                    $this->fail('合并区域过多。');
                }
            }
            $sheets[] = ['name' => $name, 'cells' => array_values($cells), 'merges' => $merges, 'maxRow' => $maxRow, 'maxCol' => $maxCol];
        }

        $this->validateSheets($sheets);

        return $sheets;
    }

    private function validateSheets(array $sheets): void
    {
        $detailCandidates = $summaryCandidates = [];
        $namedDetail = $namedSummary = null;
        foreach ($sheets as $index => $sheet) {
            $rows = [];
            $labels = [];
            if ($sheet['name'] === '钢箱梁明细表') {
                $namedDetail = $index;
            }
            if ($sheet['name'] === '材料汇总') {
                $namedSummary = $index;
            }
            foreach ($sheet['cells'] as $cell) {
                $label = preg_replace('/[\s（）()]/u', '', mb_strtolower((string) $cell['value']));
                $rows[$cell['row']][] = $label;
                if ($cell['col'] <= 4) {
                    $labels[] = $label;
                }
            }
            if (array_any($rows, fn ($row) => count(array_intersect(['名称', '编号', '数量'], $row)) === 3 && array_any($row, fn ($label) => str_starts_with($label, '规格')))) {
                $detailCandidates[] = $index;
            }
            if (in_array('已计重量合计', $labels, true) && array_any($labels, fn ($label) => str_starts_with($label, '净重合计'))) {
                $summaryCandidates[] = $index;
            }
        }
        $detail = $namedDetail ?? (count($detailCandidates) === 1 ? $detailCandidates[0] : null);
        $summary = $namedSummary ?? (count($summaryCandidates) === 1 ? $summaryCandidates[0] : null);
        if ($detail === null || $summary === null || $detail === $summary || ! in_array($detail, $detailCandidates, true) || ! in_array($summary, $summaryCandidates, true)) {
            $this->fail('未找到唯一有效的钢箱梁明细表和材料汇总，请保留名称、编号、规格、数量及重量汇总表头。');
        }
    }

    private function texts(\DOMNode $node): string
    {
        $value = '';
        foreach ($node->getElementsByTagName('t') as $text) {
            $value .= $text->textContent;
        }

        return $value;
    }

    private function position(string $ref): array
    {
        if (! preg_match('/^([A-Z]{1,3})([1-9]\d*)$/D', $ref, $m)) {
            $this->fail('无效单元格位置：'.$ref);
        }
        $column = 0;
        foreach (str_split($m[1]) as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }
        if ($column > 16384 || (int) $m[2] > 20000) {
            $this->fail('导入表最多20000行，列范围不可越界。');
        }

        return [(int) $m[2], $column];
    }

    private function xml(string $text, string $label): DOMDocument
    {
        if ($text === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            $this->fail($label.'：缺少内部XML或包含外部实体声明。');
        }
        $prior = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($text, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->fail($label.'：Excel内部XML格式损坏。');
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
