<?php

namespace App\Support;

use App\Models\HubNotice;
use App\Models\HubSource;
use Carbon\CarbonImmutable;

class HubScreening
{
    public function matches(string $text, string $term): bool
    {
        if (str_contains(mb_strtolower($text), mb_strtolower($term))) {
            return true;
        }
        if ($term === '桥梁' && preg_match('/\bbridges?\b/i', $text) && preg_match('/\b(road|river|railway|steel|culvert|flood|girder|construction|rehabilitation)\b/i', $text)) {
            return true;
        }
        foreach (config('procurement_hub.synonyms.'.$term, []) as $synonym) {
            if (preg_match('/(?<![a-z])'.preg_quote($synonym, '/').'(?![a-z])/i', $text)) {
                return true;
            }
        }

        return false;
    }

    /** Exact shared terms support discovery, never qualification or winning claims. */
    public function terms(array $projects): array
    {
        $text = implode(' ', array_map(fn ($p) => implode(' ', array_intersect_key($p, array_flip(['title', 'name', 'remark']))), $projects));
        $terms = array_values(array_filter(['钢模板', '模板', '台车', '挂篮', '钢箱梁', '钢桥', '钢包柱', '预埋件', '防落网', '剪力钉', '大桥', '高速', '国道', '铁路', '下构', '桥梁', '桥墩', '箱梁', 'T梁', '梁场', '隧道', '轨道', '钢结构', '脚手架', '护栏', '钢筋', '钢材', '水泥', '混凝土', '粉煤灰', '砂石', '沥青', '机电', '电缆', '管道', '设备租赁', '集装箱', '活动板房'], fn ($term) => $this->matches($text, $term)));

        return array_slice($terms, 0, 30);
    }

    public function overview(array $context): array
    {
        $directions = [];
        foreach ([
            ['钢模板、隧道台车与挂篮', ['模板', '台车', '挂篮'], '优先关注定制钢模板、台车采购及年度框架入围，区分新制、租赁、翻新。'],
            ['钢箱梁与桥梁钢结构', ['钢箱梁', '钢桥', '钢包柱', '钢结构'], '关注钢箱梁加工制作、桥梁钢构件；将安装资质与单纯供货要求分别核查。'],
            ['护栏与桥梁配套钢制品', ['护栏', '预埋件', '防落网', '剪力钉'], '关注防撞护栏、预埋件、剪力钉等包件，核对材质、加工和运输范围。'],
            ['梁场与桥梁下部结构', ['梁场', '箱梁', '下构'], '跟进梁场模板与下构模板需求；成品混凝土梁采购不直接视为钢模板机会。'],
        ] as [$label, $terms, $advice]) {
            $matches = collect($context['projects'])->filter(fn ($p) => collect($terms)->contains(fn ($term) => str_contains(($p['name'] ?? $p['title']).' '.($p['remark'] ?? ''), $term)))->values();
            if ($matches->isNotEmpty()) {
                $directions[] = ['label' => $label, 'count' => $matches->count(), 'advice' => $advice,
                    'references' => $matches->take(3)->map(fn ($p) => ['id' => $p['id'], 'title' => $p['name'] ?: $p['title']])->all()];
            }
        }

        return ['title' => '我们的招采关注方向', 'project_count' => count($context['projects']), 'directions' => $directions,
            'captured_at' => $context['captured_at'] ?? null,
            'limitations' => '按项目主档名称与备注归纳，类别可交叉，记录数不等于独立项目数或中标次数。资质、产能及完整投标结果尚不能由主档确认。',
            'keywords' => $this->terms($context['projects'])];
    }

