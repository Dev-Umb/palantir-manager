<?php

namespace App\Support;

use App\Ai\Agents\ReimbursementEvidenceAgent;
use App\Models\StoredAttachment;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Files\Image;
use UnexpectedValueException;

class ReimbursementEvidenceRecognition
{
    public function __construct(private InvoiceMemoryWorkspace $workspace) {}

    public function normalize(array $response, array $pages, bool $skipNonEvidence = false): array
    {
        $data = Validator::make($response, [
            'skipped_pages' => ['sometimes', 'array', 'max:50'],
            'skipped_pages.*' => ['required', 'integer', 'distinct', Rule::in($pages)],
            'items' => ['present', 'array', 'max:100'],
            'items.*.page' => ['required', 'integer', Rule::in($pages)],
            'items.*.region' => ['required', 'string', 'max:100'],
            'items.*.type' => ['sometimes', Rule::in(config('reimbursements.types'))],
            'items.*.kind' => ['required', Rule::in(['invoice', 'receipt', 'payment'])],
            'items.*.status' => ['required', Rule::in(['issued', 'paid', 'unpaid', 'failed', 'refunded', 'unknown'])],
            'items.*.currency' => ['required', 'string', 'max:10'],
            'items.*.amount' => ['present', 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/'],
            'items.*.date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'items.*.number' => ['present', 'nullable', 'string', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.uncertain' => ['required', 'boolean'],
            'items.*.warnings' => ['present', 'array', 'max:10'],
            'items.*.warnings.*' => ['string', 'max:200'],
            'page_issues' => ['present', 'array', 'max:100'],
            'page_issues.*.page' => ['required', 'integer', Rule::in($pages)],
            'page_issues.*.message' => ['required', 'string', 'max:300'],
        ])->validate();
        $items = [];
        $issues = $data['page_issues'];
        $counts = [];
        $numbers = [];
        foreach ($data['items'] as $item) {
            $page = $item['page'];
            $counts[$page] = ($counts[$page] ?? 0) + 1;
            $amount = $item['amount'];
            $warnings = $item['warnings'];
            $parts = explode('.', $amount ?? '0');
            $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            if ($item['uncertain'] || $item['currency'] !== 'CNY' || $cents <= 0 || ! in_array($item['status'], match ($item['kind']) {
                'invoice' => ['issued'], 'receipt' => ['issued', 'paid'], default => ['paid']
            }, true)) {
                $amount = null;
                $warnings[] = '金额不确定、非人民币或不是有效支出，请手工核对。';
            } else {
                $amount = sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
            }
            if ($item['kind'] !== 'invoice') {
                $warnings[] = '请核对是否与发票重复计费，再确认此项支出。';
            }
            if ($item['number'] && isset($numbers[$item['number']])) {
                $amount = null;
                $warnings[] = '识别到重复凭证号，请核对是否为同一票据的重复联或截图。';
            }
            if ($item['number']) {
                $numbers[$item['number']] = true;
            }
            if (! $item['number']) {
                $warnings[] = '未识别凭证号码，请核对是否重复。';
            }
            $items[] = [
                'id' => $page.'-'.$counts[$page], 'page' => $page, 'region' => $item['region'],
                'type' => $item['type'] ?? '其他', 'kind' => $item['kind'], 'amount' => $amount, 'number' => $item['number'],
                'invoice_date' => $item['kind'] === 'invoice' ? $item['date'] : null,
                'payment_date' => $item['kind'] !== 'invoice' ? $item['date'] : null,
                'description' => $item['description'].($item['number'] ? "\n凭证号码：".$item['number'] : '').($item['kind'] !== 'invoice' && $item['date'] ? "\n支付日期：".$item['date'] : ''),
                'warnings' => $warnings,
            ];
            if ($warnings) {
                $issues[] = ['page' => $page, 'message' => $item['region'].'：'.implode('；', $warnings)];
            }
        }
        foreach ($pages as $page) {
            if (! isset($counts[$page]) && ! ($skipNonEvidence && in_array($page, $data['skipped_pages'] ?? [], true)) && ! collect($issues)->contains('page', $page)) {
                $issues[] = ['page' => $page, 'message' => '此页未识别到凭证，请核对原件。'];
            }
        }

        return ['items' => $items, 'issues' => $issues];
    }

    public function recognize(StoredAttachment $attachment, array $previous = [], bool $skipNonEvidence = false): array
    {
        return $this->workspace->run(function (string $directory) use ($attachment, $previous, $skipNonEvidence): array {
            $source = Storage::disk($attachment->disk)->readStream($attachment->object_key);
            if (! is_resource($source)) {
                throw new UnexpectedValueException('附件无法读取。');
            }
            $path = $directory.'/source';
            $output = fopen($path, 'wb');
            try {
                $size = stream_copy_to_stream($source, $output, 20971521);
            } finally {
                fclose($source);
                fclose($output);
            }
            if ($size !== $attachment->size || ! hash_equals($attachment->sha256, hash_file('sha256', $path))) {
                throw new UnexpectedValueException('附件完整性校验失败。');
            }
            $pages = 1;
            if ($attachment->mime_type === 'application/pdf') {
                $info = $this->run(['pdfinfo', $path]);
                if (! preg_match('/^Pages:\s+(\d+)/m', $info, $match) || (int) $match[1] < 1 || (int) $match[1] > 50) {
                    throw new UnexpectedValueException('每份 PDF 支持 1–50 页。');
                }
                $pages = (int) $match[1];
            } elseif (! in_array($attachment->mime_type, ['image/jpeg', 'image/png'], true)) {
                throw new UnexpectedValueException('仅支持 PDF、JPG、PNG。');
            }
            $done = $previous['done_pages'] ?? [];
            $selected = array_slice(array_values(array_diff(range(1, $pages), $done)), 0, 5);
            $images = [];
            $imagePages = [];
            $issues = $previous['issues'] ?? [];
            foreach ($selected as $page) {
                try {
                    $image = $path;
                    if ($attachment->mime_type === 'application/pdf') {
                        $prefix = $directory.'/page-'.$page;
                        $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2200', '-jpeg', '-jpegopt', 'quality=85', $path, $prefix]);
                        $image = $prefix.'.jpg';
                    }
                    $images[] = Image::fromPath($image);
                    $imagePages[] = $page;
                } catch (UnexpectedValueException $error) {
                    $issues[] = ['page' => $page, 'message' => $error->getMessage()];
                }
            }
            $items = $previous['items'] ?? [];
            if ($images) {
                $response = ReimbursementEvidenceAgent::make(skipNonEvidence: $skipNonEvidence)->prompt('按附件顺序对应原文件页码：'.implode('、', $imagePages).'。输出这些原始页码，逐页提取所有独立凭证。', attachments: $images, provider: 'aimon', timeout: 100);
                $result = $this->normalize(json_decode($response->text, true, flags: JSON_THROW_ON_ERROR), $imagePages, $skipNonEvidence);
                foreach ($result['items'] as $item) {
                    $index = collect($items)->search(fn ($old) => $old['id'] === $item['id']);
                    if ($index === false) {
                        $items[] = $item;
                    } elseif ($items[$index]['amount'] === null) {
                        $items[$index] = $item;
                    }
                }
                $issues = [...$issues, ...$result['issues']];
            }
            $done = array_values(array_unique([...$done, ...$selected]));
            $complete = count($done) === $pages;

            return ['status' => ! $complete ? 'processing' : ($issues ? 'attention' : 'completed'), 'items' => $items, 'issues' => $issues, 'pages' => $pages, 'done_pages' => $done];
        });
    }

    private function run(array $command): string
    {
        try {
            $result = Process::timeout(10)->run($command);
        } catch (ProcessTimedOutException $error) {
            throw new UnexpectedValueException('此页读取超时，请重试或手工补录。', previous: $error);
        }
        if (! $result->successful()) {
            throw new UnexpectedValueException('页面无法读取，请重试或手工补录。');
        }

        return $result->output();
    }
}
