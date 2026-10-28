<?php

namespace App\Support;

use App\Models\SupportLibraryVersion;
use ZipArchive;

class SupportLibraryExport
{
    public function result(array $result): string
    {
        $rows = [['类别', '规格', '长度mm', '孔位备注', '数量（根）', '单重kg', '总重kg', '螺栓数量（套）', '螺栓重量kg']];
        foreach ($result['merged'] as $row) {
            $rows[] = [$row['kind'], $row['spec'], $row['length_mm'], self::remark($row['hole']), $row['quantity'],
                $row['accounted_unit_weight_kg'] ?? '待补', $row['accounted_weight_kg'] ?? '待补', $row['bolt_quantity'] ?? '待补', $row['bolt_weight_kg'] ?? '待补'];
        }
        $end = count($rows);
        foreach ([['钢柱小计', '钢柱'], [$result['bracing_complete'] ? '柱撑小计' : '柱撑已计小计', '柱撑'], [$result['bracing_complete'] ? '合计' : '已计小计', null]] as [$label, $kind]) {
            $items = $kind === null ? $result['merged'] : array_values(array_filter($result['merged'], fn ($row) => $row['kind'] === $kind));
            if ($items) {
                $rows[] = [$label, '', '', '', $this->subtotal($items, 'quantity'), '', $this->subtotal($items, 'accounted_weight_kg'), $this->subtotal($items, 'bolt_quantity'), $this->subtotal($items, 'bolt_weight_kg')];
            }
        }
        $rows[] = [$result['bracing_complete'] ? '含螺栓合计重量' : '已计含螺栓合计重量', '', '', '', '', '',
            ($result['bracing_complete'] ? $result['estimated_with_bolts_weight_kg'] : $result['accounted_with_bolts_weight_kg']) ?? '待补重量', '', ''];

        return $this->workbook([['name' => $result['bracing_complete'] ? '构件明细' : '构件明细（部分）', 'rows' => $rows, 'weights' => [5, 6, 8], 'detailEnd' => $end]]);
    }

    public function library(array $db): string
    {
        $partRows = [['规格', '长度（mm）', '重量（kg）', '配套螺栓（M22*80）', '备注']];
        $extra = array_any($db['parts'], fn ($p) => $p['kind'] === '柱撑' && ($p['note'] ?? '') !== '' && ! in_array($p['note'], ['带中孔', '未注明中孔'], true));
        if ($extra) {
            $partRows[0][] = '补充备注';
        }
        $parts = $db['parts'];
        usort($parts, fn ($a, $b) => ($a['kind'] === '钢柱' ? 0 : 1) <=> ($b['kind'] === '钢柱' ? 0 : 1)
            ?: strcmp($a['spec'], $b['spec']) ?: $a['length_mm'] <=> $b['length_mm'] ?: strcmp($a['hole'], $b['hole']));
        foreach ($parts as $p) {
            $row = [$p['spec'], $p['length_mm'], $p['unit_weight_kg'] ?? '', $p['bolts_per_piece_raw'] ?? '',
                $p['kind'] === '柱撑' ? ($p['hole'] === '带中孔' ? '带中孔' : '') : ($p['note'] ?? '')];
            if ($extra) {
                $row[] = $p['kind'] === '柱撑' ? ($p['note'] ?? '') : '';
            }
            $partRows[] = $row;
        }
        $frameRows = [['用途', "钢柱规格\n（直径-高度）", '柱间距(m)', '柱撑长度（mm）', '数量', '备注']];
        $frames = $db['frames'];
        usort($frames, fn ($a, $b) => strcmp($a['support_use'], $b['support_use']) ?: $a['diameter_mm'] <=> $b['diameter_mm'] ?: $a['height_mm'] <=> $b['height_mm'] ?: $a['spacing_mm'] <=> $b['spacing_mm']);
        foreach ($frames as $f) {
            $lines = array_values(array_filter($db['composition'], fn ($d) => $d['frame_id'] === $f['id']));
            usort($lines, fn ($a, $b) => $a['length_mm'] <=> $b['length_mm'] ?: strcmp($a['hole'], $b['hole']));
            foreach ($lines as $d) {
                $frameRows[] = [$f['support_use'], 'φ'.$f['diameter_mm'].'-'.($f['height_mm'] / 1000).'m', $f['spacing_mm'] / 1000,
                    $d['length_mm'], $d['per_frame'], $d['hole'] === '带中孔' ? '带中孔' : ''];
            }
        }

        return $this->workbook([['name' => '构件库', 'rows' => $partRows, 'weights' => [2], 'detailEnd' => count($partRows)],
            ['name' => '支架搭配', 'rows' => $frameRows, 'weights' => [], 'detailEnd' => count($frameRows)]]);
    }

