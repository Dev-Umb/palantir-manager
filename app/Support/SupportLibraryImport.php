<?php

namespace App\Support;

use DOMDocument;
use ZipArchive;

class SupportLibraryImport
{
    public function __construct(private SupportLibraryValidator $validator) {}

    public function preview(string $path, string $filename, array $base): array
    {
        if (! preg_match('/\.xlsx$/i', $filename) || filesize($path) > 10 * 1024 * 1024) {
            $this->fail('请选择不超过10MiB的未加密.xlsx文件。');
        }
        $filename = basename(str_replace('\\', '/', $filename));
        $parsed = $this->fromSheets($this->read($path), $base, $filename);
        $merged = $this->merge($base, $parsed['library']);
        $merged['payload']['last_excel_import'] = ['filename' => $filename, 'sha256' => hash_file('sha256', $path), 'imported_at' => now()->toIso8601String()];

        return [...$merged, 'filename' => $filename, 'warnings' => $parsed['warnings'], 'compositionCount' => count($parsed['library']['composition'])];
    }

    public function read(string $path): array
    {
        if (filesize($path) > 10 * 1024 * 1024) {
            $this->fail('文件超过10MiB。');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('无法读取Excel，请使用未加密的.xlsx文件。');
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
            foreach ($doc->getElementsByTagName('c') as $cell) {
                $ref = $cell->getAttribute('r');
                [$row] = $this->position($ref);
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
                    $cells[$ref] = ['value' => $value, 'formula' => $formula, 'error' => in_array($type, ['e', 'b'], true)];
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
                for ($r = $r1; $r <= $r2; $r++) {
                    for ($c = $c1; $c <= min($c2, 6); $c++) {
                        $key = SupportLibraryExport::column($c - 1).$r;
                        if (isset($merges[$key])) {
                            $this->fail($name.'：合并单元格范围重叠。');
                        }
                        $merges[$key] = $refs[0];
                    }
                }
            }
            $sheets[] = ['name' => $name, 'cells' => $cells, 'merges' => $merges, 'maxRow' => $maxRow];
        }

        return $sheets;
    }

    public function cell(array $sheet, int $row, int $column): mixed
    {
        $ref = SupportLibraryExport::column($column - 1).$row;
        $ref = $sheet['merges'][$ref] ?? $ref;
        $cell = $sheet['cells'][$ref] ?? null;
        if ($cell['formula'] ?? false) {
            $this->fail($sheet['name'].'!'.$ref.'：请将公式复制粘贴为数值，不能使用缓存结果。', $sheet['name'], $ref);
        }
        if ($cell['error'] ?? false) {
            $this->fail($sheet['name'].'!'.$ref.'：单元格包含错误或布尔值。', $sheet['name'], $ref);
        }

        return $cell['value'] ?? null;
    }

