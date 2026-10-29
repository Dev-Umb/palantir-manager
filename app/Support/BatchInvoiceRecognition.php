<?php

namespace App\Support;

use App\Ai\Agents\BatchInvoiceAgent;
use App\Models\StoredAttachment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Files\Image;
use UnexpectedValueException;

class BatchInvoiceRecognition
{
    public function __construct(private InvoiceMemoryWorkspace $workspace) {}

    public function recognize(StoredAttachment $file): array
    {
        return $this->workspace->run(function (string $dir) use ($file): array {
            $input = Storage::disk($file->disk)->readStream($file->object_key);
            if (! is_resource($input)) {
                throw new \RuntimeException('invoice_read_failed');
            }
            $path = $dir.'/source';
            $output = fopen($path, 'wb');
            try {
                $size = stream_copy_to_stream($input, $output, 20971521);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($size !== $file->size || ! hash_equals($file->sha256, hash_file('sha256', $path))) {
                throw new UnexpectedValueException('文件完整性校验失败，请重新上传。');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $text = '';
            $paths = [$path];
            if ($mime === 'application/pdf') {
                $info = Process::timeout(10)->run(['pdfinfo', $path]);
                if (! $info->successful() || ! preg_match('/^Pages:\s+(\d+)/m', $info->output(), $match) || (int) $match[1] < 1 || (int) $match[1] > 5) {
                    throw new UnexpectedValueException('仅支持 1–5 页的单张发票 PDF，请拆分后重传。');
                }
                $plain = Process::timeout(15)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
                if ($plain->successful()) {
                    $text = mb_substr($plain->output(), 0, 20000);
                }
                $render = Process::timeout(30)->run(['pdftoppm', '-jpeg', '-jpegopt', 'quality=75', '-scale-to', '2000', $path, $dir.'/page']);
                $paths = glob($dir.'/page-*.jpg');
                natsort($paths);
                $paths = array_values($paths);
                if (! $render->successful() || count($paths) !== (int) $match[1]) {
                    throw new UnexpectedValueException('PDF 页面无法读取，请检查文件。');
                }
            } elseif (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw new UnexpectedValueException('仅支持 PDF、JPG、PNG 发票。');
            }
            if (array_sum(array_map('filesize', $paths)) > 20 * 1024 * 1024) {
                throw new UnexpectedValueException('图片内容过大，请降低分辨率后重传。');
            }
            $response = BatchInvoiceAgent::make()->prompt('请核对附件中的单张发票。以下文本仅作辅助，金额须与原件一致：'.$text, attachments: array_map(fn ($p) => Image::fromPath($p), $paths), provider: config('ai.default'), timeout: 100);
            $r = Validator::make(json_decode($response->text, true, flags: JSON_THROW_ON_ERROR), [
                'supported' => ['required', 'boolean'], 'invoice_count' => ['required', 'integer', 'min:0'],
                'type' => ['required', Rule::in(config('reimbursements.types'))], 'uncertain' => ['required', 'boolean'],
                'amount' => ['present', 'nullable', 'regex:/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/'],
                'invoice_date' => ['present', 'nullable', 'date_format:Y-m-d'],
                'invoice_number' => ['present', 'nullable', 'string', 'max:100'], 'invoice_code' => ['present', 'nullable', 'string', 'max:100'],
                'buyer' => ['present', 'nullable', 'string', 'max:150'], 'seller' => ['present', 'nullable', 'string', 'max:150'],
                'description' => ['present', 'string', 'max:300'], 'warnings' => ['present', 'array', 'max:10'], 'warnings.*' => ['string', 'max:200'],
                'evidence' => ['present', 'array', 'max:10'], 'evidence.*' => ['string', 'max:300'],
            ])->validate();
            if (! $r['supported'] || $r['invoice_count'] !== 1) {
                throw new UnexpectedValueException('未识别为单张人民币发票，合并 PDF 请拆分后上传。');
            }
            $lines = [$r['description']];
            foreach (['invoice_number' => '发票号码', 'invoice_code' => '发票代码', 'buyer' => '购买方', 'seller' => '销售方'] as $key => $label) {
                if ($r[$key]) {
                    $lines[] = $label.'：'.$r[$key];
                }
            }
            $r['description'] = mb_substr(implode("\n", $lines), 0, 500);
            if ($r['uncertain']) {
                $r['type'] = '其他';
            }

            return $r;
        });
    }
}
