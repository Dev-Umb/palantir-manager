<?php

namespace App\Support;

use App\Models\StoredAttachment;
use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use UnexpectedValueException;

class InvoicePdfRecognition
{
    public function __construct(private StandardInvoiceParser $parser) {}

    /** @return array<string, mixed> */
    public function recognize(StoredAttachment $attachment, ?Closure $observe = null): array
    {
        $directory = sys_get_temp_dir().'/invoice-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new UnexpectedValueException('无法创建识别工作目录，请稍后重试。');
        }
        $deadline = microtime(true) + 16;
        $stage = 'invoice_read';
        $started = hrtime(true);
        $success = false;
        try {
            $source = Storage::disk($attachment->disk)->readStream($attachment->object_key);
            if (! is_resource($source)) {
                throw new UnexpectedValueException('发票文件暂时无法读取。');
            }
            $file = $directory.'/invoice.pdf';
            $target = fopen($file, 'w+b');
            try {
                $bytes = stream_copy_to_stream($source, $target, 20 * 1024 * 1024 + 1);
            } finally {
                fclose($source);
                fclose($target);
            }
            if ($bytes !== $attachment->size || ! hash_equals($attachment->sha256, hash_file('sha256', $file))) {
                throw new UnexpectedValueException('发票文件完整性校验失败，请重新上传。');
            }
            $observe?->__invoke($stage, (hrtime(true) - $started) / 1e6, true);
            $stage = 'invoice_parse';
            $started = hrtime(true);
            $info = $this->run(['pdfinfo', $file], $deadline);
            if (! preg_match('/^Pages:\s+(\d+)$/m', $info, $pages) || (int) $pages[1] < 1 || (int) $pages[1] > 5) {
                throw new UnexpectedValueException('仅支持 1–5 页的单张发票 PDF，请拆分后重试。');
            }
            $text = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $file, '-'], $deadline);
            $method = 'text';
            $texts = explode("\f", $text);
            for ($page = 1; $page <= (int) $pages[1]; $page++) {
                if (mb_strlen(preg_replace('/\s/u', '', $texts[$page - 1] ?? '')) >= 30) {
                    continue;
                }
                $method = 'ocr';
                $prefix = $directory.'/page-'.$page;
                $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-png', $file, $prefix], $deadline);
                $texts[$page - 1] = $this->run(['tesseract', $prefix.'.png', 'stdout', '-l', 'chi_sim+eng', '--psm', '6', '-c', 'preserve_interword_spaces=1'], $deadline);
            }

            $result = $this->parser->parse(implode("\n", $texts));
            if ($method === 'ocr') {
                $result['warnings'][] = '扫描识别的文字与数字可能有误，请逐项核对原件。';
            }

            $success = true;

            return [...$result, 'method' => $method];
        } catch (\Throwable $error) {
            if ($error instanceof UnexpectedValueException && ! $error instanceof ExceptionInterface) {
                throw $error;
            }
            throw new UnexpectedValueException('识别超时或工具不可用，请重试或手动填写。');
        } finally {
            $observe?->__invoke($stage, (hrtime(true) - $started) / 1e6, $success);
            foreach (glob($directory.'/*') as $temporary) {
                unlink($temporary);
            }
            rmdir($directory);
        }
    }

    /** @param array<int, string> $command */
    private function run(array $command, float $deadline): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new UnexpectedValueException('识别超时，请重试或手动填写。');
        }
        $result = Process::timeout($remaining)->env(['OMP_THREAD_LIMIT' => '1'])->run($command);
        if (! $result->successful()) {
            throw new UnexpectedValueException('发票无法解析或识别工具不可用，请手动填写或稍后重试。');
        }
        $output = $result->output();
        if (strlen($output) > 2 * 1024 * 1024) {
            throw new UnexpectedValueException('PDF 内容过多，请拆分后重试。');
        }

        return $output;
    }
}