    public function brief(HubNotice $notice, array $context): array
    {
        $evidence = $notice->evidence()->latest('id')->first();
        $text = $evidence?->text ?? '';
        $conditions = [];
        foreach (preg_split('/[\r\n]+/u', $text) as $line) {
            $line = trim($line);
            if (mb_strlen($line) > 12 && preg_match('/厂房面积|加工制造能力|类似业绩要求|业绩要求|采购量约为|预计模板翻新|投标文件递交的截止时间|本项目投标截止时间/u', $line)) {
                $conditions[] = mb_substr($line, 0, 350);
            }
        }
        $screening = $this->describe($notice, $context);
        $registrationDeadline = $this->registrationDeadline($text);
        $registrationClosed = $registrationDeadline?->isPast() || str_contains($text, '【报名已结束】');
        $closed = in_array($notice->kind, ['award', 'candidate', 'termination'], true) || ($notice->deadline && $notice->deadline->isPast()) || str_contains($text, '【报名已结束】');
        $source = $evidence ? HubSource::find($evidence->hub_source_id) : null;
        $reprint = $source && $source->adapter === 'unverified';
        $counterexamples = collect($context['projects'])->filter(fn ($p) => preg_match('/未中标|未中[，。]/u', $p['remark'] ?? ''))->take(2)
            ->map(function ($p): array {
                preg_match('/[^。\n]*未中(?:标)?[^。\n]*/u', $p['remark'], $match);

                return ['title' => $p['name'] ?: $p['title'], 'quote' => mb_substr($match[0] ?? '', 0, 180)];
            })->values()->all();

        $scope = mb_strtolower($notice->facts['procurement_scope'] ?? '');
        if (in_array($scope, ['works', 'services', 'consulting services'], true)) {
            $conditions[] = '原文采购类别：'.$notice->facts['procurement_scope'].'。工程或服务采购不等同于材料供货机会；需核实是否存在可参与的分包、供货包件及参与条件。';
        }

        return ['notice_id' => $notice->id, 'title' => $notice->title, 'status' => '原文与主档自动核对',
            'recommendation' => $closed ? '该条作为历史或结果信息研究，核对对应采购轮次后关注下一轮。' : ($notice->kind === 'intent' ? '计划、意向或待核实阶段，仅作前期线索；正式采购与资格窗口尚待确认。' : ($registrationClosed ? '报名已截止；仅已报名者继续核实投标资格与递交窗口，未报名者关注下一轮。' : ($reprint ? ($screening['projects'] ? '产品方向相关；先核实原站报名窗口与完整资格，再决定是否参与。' : '公开采购线索，暂未找到主档中可对照的同类项目；原站条件仍需核实。') : '可继续核对采购条件和供货范围，确认适配后再跟进。'))),
            'screening' => $screening, 'conditions' => array_slice(array_unique($conditions), 0, 5),
            'source_url' => $evidence?->url, 'source_name' => $source?->name, 'evidence_id' => $evidence?->id,
            'fetched_at' => $evidence?->fetched_at, 'counterexamples' => $counterexamples,
            'limitations' => [...($notice->missing ?? []), '主档仅辅助识别项目方向，不足以证明资质、产能和投标业绩。',
                '预算、保证金、加工费用和租赁金额不等同于成交单价；当前不提供推算报价。',
                ...($reprint ? ['当前为公开转载，日期或采购主体可能被隐藏，以采购平台原文为准。'] : [])],
        ];
    }

    private function registrationDeadline(string $text): ?CarbonImmutable
    {
        $end = $this->registrationWindow($text)['end'];

        return $end && str_contains($end, 'T') ? CarbonImmutable::parse($end) : null;
    }

    /** @return array{start: ?string, end: ?string} */
    public function registrationWindow(string $text): array
    {
        $unknown = ['start' => null, 'end' => null];
        $datePattern = '(\d{4})年(\d{1,2})月(\d{1,2})日(?:(\d{1,2})[:：时](\d{2})(?:分|时)?)?';
        foreach (preg_split('/[\r\n]+/u', $text) as $line) {
            $line = preg_replace('/\s+/u', '', $line);
            if (str_contains($line, '报名') && preg_match('/请于('.$datePattern.')至('.$datePattern.')/u', $line, $match, PREG_UNMATCHED_AS_NULL)) {
                try {
                    $dates = [];
                    foreach (['start' => 2, 'end' => 8] as $key => $offset) {
                        $date = CarbonImmutable::createSafe((int) $match[$offset], (int) $match[$offset + 1], (int) $match[$offset + 2], (int) ($match[$offset + 3] ?? 0), (int) ($match[$offset + 4] ?? 0), 0, 'Asia/Shanghai');
                        $dates[$key] = $match[$offset + 3] === null ? $date->format('Y-m-d') : $date->toIso8601String();
                    }

                    return $dates['start'] <= $dates['end'] ? $dates : $unknown;
                } catch (\Throwable) {
                    return $unknown;
                }
            }
        }

        return $unknown;
    }

    public function describe(HubNotice $notice, array $context): array
    {
        $text = $notice->title.' '.$notice->product.' '.($notice->facts['procurement_content'] ?? '');
        $references = [];
        $matched = [];
        foreach ($context['projects'] as $project) {
            $terms = array_values(array_filter($this->terms([$project]), fn ($term) => $this->matches($text, $term)));
            if ($terms) {
                $matched = array_unique([...$matched, ...$terms]);
                $references[] = ['id' => $project['id'], 'title' => $project['name'] ?: $project['title'], 'terms' => $terms];
            }
        }
        $active = in_array($notice->kind, ['notice', 'intent', 'amendment'], true) && (! $notice->deadline || $notice->deadline->isFuture());

        return ['label' => $references ? '项目关键词初筛' : '公开信息初筛',
            'reasons' => [$references ? '与已有项目共同涉及：'.implode('、', $matched).'。' : ($active ? '采购公告，可进一步核对需求与资格条件。' : '历史或变更信息，可作为采购研究参考。'),
                $notice->deadline ? '截止时间：'.$notice->deadline->timezone('Asia/Shanghai')->format('Y-m-d H:i').'。' : '原文截止时间尚待核实。'],
            'projects' => array_slice($references, 0, 3),
            'limitation' => $references ? '关键词相近不代表已具备资格，也不代表曾经投标或中标。' : '暂未找到可参考的同类项目，当前依据公开公告初筛。'];
    }
}