    private function fromSheets(array $sheets, array $base, string $filename): array
    {
        $partSheets = array_values(array_filter($sheets, fn ($s) => $this->header($this->cell($s, 1, 1)) === '规格' && $this->header($this->cell($s, 1, 2)) === '长度mm'));
        $frameSheets = array_values(array_filter($sheets, fn ($s) => $this->header($this->cell($s, 1, 1)) === '用途' && $this->header($this->cell($s, 1, 2)) === '钢柱规格直径-高度'));
        if (! $partSheets || count($frameSheets) !== 1) {
            $this->fail('格式不符：需要构件表和一张支架搭配表，请下载格式模板。');
        }
        $parts = $frames = $composition = $warnings = $partKeys = $frameKeys = $lineKeys = [];
        foreach ($partSheets as $s) {
            if (! in_array($this->header($this->cell($s, 1, 3)), ['重量kg', '单重kg'], true) || ! str_starts_with($this->header($this->cell($s, 1, 4)), '配套螺栓') || $this->header($this->cell($s, 1, 5)) !== '备注') {
                $this->fail($s['name'].'：构件表列顺序须为规格、长度mm、重量kg、配套螺栓、备注。');
            }
            for ($r = 2; $r <= $s['maxRow']; $r++) {
                $row = array_map(fn ($c) => $this->cell($s, $r, $c), range(1, 5));
                if (array_all(array_slice($row, 1), $this->empty(...))) {
                    continue;
                }
                $spec = preg_replace('/^[ΦφØø]/u', 'φ', trim((string) $row[0]));
                $spec = strtoupper($spec) === 'C' ? 'C' : $spec;
                if (! in_array($spec, ['C', 'φ273', 'φ530'], true)) {
                    $this->at($filename, $s, $r, 1, '规格须为φ273、φ530或C。');
                }
                $label = $filename.' / '.$s['name'].'!B'.$r;
                $n = $this->number($row[1], $label);
                $meters = $spec !== 'C' && $n < 1;
                $length = $this->mm($n, $label, $meters);
                if ($meters) {
                    $warnings[] = $label.'：按米换算为'.$length.'mm。';
                }
                $note = $this->empty($row[4]) ? '' : trim((string) $row[4]);
                $hole = $spec === 'C' ? $this->hole($note, $filename.' / '.$s['name'].'!E'.$r) : '未注明中孔';
                $weight = $this->number($row[2], $filename.' / '.$s['name'].'!C'.$r, true);
                $bolts = $this->number($row[3], $filename.' / '.$s['name'].'!D'.$r, true);
                if ($weight !== null && $weight <= 0) {
                    $this->at($filename, $s, $r, 3, '单重须大于0，未知请留空。');
                }
                if ($bolts !== null && (floor($bolts) !== $bolts || $bolts > SupportLibraryMath::MAX_COUNT)) {
                    $this->at($filename, $s, $r, 4, '螺栓数量须为非负整数。');
                }
                $part = ['id' => 'IMP-P'.(count($parts) + 1), 'kind' => $spec === 'C' ? '柱撑' : '钢柱', 'spec' => $spec, 'length_mm' => $length,
                    'length_raw' => $row[1], 'length_status' => 'Excel导入，已换算为mm', 'unit_weight_kg' => $weight, 'bolts_per_piece_raw' => $bolts === null ? null : (int) $bolts,
                    'note' => $note, 'hole' => $hole, 'source' => $filename.' / '.$s['name'].'!A'.$r];
                if ($this->header($this->cell($s, 1, 6)) === '补充备注' && ! $this->empty($this->cell($s, $r, 6))) {
                    $part['note'] = trim((string) $this->cell($s, $r, 6));
                }
                $key = $this->validator->partKey($part);
                if (isset($partKeys[$key])) {
                    $this->at($filename, $s, $r, 1, '同规格、长度、孔位构件重复，请整理为一行。');
                }
                $partKeys[$key] = true;
                $parts[] = $part;
            }
        }
        $s = $frameSheets[0];
        if ($this->header($this->cell($s, 1, 3)) !== '柱间距m' || $this->header($this->cell($s, 1, 4)) !== '柱撑长度mm' || $this->header($this->cell($s, 1, 5)) !== '数量' || $this->header($this->cell($s, 1, 6)) !== '备注') {
            $this->fail($s['name'].'：支架搭配表列顺序不正确。');
        }
        for ($r = 2; $r <= $s['maxRow']; $r++) {
            $row = array_map(fn ($c) => $this->cell($s, $r, $c), range(1, 6));
            if (array_all(array_slice($row, 3), $this->empty(...))) {
                continue;
            }
            $use = preg_replace('/支架$/u', '', trim((string) $row[0]));
            if (! in_array($use, ['吊装', '顶推'], true) || ! preg_match('/^[φΦØø](273|530)-(\d+(?:\.\d+)?)m$/iu', trim((string) $row[1]), $match)) {
                $this->at($filename, $s, $r, 2, '用途须为吊装或顶推，钢柱规格按φ273-6m填写。');
            }
            $height = $this->mm($this->number($match[2], $s['name'].'!B'.$r), $s['name'].'!B'.$r, true);
            $spacing = $this->mm($this->number($row[2], $s['name'].'!C'.$r), $s['name'].'!C'.$r, true);
            $length = $this->mm($this->number($row[3], $s['name'].'!D'.$r), $s['name'].'!D'.$r);
            $quantity = $this->number($row[4], $s['name'].'!E'.$r);
            if ($quantity <= 0 || floor($quantity) !== $quantity || $quantity > SupportLibraryMath::MAX_COUNT) {
                $this->at($filename, $s, $r, 5, '每面数量须为正整数。');
            }
            $note = $this->empty($row[5]) ? '' : trim((string) $row[5]);
            $hole = $this->hole($note, $s['name'].'!F'.$r);
            $key = implode('|', [$use, $match[1], $height, $spacing]);
            if (! isset($frameKeys[$key])) {
                $frameKeys[$key] = count($frames);
                $frames[] = ['id' => 'IMP-F'.(count($frames) + 1), 'support_use' => $use, 'diameter_mm' => (int) $match[1], 'height_mm' => $height, 'spacing_mm' => $spacing, 'sources' => []];
            }
            $index = $frameKeys[$key];
            $frames[$index]['sources'][] = $filename.' / '.$s['name'].'!A'.$r;
            $hits = array_values(array_filter($parts, fn ($p) => $p['kind'] === '柱撑' && $p['length_mm'] === $length && $p['hole'] === $hole));
            if (count($hits) !== 1) {
                $this->at($filename, $s, $r, 4, '本Excel构件表未找到对应长度和孔位的柱撑。');
            }
            $lineKey = $frames[$index]['id'].'/'.$hits[0]['id'];
            if (isset($lineKeys[$lineKey])) {
                $this->at($filename, $s, $r, 4, '同搭配同构件行重复，请整理为一行，不自动相加。');
            }
            $lineKeys[$lineKey] = true;
            $composition[] = ['frame_id' => $frames[$index]['id'], 'part_id' => $hits[0]['id'], 'per_frame' => (int) $quantity, 'note' => $note, 'source' => $filename.' / '.$s['name'].'!A'.$r];
        }
        if (! $parts || ! $frames) {
            $this->fail('Excel没有完整的构件和搭配数据，空模板不能覆盖数据库。');
        }
        $library = $this->validator->validate([...$base, 'parts' => $parts, 'frames' => $frames, 'composition' => $composition]);
        $missingWeights = count(array_filter($parts, fn ($p) => $p['kind'] === '钢柱' && $p['unit_weight_kg'] === null));
        $missingBolts = count(array_filter($parts, fn ($p) => $p['bolts_per_piece_raw'] === null));
        if ($missingWeights) {
            $warnings[] = $missingWeights.'条钢柱单重为空，重量仍显示待补。';
        }
        if ($missingBolts) {
            $warnings[] = $missingBolts.'条构件配套螺栓未知。';
        }

        return ['library' => $library, 'warnings' => $warnings];
    }

