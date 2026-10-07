<?php

namespace App\Support;

use App\Ai\Agents\QuotationMarketAgent;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QuotationMarketSearch
{
    public function __construct(public QuotationAgentRunner $runner) {}

    public function search(array $query): array
    {
        $words = implode(' ', [$query['price_date'], $query['market'], $query['material'], $query['steel_spec'], '钢材价格 元/吨', $query['tax_basis']]);
        $rss = Http::connectTimeout(5)->timeout(12)->withOptions(['allow_redirects' => false])->get('https://www.bing.com/search', ['q' => $words, 'format' => 'rss'])->throw()->body();
        if (strlen($rss) > 1000000) {
            throw new RuntimeException('搜索结果超过大小限制。');
        }
        $xml = simplexml_load_string($rss, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $evidence = [];
        foreach ($xml?->channel?->item ?? [] as $item) {
            $url = (string) $item->link;
            if (! $this->allowed($url)) {
                continue;
            }
            try {
                $text = $this->fetch($url);
                $evidence[] = ['source_id' => count($evidence), 'url' => $url, 'title' => (string) $item->title, 'text' => mb_substr($text, 0, 14000), 'retrieved_at' => now()->toIso8601String()];
            } catch (\Throwable) {
                continue;
            }
            if (count($evidence) >= 4) {
                break;
            }
        }
        if (! $evidence) {
            return ['candidates' => [], 'sources' => [], 'limitations' => ['未取得可验证的公开钢材页面，可能需要订阅或登录。请提供价格或稍后重试。']];
        }
        $output = $this->runner->run(new QuotationMarketAgent, ['query' => $query, 'evidence' => $evidence]);
        $candidates = [];
        foreach ($output['candidates'] ?? [] as $candidate) {
            $source = $evidence[$candidate['source_id'] ?? -1] ?? null;
            if ($source && $this->verified($candidate, $source, $query)) {
                $candidates[] = [...$candidate, 'source' => 'internet', 'source_name' => $source['title'], 'url' => $source['url'], 'retrieved_at' => $source['retrieved_at'], 'date' => $candidate['published_date']];
            }
        }

        return ['candidates' => $candidates, 'sources' => array_map(fn (array $source) => array_diff_key($source, ['text' => true]), $evidence), 'limitations' => [...($output['limitations'] ?? []), ...($candidates ? [] : ['已读取页面，但日期、规格或税口径不完整匹配，未推荐可计算价格。'])]];
    }

    public function verified(array $candidate, array $source, array $query): bool
    {
        $quote = $candidate['quote'] ?? '';
        $text = $source['text'];
        if (! is_string($quote) || (mb_strlen($quote) < 5 || mb_strlen($quote) > 1500) || ! str_contains($text, $quote)
            || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $candidate['amount'] ?? '')
            || (float) $candidate['amount'] <= 0 || (float) $candidate['amount'] > 10000000
            || ($candidate['published_date'] ?? '') !== $query['price_date']) {
            return false;
        }
        $normalized = preg_replace('/[\s,，]/u', '', $text);
        if (! preg_match('/(?<![\\d.])'.preg_quote((string) (float) $candidate['amount'], '/').'(?:\\.0+)?(?![\\d.])/', preg_replace('/[,，]/u', '', $quote))) {
            return false;
        }
        $date = Carbon::parse($query['price_date']);
        $datePresent = false;
        foreach ([$date->format('Y-m-d'), $date->format('Y/n/j'), $date->format('Y/m/d'), $date->format('Y年n月j日'), $date->format('Y年m月d日'), $date->format('Y.n.j')] as $format) {
            $datePresent = $datePresent || str_contains($text, $format);
        }
        foreach (['market', 'material', 'tax_basis'] as $field) {
            if (($candidate[$field] ?? '') !== $query[$field] || ! str_contains($normalized, preg_replace('/\s/u', '', $query[$field]))) {
                return false;
            }
        }
        $spec = preg_replace('/[\s×xX*]/u', '', $query['steel_spec']);

        return $datePresent && preg_replace('/[\s×xX*]/u', '', $candidate['spec'] ?? '') === $spec
            && str_contains(preg_replace('/[\s×xX*]/u', '', $text), $spec) && str_contains($text, '元/吨');
    }

    private function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && in_array(strtolower($parts['host'] ?? ''), config('quotation.market_hosts'), true);
    }

    private function fetch(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $ips = gethostbynamel($host) ?: [];
        if (! $ips || collect($ips)->contains(fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new RuntimeException('来源必须是公开网络地址。');
        }
        $port = str_starts_with($url, 'https://') ? 443 : 80;
        $body = Http::connectTimeout(5)->timeout(10)->withUserAgent('QuotationReference/1.0')
            ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"], CURLOPT_PROXY => ''],
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 1000000) {
                        throw new RuntimeException('来源过大。');
                    }
                }, 'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 1000000) {
                        throw new RuntimeException('来源过大。');
                    }
                }])->get($url)->throw()->body();
        if (strlen($body) > 1000000) {
            throw new RuntimeException('来源过大。');
        }
        $body = mb_convert_encoding($body, 'UTF-8', mb_detect_encoding($body, ['UTF-8', 'GB18030', 'GBK'], true) ?: 'UTF-8');
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        return trim(preg_replace('/[ \t]+/u', ' ', $dom->textContent));
    }
}
