<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class FixedQuotationDocument
{
    public const TEMPLATE_SHA256 = '318358a2c520df0f50a0e2c950fee4304b1f695cf04f90ea6b1840e7da75a795';

    /** @param array{title:string,date:string,contact:string,phone:string,items:array} $data */
    public function generate(array $data): string
    {
        $template = resource_path('quotation-template.docx');
        if (hash_file('sha256', $template) !== self::TEMPLATE_SHA256) {
            throw new RuntimeException('报价模板校验失败，请联系管理员。');
        }
        $path = tempnam(sys_get_temp_dir(), 'fixed-quote-');
        if ($path === false) {
            throw new RuntimeException('无法创建报价文件。');
        }
        try {
            copy($template, $path);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('无法读取报价模板。');
            }
            try {
                $document = new DOMDocument;
                $document->preserveWhiteSpace = true;
                $document->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $body = '/w:document/w:body';
                $table = $body.'/w:tbl[1]';
                $this->fill($xpath, $body.'/w:p[1]', $data['title']);
                [$year, $month, $day] = explode('-', $data['date']);
                $date = $year.' 年 '.(int) $month.' 月 '.(int) $day.' 日';
                $this->fill($xpath, $table.'/w:tr[1]/w:tc[4]', $date);
                $this->fill($xpath, $table.'/w:tr[3]/w:tc[2]', $data['contact']);
                $this->fill($xpath, $table.'/w:tr[3]/w:tc[4]', $data['phone']);
                $this->fill($xpath, $body.'/w:p[8]', '日期：'.$date);
                $row = $xpath->query($table.'/w:tr[7]')->item(0);
                $prototype = $row->cloneNode(true);
                foreach ($data['items'] as $index => $item) {
                    $current = $index === 0 ? $row : $row->parentNode->appendChild($prototype->cloneNode(true));
                    $values = [(string) ($index + 1), $item['name'], $this->price($item['material_price'] ?? null, $item['unit']), $this->price($item['processing_price'] ?? null, $item['unit']), $this->price($item['price'], $item['unit'])];
                    foreach ($values as $column => $value) {
                        $cell = $xpath->query('./w:tc', $current)->item($column);
                        $this->replaceText($xpath, $cell, $value);
                    }
                }
                if (count($data['items']) > 1) {
                    $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
                    $offset = $xpath->query('//wp:anchor/wp:positionV/wp:posOffset')->item(0);
                    $offset->textContent = (string) ((int) $offset->textContent + (count($data['items']) - 1) * 592 * 635);
                }
                $this->blackenText($xpath);
                if (! $zip->addFromString('word/document.xml', $document->saveXML())) {
                    throw new RuntimeException('无法生成报价文件。');
                }
            } finally {
                $zip->close();
            }
            $check = new ZipArchive;
            if ($check->open($path, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('生成的报价文件校验失败。');
            }
            $check->close();

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function blackText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quote-black-');
        if ($path === false) {
            throw new RuntimeException('无法创建报价导出文件。');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('报价文件校验失败。');
            }
            try {
                $document = new DOMDocument;
                $document->preserveWhiteSpace = true;
                $document->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                if (! $this->blackenText($xpath)) {
                    return $bytes;
                }
                if (! $zip->addFromString('word/document.xml', $document->saveXML())) {
                    throw new RuntimeException('报价导出失败。');
                }
            } finally {
                $zip->close();
            }

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function blackenText(DOMXPath $xpath): bool
    {
        $changed = false;
        $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        foreach ($xpath->query('//w:rPr/w:color') as $color) {
            if ($color->getAttributeNS($namespace, 'val') !== '000000') {
                $color->setAttributeNS($namespace, 'w:val', '000000');
                $changed = true;
            }
            foreach (['themeColor', 'themeTint', 'themeShade'] as $attribute) {
                if ($color->hasAttributeNS($namespace, $attribute)) {
                    $color->removeAttributeNS($namespace, $attribute);
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function price(mixed $amount, string $unit): string
    {
        return $amount === null || $amount === '' ? '—' : (string) $amount.'元/'.$unit;
    }

    private function fill(DOMXPath $xpath, string $path, string $value): void
    {
        $node = $xpath->query($path)->item(0);
        if (! $node instanceof DOMElement) {
            throw new RuntimeException('报价模板字段缺失。');
        }
        $this->replaceText($xpath, $node, $value);
    }

    private function replaceText(DOMXPath $xpath, DOMElement $node, string $value): void
    {
        $texts = $xpath->query('.//w:t', $node);
        if ($texts->length === 0) {
            throw new RuntimeException('报价模板文本字段缺失。');
        }
        foreach ($texts as $index => $text) {
            $text->textContent = $index === 0 ? $value : '';
        }
    }
}
