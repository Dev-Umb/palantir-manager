<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FixedQuotationPdf
{
    public function convert(string $docx): string
    {
        $directory = sys_get_temp_dir().'/quote-pdf-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('无法创建 PDF 转换目录。');
        }
        try {
            file_put_contents($directory.'/quotation.docx', $docx);
            $result = Process::timeout(45)->run([
                config('quotation.pdf_binary', '/usr/bin/libreoffice'),
                '-env:UserInstallation=file://'.$directory.'/profile',
                '--headless', '--convert-to', 'pdf:writer_pdf_Export',
                '--outdir', $directory, $directory.'/quotation.docx',
            ]);
            $path = $directory.'/quotation.pdf';
            if (! $result->successful() || ! is_file($path)) {
                throw new RuntimeException('PDF 转换失败。');
            }
            $bytes = file_get_contents($path);
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF')) {
                throw new RuntimeException('PDF 转换结果校验失败。');
            }

            return $bytes;
        } catch (Throwable $exception) {
            throw new RuntimeException('PDF 转换暂时不可用。', previous: $exception);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
