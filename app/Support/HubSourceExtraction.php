<?php

namespace App\Support;

use App\Models\HubSource;

class HubSourceExtraction
{
    /** Conservative extraction for verified public sources, independent of model availability. */
    public function extract(HubSource $source, array $document, string $title): ?array
    {
        $host = parse_url($document['url'], PHP_URL_HOST);
        if (! in_array($host, ['bid.cncecyc.com', 'cg.aceg.com.cn', 'bid.powerchina.cn', 'zbcg.sdhsg.com'], true) || ! in_array($host, $source->allowed_hosts, true)) {
            return null;
        }
        $text = $document['text'];
        $title = trim($title);
        if (mb_strlen($title) < 18 || mb_strlen($title) > 500 || ! preg_match('/采购|招标|中标|成交/u', $title)
            || ! str_contains(app(HubEvidenceRules::class)->normalize($text), app(HubEvidenceRules::class)->normalize($title))) {
            return null;
        }
        $values = ['title' => $title];
        $patterns = [
            'buyer' => '/(?:^|\n)\s*(?:[一二三四五六七八九十0-9]+[.、．]?\s*)?(?:招标人|采购人|采购单位)\s*[：:]\s*([^\n]{2,150})/u',
            'project_code' => '/(?:招标编号|采购编号|项目编号)\s*[：:]\s*([A-Za-z0-9_（）()\-\/]{4,100})/u',
            'published_at' => '/(?:发布时间|发布日期|发布时间为|信息时间)\s*[：:]?\s*(20\d{2}[-年\/][0-9]{1,2}[-月\/][0-9]{1,2}日?(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?)/u',
            'deadline' => '/(?:投标截止时间|响应文件递交截止时间|报价截止时间)(?:（开标时间）)?\s*[：:]?\s*(20\d{2}\s*[-年\/]\s*[0-9]{1,2}\s*[-月\/]\s*[0-9]{1,2}\s*日?\s*\d{1,2}[:时]\d{1,2}(?:分|:\d{2})?)/u',
        ];
        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $values[$field] = trim($match[1]);
            }
        }
        foreach (['钢模板', '钢筋', '钢材', '混凝土', '粉煤灰', '水泥', '砂石', '管道', '电缆', '集装箱', '活动板房'] as $product) {
            if (str_contains($title, $product)) {
                $values['product'] = $product;
                break;
            }
        }
        $kind = match (true) {
            (bool) preg_match('/终止|流标|废标/u', $title) => 'termination',
            (bool) preg_match('/变更|澄清|补遗|补充公告|修改|更正/u', $title) => 'amendment',
            str_contains($title, '候选人') => 'candidate',
            (bool) preg_match('/成交结果|中标结果|中标公示|成交公示|中标公告|成交公告/u', $title) => 'award',
            str_contains($title, '采购意向') => 'intent',
            default => 'notice',
        };

        return [...$values, 'is_notice' => true, 'kind' => $kind, 'transaction' => 'unknown', 'amount_type' => 'unknown',
            'registration_closed' => str_contains($text, '【报名已结束】'),
            'missing' => ['已核验公告原文；资格、价格及税运规格待进一步分析，保证金不作为成交金额。'],
            'citations' => collect($values)->map(fn ($value, $field) => ['field' => $field, 'quote' => $value])->values()->all()];
    }
}
