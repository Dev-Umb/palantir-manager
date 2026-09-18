<?php

namespace App\Integrations\Feishu;

use App\Ai\XycDataAccess;
use App\Models\AuditLog;
use App\Models\FeishuUserBinding;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class FeishuExportService
{
    public function __construct(
        private XycDataAccess $data,
        private FeishuCliClient $cli,
    ) {}

    /** @return array<string, mixed> */
    public function export(User $user, string $format, array $input): array
    {
        if (! config('services.feishu.cli.enabled')) {
            return $this->failure('export_disabled', '飞书文件导出尚未启用。');
        }

        $recipientOpenId = FeishuUserBinding::active()->where('user_id', $user->id)->value('open_id');
        if (blank($recipientOpenId)) {
            return $this->failure('feishu_binding_missing', '当前 Palantir 账号没有有效的飞书绑定，无法授予导出文件访问权限。');
        }

        $input['limit'] = min(
            max((int) ($input['limit'] ?? 50), 1),
            (int) config('services.feishu.cli.max_rows', 200),
        );
        $result = $this->data->queryRecords($user, $input);
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $rows = array_values($result['rows'] ?? []);
        if ($rows === []) {
            return $this->failure('empty_result', '当前权限和筛选条件下没有可导出的记录。');
        }

        try {
            [$headers, $values] = $this->tabularData($result, $rows);
            $title = $this->title($input, $result);
            $file = $format === 'docx'
                ? $this->cli->createDocument($title, $this->documentXml($title, $headers, $values), $recipientOpenId)
                : $this->cli->createSpreadsheet($title, $headers, $values, $recipientOpenId);
        } catch (InvalidArgumentException) {
            return $this->failure('payload_too_large', '导出内容超过飞书文件安全上限，请减少字段或记录数量后重试。');
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('export_failed', '飞书文件创建失败，请联系管理员检查 CLI 配置或应用权限。');
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ai.feishu_export.created',
            'subject_type' => $format,
            'subject_id' => (string) ($file['token'] ?? ''),
            'payload' => [
                'object_key' => data_get($result, 'object.key'),
                'title' => $file['title'],
                'row_count' => count($values),
                'url_hash' => hash('sha256', $file['url']),
                'query_hash' => data_get($result, 'provenance.query_hash'),
            ],
        ]);

        return [
            'ok' => true,
            'file' => $file,
            'record_count' => count($values),
            'message' => "已生成飞书{$this->formatLabel($format)}：{$file['title']}",
            'sources' => $result['sources'] ?? [],
            'provenance' => $result['provenance'] ?? null,
            'data_quality' => $result['data_quality'] ?? [],
        ];
    }

    /** @return array{0: string[], 1: array<int, array<int, scalar|null>>} */
    private function tabularData(array $result, array $rows): array
    {
        $maxColumns = (int) config('services.feishu.cli.max_columns', 20);
        $keys = array_slice(array_keys($rows[0]), 0, $maxColumns);
        $labels = collect($result['fields'] ?? [])->mapWithKeys(fn (array $field): array => [
            (string) ($field['key'] ?? '') => (string) ($field['label'] ?? $field['key'] ?? ''),
        ]);
        $headers = collect($keys)->map(fn (string $key): string => $labels->get($key, $key === 'group' ? '分组' : $key)
        )->all();
        $values = collect($rows)->map(fn (array $row): array => collect($keys)
            ->map(fn (string $key): string|int|float|bool|null => $this->cellValue($row[$key] ?? null))
            ->all())->all();

        $bytes = strlen(json_encode([$headers, $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if ($bytes > (int) config('services.feishu.cli.max_payload_bytes', 200000)) {
            throw new InvalidArgumentException('feishu_export_payload_too_large');
        }

        return [$headers, $values];
    }

    private function cellValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 2000);
    }

    private function title(array $input, array $result): string
    {
        $requested = Str::squish((string) ($input['title'] ?? ''));
        $fallback = (string) data_get($result, 'object.label', 'Palantir 数据')
            .'导出-'.now('Asia/Taipei')->format('Ymd-His');

        return Str::limit($requested !== '' ? $requested : $fallback, 80, '');
    }

    private function documentXml(string $title, array $headers, array $rows): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $head = collect($headers)->map(fn (string $header): string => '<th background-color="light-blue">'.$escape($header).'</th>'
        )->implode('');
        $body = collect($rows)->map(fn (array $row): string => '<tr>'.collect($row)
            ->map(fn (mixed $value): string => '<td>'.$escape($value).'</td>')->implode('').'</tr>')->implode('');

        return '<doc><h1>'.$escape($title).'</h1><p>导出时间：'.$escape(now('Asia/Taipei')->format('Y-m-d H:i:s'))
            .'</p><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></doc>';
    }

    /** @return array{ok: false, error: string, message: string, record_count: int} */
    private function failure(string $error, string $message): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message, 'record_count' => 0];
    }

    private function formatLabel(string $format): string
    {
        return $format === 'docx' ? '云文档' : '电子表格';
    }
}
