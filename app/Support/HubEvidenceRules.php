<?php

namespace App\Support;

use App\Models\HubEvidence;
use App\Models\HubNotice;
use Illuminate\Support\Collection;
use RuntimeException;

class HubEvidenceRules
{
    public const FIELDS = ['title', 'buyer', 'group_name', 'project_code', 'lot', 'round', 'region', 'product',
        'published_at', 'deadline', 'requirements', 'procurement_content', 'amount', 'quantity', 'unit', 'spec', 'tax', 'freight', 'rental_period', 'supplier', 'currency', 'procurement_scope'];

    public function normalize(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower($value));
    }

    public function validateExtraction(array $data, string $text): array
    {
        if (! ($data['is_notice'] ?? false)) {
            throw new RuntimeException('该页面不是可识别的采购公告。');
        }
        $quotes = collect($data['citations'] ?? [])->keyBy('field');
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if (mb_strlen($value) > (in_array($field, ['requirements', 'procurement_content'], true) ? 3000 : ($field === 'title' ? 500 : 255))) {
                throw new RuntimeException('字段 '.$field.' 超过长度限制，需核实提取范围。');
            }
            if ($value === '') {
                continue;
            }
            $quote = $quotes->get($field)['quote'] ?? '';
            if ($quote === '' || ! str_contains($this->normalize($text), $this->normalize($quote))
                || ! str_contains($this->normalize($quote), $this->normalize($value))) {
                throw new RuntimeException('字段 '.$field.' 缺少原文支持，不能入库。');
            }
        }
        if (empty($data['title']) || ! in_array($data['kind'] ?? '', ['notice', 'amendment', 'candidate', 'award', 'termination', 'intent'], true)) {
            throw new RuntimeException('公告标题或类型无效。');
        }

        return $data;
    }

    public function numeric(string $raw): ?float
    {
        $raw = str_replace([',', '，', ' '], '', $raw);
        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(万元|元|吨|平方米|套|件)?$/u', $raw, $matches)) {
            return null;
        }

        return (float) $matches[1] * (($matches[2] ?? '') === '万元' ? 10000 : 1);
    }

    /** @return array{groups:array, excluded:array} */
    public function prices(Collection $notices): array
    {
        $groups = [];
        $excluded = [];
        foreach ($notices as $notice) {
            $facts = $notice->facts;
            $amount = $this->numeric($facts['amount'] ?? '');
            $quantity = $this->numeric($facts['quantity'] ?? '');
            $required = ['unit', 'spec', 'tax', 'freight', 'transaction', 'amount_type'];
            if (($facts['transaction'] ?? '') === 'rental') {
                $required[] = 'rental_period';
            }
            if (! empty($facts['_conflicts']) || $amount === null || $quantity === null || $quantity <= 0
                || ! preg_match('/(?:元|万元)$/u', $facts['amount'] ?? '')
                || ! $notice->product || ! $notice->region || ! $notice->published_at
                || collect($required)->contains(fn ($key) => empty($facts[$key]) || $facts[$key] === 'unknown')
                || ! in_array($notice->kind, ['award', 'candidate'], true)
                || (($notice->kind === 'candidate') !== (($facts['amount_type'] ?? '') === 'candidate'))
                || ! in_array($facts['amount_type'] ?? '', ['award', 'candidate'], true)) {
                $excluded[] = ['notice_id' => $notice->id, 'reason' => '价格、数量、成交阶段或税运规格口径不完整，不计算可比单价。'];

                continue;
            }
            $key = hash('sha256', json_encode([$notice->product, $notice->region, $notice->published_at?->format('Y'), ...collect($required)->map(fn ($k) => $facts[$k])->all()], JSON_UNESCAPED_UNICODE));
            $groups[$key]['basis'] = array_merge(['product' => $notice->product, 'region' => $notice->region, 'year' => $notice->published_at?->format('Y')], array_intersect_key($facts, array_flip($required)));
            $groups[$key]['samples'][] = ['notice_id' => $notice->id, 'total' => $amount, 'quantity' => $quantity, 'unit_price' => round($amount / $quantity, 2)];
        }
        foreach ($groups as &$group) {
            $prices = collect($group['samples'])->pluck('unit_price')->sort()->values();
            $group['count'] = $prices->count();
            $group['min'] = $prices->min();
            $group['max'] = $prices->max();
            $group['median'] = $prices->median();
        }

        return ['groups' => array_values($groups), 'excluded' => $excluded];
    }

    public function reportErrors(array $report, array $snapshot): array
    {
        $errors = [];
        if (array_key_exists('verdict', $report)) {
            $missing = array_diff(['decision', 'match', 'qualification', 'counterexample', 'window', 'action'], array_column($report['claims'] ?? [], 'section'));
            if ($missing) {
                $errors[] = '跟进报告缺少必要部分：'.implode('、', $missing);
            }
        }
        foreach ($report['claims'] ?? [] as $claim) {
            if (trim($claim['text'] ?? '') === '') {
                $errors[] = '结论正文缺失，不能发布。';
            }
            if (empty($claim['evidence_ids']) && ! (in_array($claim['section'] ?? '', ['counterexample', 'match'], true) && ! empty($report['project_references']))) {
                $errors[] = '结论缺少证据引用。';
            }
            foreach ($claim['evidence_ids'] ?? [] as $id) {
                if (! in_array($id, $snapshot['evidence_ids'] ?? [], true)) {
                    $errors[] = '引用不属于本次研究快照。';
                }
                $evidence = collect($snapshot['evidence'] ?? [])->firstWhere('id', $id);
                $quote = collect($claim['quotes'] ?? [])->firstWhere('evidence_id', $id)['quote'] ?? '';
                if (! $evidence || trim($quote) === '' || ! str_contains($this->normalize($evidence['text']), $this->normalize($quote))) {
                    $errors[] = '结论引用缺少可核验的原文短句。';
                }
            }
            if (preg_match('/中标[概几]率\s*[：:为]?\s*\d|从未合作|从未中标/u', $claim['text'] ?? '')) {
                $errors[] = '结论包含未获授权的概率或绝对历史判断。';
            }
        }
        if (empty($report['claims'])) {
            $errors[] = '没有可核验的结论。';
        }

        return array_values(array_unique($errors));
    }

    public function snapshotCurrent(array $snapshot): bool
    {
        if (! app(HubProjectContext::class)->current($snapshot)) {
            return false;
        }
        foreach ($snapshot['notice_versions'] ?? [] as $id => $revision) {
            if (HubNotice::find($id)?->revision !== $revision) {
                return false;
            }
        }

        return HubEvidence::whereIn('id', $snapshot['evidence_ids'] ?? [])->count() === count($snapshot['evidence_ids'] ?? []);
    }
}