    public function html(SupportLibraryVersion $version): string
    {
        $html = file_get_contents(resource_path('support-library/offline.html'));
        $payload = $version->payload_json;
        unset($payload['source']['original_path']);
        $payload['platform_snapshot'] = ['revision' => $version->revision, 'rule_version' => $version->rule_version, 'created_at' => $version->created_at->toIso8601String()];
        $start = strpos($html, '/*LIBRARY_START*/');
        $end = strpos($html, '/*LIBRARY_END*/', $start);
        if ($start === false || $end === false) {
            throw new SupportLibraryException('离线备份模板缺失，无法生成。', 'EXPORT_FAILED', 500);
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $html = substr($html, 0, $start).'/*LIBRARY_START*/let DB='.$json.';'.substr($html, $end);
        $banner = '<p style="padding:12px;background:#fff4dd;color:#151b22">共享支架库版本 '.$version->revision.'；这是离线快照，离线修改不会自动同步平台。导出副本后平台权限撤回不能撤回此文件。</p>';

        return str_replace('<main id="top">', '<main id="top">'.$banner, $html);
    }

    public function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'support-xlsx-');
        $zip = new ZipArchive;
        try {
            if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new SupportLibraryException('无法创建Excel临时文件。', 'EXPORT_FAILED', 500);
            }
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = '<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($sheets as $i => $sheet) {
                $n = $i + 1;
                $workbook .= '<sheet name="'.$this->escape($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
                $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $this->put($zip, 'xl/worksheets/sheet'.$n.'.xml', $this->sheet($sheet, $ns));
            }
            $this->put($zip, '[Content_Types].xml', $types.'</Types>');
            $this->put($zip, 'xl/workbook.xml', $workbook.'</sheets></workbook>');
            $this->put($zip, 'xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $this->put($zip, '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $this->put($zip, 'xl/styles.xml', '<styleSheet xmlns="'.$ns.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.0"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Microsoft YaHei"/></font><font><b/><sz val="11"/><name val="Microsoft YaHei"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            if (! $zip->close()) {
                throw new SupportLibraryException('Excel文件生成失败。', 'EXPORT_FAILED', 500);
            }

            return file_get_contents($path);
        } finally {
            if ($zip->filename !== '') {
                $zip->close();
            }
            if ($path !== false) {
                unlink($path);
            }
        }
    }

    private function sheet(array $sheet, string $ns): string
    {
        $width = count($sheet['rows'][0]);
        $last = self::column($width - 1);
        $xml = '<worksheet xmlns="'.$ns.'"><dimension ref="A1:'.$last.count($sheet['rows']).'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.$width.'" width="22" customWidth="1"/></cols><sheetData>';
        foreach ($sheet['rows'] as $i => $row) {
            $xml .= '<row r="'.($i + 1).'">';
            foreach ($row as $column => $value) {
                $style = $i === 0 ? 1 : (in_array($column, $sheet['weights'], true) ? 2 : 0);
                $ref = self::column($column).($i + 1);
                $xml .= '<c r="'.$ref.'" s="'.$style.'"';
                if (is_int($value) || is_float($value)) {
                    $xml .= '><v>'.$value.'</v></c>';
                } else {
                    $xml .= ' t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value ?? '').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData><autoFilter ref="A1:'.$last.$sheet['detailEnd'].'"/><pageSetup orientation="landscape" paperSize="9"/></worksheet>';
    }

    private function subtotal(array $items, string $key): float|string
    {
        $values = array_column($items, $key);
        $sum = SupportLibraryMath::sum($values, true);

        return $sum === null ? '待补' : (in_array(null, $values, true) ? '已知 '.$sum : $sum);
    }

    public static function remark(?string $value): string
    {
        return ! $value || in_array($value, ['未注明中孔', '—'], true) ? '/' : $value;
    }

    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string|int|float $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', '', (string) $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function put(ZipArchive $zip, string $path, string $xml): void
    {
        if (! $zip->addFromString($path, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) {
            throw new SupportLibraryException('Excel内部文件写入失败。', 'EXPORT_FAILED', 500);
        }
    }
}
