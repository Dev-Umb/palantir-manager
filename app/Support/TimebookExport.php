<?php

namespace App\Support;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

class TimebookExport
{
    public function make(array $result): string
    {
        $summary = [['姓名', '累计工日（天）', '加班（小时）', '记录数', '首次日期', '最近日期']];
        foreach ($result['summary'] as $row) {
            $summary[] = [$row['name'], $row['days'], $row['overtime'], $row['count'], $this->date($row['first_day']), $this->date($row['last_day'])];
        }
        $summary[] = ['合计', $result['totals']['days'], $result['totals']['overtime'], $result['totals']['count'], '', ''];
        $records = [['日期', '姓名', '工日（天）', '加班（小时）', '工作项目', '备注', '记录编号', '最后修改时间（UTC）']];
        foreach ($result['records'] as $row) {
            $records[] = [$this->date($row['day']), $row['name'], $row['days'], $row['overtime'], $row['project'], $row['note'], $row['id'], $row['updated_at']];
        }
        $filters = $result['filters'];
        $notes = [['项目', '内容'], ['开始日期', $filters['start'] ?: '不限'], ['结束日期', $filters['end'] ?: '不限'],
            ['姓名搜索', $filters['q'] ?: '全部'], ['精确人员编号', (string) ($filters['worker'] ?: '不限')],
            ['导出时间（UTC）', $result['queried_at']], ['统计说明', '仅统计有效记录；工日按半天、加班按分钟累计，两者不折算。'],
            ['同名说明', '标准化后同名归为一人；同名不同人请在姓名中添加区分文字。']];
        $sheets = ['人员汇总' => $summary, '记工明细' => $records, '导出说明' => $notes];
        $path = tempnam(sys_get_temp_dir(), 'timebook-');
        if ($path === false) {
            throw new RuntimeException('无法创建导出临时文件。');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('无法创建 XLSX。');
            }
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $index = 0;
            foreach ($sheets as $name => $rows) {
                $index++;
                $workbook .= '<sheet name="'.$name.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rels .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
                $this->put($zip, 'xl/worksheets/sheet'.$index.'.xml', $this->sheet($rows, $index === 1));
            }
            $this->put($zip, '[Content_Types].xml', $types.'</Types>');
            $this->put($zip, 'xl/workbook.xml', $workbook.'</sheets></workbook>');
            $this->put($zip, 'xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $this->put($zip, '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $this->put($zip, 'xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="0.##"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            if (! $zip->close()) {
                throw new RuntimeException('XLSX 写入失败。');
            }
            $data = file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException('XLSX 读取失败。');
            }

            return $data;
        } finally {
            if ($zip->status === ZipArchive::ER_OK && $zip->filename !== '') {
                $zip->close();
            }
            unlink($path);
        }
    }

    private function date(string $date): array
    {
        return ['excel_date' => (int) (new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable($date))->format('%r%a')];
    }

    private function sheet(array $rows, bool $hasTotal): string
    {
        $lastColumn = chr(64 + count($rows[0]));
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($rows[0]).'" width="22" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($row as $column => $value) {
                $cell = chr(65 + $column).$number;
                if (is_array($value)) {
                    $xml .= '<c r="'.$cell.'" s="1"><v>'.$value['excel_date'].'</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$cell.'" s="2"><v>'.$value.'</v></c>';
                } else {
                    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value);
                    $xml .= '<c r="'.$cell.'" t="inlineStr" s="'.($rowIndex === 0 ? 3 : 0).'"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData><autoFilter ref="A1:'.$lastColumn.max(1, count($rows) - (int) $hasTotal).'"/></worksheet>';
    }

    private function put(ZipArchive $zip, string $path, string $xml): void
    {
        if (! $zip->addFromString($path, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) {
            throw new RuntimeException('XLSX 内容写入失败。');
        }
    }
}
