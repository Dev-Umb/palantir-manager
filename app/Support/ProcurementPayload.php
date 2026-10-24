<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProcurementPayload
{
    public function validate(array $payload): array
    {
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 2097152) {
            throw ValidationException::withMessages(['payload' => 'JSON 最大 2 MiB。']);
        }
        $rules = ['schema_version' => ['sometimes', 'integer', 'in:1'], 'title' => ['required', 'string', 'max:500'], 'date' => ['required', 'date_format:Y-m-d'], 'window' => ['required', 'string', 'max:20000'], 'scope' => ['required', 'string', 'max:20000'], 'items' => ['required', 'array', 'min:1', 'max:200'], 'coverage' => ['present', 'array', 'max:200'], 'exclusions' => ['present', 'array', 'max:200'], 'limitations' => ['present', 'array', 'max:200'], 'limitations.*' => ['string', 'max:20000'], 'coverage.*' => ['array', 'size:4'], 'coverage.*.*' => ['string', 'max:20000'], 'exclusions.*' => ['array', 'size:2'], 'exclusions.*.*' => ['string', 'max:20000'], 'items.*' => ['array']];
        foreach (['id', 'title', 'category', 'priority', 'unit', 'code', 'published', 'registration', 'deadline', 'quantity', 'material', 'contacts', 'judgment', 'terms', 'url', 'platform', 'status', 'issues', 'nature', 'subtype'] as $key) {
            $rules['items.*.'.$key] = ['present', 'string', 'max:20000'];
        }
        foreach (['id' => 255, 'title' => 500, 'platform' => 100, 'nature' => 100, 'code' => 500] as $key => $max) {
            $rules['items.*.'.$key] = ['required', 'string', 'max:'.$max];
        }
        $rules['items.*.id'][] = 'distinct:strict';
        $rules['items.*.priority'] = ['required', 'in:P1,P2,P3,待核实'];
        $rules['items.*.status'] = ['required', 'in:纳入,待核实'];
        $rules['items.*.url'] = ['required', 'url:http,https', 'max:10000'];
        foreach (['invited', 'framework'] as $key) {
            $rules['items.*.'.$key] = ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('必须为 JSON 布尔值。');
                }
            }];
        }
        $rules['items.*.evidence'] = ['present', 'array', 'max:200'];
        $rules['items.*.evidence.*'] = ['string', 'max:500'];
        Validator::make($payload, $rules)->validate();

        return $payload;
    }

    public function hash(array $payload): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            } if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function contentHash(array $item): string
    {
        unset($item['id']);

        return $this->hash($item);
    }

    public function timestamp(string $text): array
    {
        if (! preg_match('/(20\d{2})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/u', $text, $m)) {
            return ['value' => null, 'date' => null, 'precision' => 'unknown'];
        }
        try {
            $d = CarbonImmutable::createSafe((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), 'Asia/Shanghai');
        } catch (\Throwable) {
            return ['value' => null, 'date' => null, 'precision' => 'invalid'];
        }

        return ['value' => isset($m[4]) ? $d->format('Y-m-d H:i:s') : null, 'date' => $d->toDateString(), 'precision' => isset($m[6]) ? 'second' : (isset($m[4]) ? 'minute' : 'date')];
    }

    public function dates(array $item): array
    {
        $registration = $this->timestamp($item['registration']);
        $fileOnly = str_contains($item['registration'], '报名未单列') || (str_contains($item['registration'], '文件') && ! str_contains($item['registration'], '报名'));

        return ['published' => $this->timestamp($item['published']), 'registration' => $fileOnly ? ['value' => null, 'date' => null, 'precision' => 'unknown'] : $registration, 'file_acquisition' => str_contains($item['registration'], '文件') ? $registration : null, 'deadline' => $this->timestamp($item['deadline'])];
    }

    public function warnings(array $item): array
    {
        $warnings = [];
        if ($item['issues'] !== '') {
            $warnings[] = $item['issues'];
        }
        if ($item['evidence']) {
            $warnings[] = '证据文件仅提供名称，未上传原文件；采集内容未经平台原文核验。';
        }
        if ($this->timestamp($item['published'])['precision'] === 'date') {
            $warnings[] = '发布时间仅精确到日期，不能确认精确时间窗口。';
        }
        $warnings[] = '报价/投标截止类型按采集原文展示，不推断；AI初判不代表已具备投标资格。';
        $known = ['id', 'title', 'category', 'priority', 'unit', 'code', 'published', 'registration', 'deadline', 'quantity', 'material', 'contacts', 'judgment', 'terms', 'url', 'platform', 'status', 'issues', 'nature', 'subtype', 'invited', 'framework', 'evidence'];
        if ($extra = array_diff(array_keys($item), $known)) {
            $warnings[] = '未映射扩展字段已保留：'.implode('、', $extra);
        }

        return $warnings;
    }
}