    private function merge(array $base, array $imported): array
    {
        $next = $base;
        $partMap = $frameMap = [];
        $stats = ['partsAdded' => 0, 'partsUpdated' => 0, 'framesAdded' => 0, 'framesUpdated' => 0];
        foreach (['parts', 'frames'] as $section) {
            $keyMethod = $section === 'parts' ? 'partKey' : 'frameKey';
            foreach ($imported[$section] as $row) {
                $key = $this->validator->$keyMethod($row);
                $hits = array_keys(array_filter($next[$section], fn ($old) => $this->validator->$keyMethod($old) === $key));
                if (count($hits) > 1) {
                    $this->fail('当前库存在同自然键多个记录，无法确定更新对象。');
                }
                $index = $hits[0] ?? null;
                $id = $index !== null ? $next[$section][$index]['id'] : $this->newId($next[$section], $section === 'parts' ? 'XLP' : 'XLF');
                if ($section === 'parts') {
                    $partMap[$row['id']] = $id;
                } else {
                    $frameMap[$row['id']] = $id;
                }
                $record = [...$row, 'id' => $id];
                $stats[$section.($index === null ? 'Added' : 'Updated')]++;
                if ($index === null) {
                    $next[$section][] = $record;
                } else {
                    $next[$section][$index] = $record;
                }
            }
        }
        $replacing = array_values($frameMap);
        $next['composition'] = array_values(array_filter($next['composition'], fn ($d) => ! in_array($d['frame_id'], $replacing, true)));
        foreach ($imported['composition'] as $line) {
            $next['composition'][] = [...$line, 'frame_id' => $frameMap[$line['frame_id']], 'part_id' => $partMap[$line['part_id']]];
        }

        return ['payload' => $this->validator->validate($next), 'stats' => $stats];
    }

    private function newId(array $rows, string $prefix): string
    {
        $ids = array_column($rows, 'id');
        $n = 1;
        while (in_array($prefix.$n, $ids, true)) {
            $n++;
        }

        return $prefix.$n;
    }

    private function number(mixed $value, string $label, bool $optional = false): ?float
    {
        if ($this->empty($value)) {
            if ($optional) {
                return null;
            }
            $this->fail($label.'：不能为空。');
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/D', trim((string) $value)) || ! is_finite((float) $value)) {
            $this->fail($label.'：须填写非负数值。');
        }

        return (float) $value;
    }

    private function mm(float $value, string $label, bool $meters = false): int
    {
        $mm = $value * ($meters ? 1000 : 1);
        if ($mm <= 0 || $mm > SupportLibraryMath::MAX_COUNT || abs($mm - round($mm)) > 1e-7) {
            $this->fail($label.'：长度须大于0且精确到毫米。');
        }

        return (int) round($mm);
    }

    private function hole(string $note, string $label): string
    {
        if (in_array($note, ['', '/', '—', '未注明中孔', '无中孔', '不带中孔'], true)) {
            return '未注明中孔';
        }
        if ($note === '带中孔') {
            return $note;
        }
        $this->fail($label.'：孔位请填带中孔或留空，其他说明放补充备注。');
    }

    private function header(mixed $value): string
    {
        return strtolower(preg_replace('/[\s（）()]/u', '', trim((string) $value)));
    }

    private function empty(mixed $value): bool
    {
        return $value === null || in_array(trim((string) $value), ['', '/', '—', '待确认', '待补'], true);
    }

    private function texts(\DOMNode $node): string
    {
        $text = '';
        foreach ($node->getElementsByTagName('t') as $t) {
            $text .= $t->textContent;
        }

        return $text;
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

    private function at(string $file, array $sheet, int $row, int $column, string $message): never
    {
        $ref = SupportLibraryExport::column($column - 1).$row;
        $this->fail($file.' / '.$sheet['name'].'!'.$ref.'：'.$message, $sheet['name'], $ref);
    }

    private function fail(string $message, ?string $sheet = null, ?string $cell = null): never
    {
        throw new SupportLibraryException($message, 'IMPORT_INVALID', context: ['sheet' => $sheet, 'cell' => $cell]);
    }
}
