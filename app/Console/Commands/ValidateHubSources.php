<?php

namespace App\Console\Commands;

use App\Models\HubSource;
use App\Support\HubEvidenceRules;
use App\Support\HubFetcher;
use App\Support\HubScreening;
use App\Support\HubSourceAdapters;
use App\Support\HubSourceExtraction;
use Database\Seeders\HubSourceSeeder;
use Illuminate\Console\Command;
use Throwable;

class ValidateHubSources extends Command
{
    protected $signature = 'hub:validate-sources {--limit=10} {--expanded : Validate the eight new sources} {--source=* : Source name or adapter} {--output= : JSON evidence file}';

    protected $description = 'Fetch real public samples without models, ERP reads or business database writes';

    public function handle(HubFetcher $fetcher, HubSourceAdapters $adapters): int
    {
        $results = [];
        $limit = max(1, min(10, (int) $this->option('limit')));
        $definitions = $this->option('expanded') ? array_slice(HubSourceSeeder::sources(), 3, 8) : array_slice(HubSourceSeeder::sources(), 0, 3);
        if ($this->option('source')) {
            $definitions = array_filter(HubSourceSeeder::sources(), fn ($source) => in_array($source['adapter'], $this->option('source'), true) || in_array($source['name'], $this->option('source'), true));
        }
        foreach ($definitions as $definition) {
            $source = new HubSource($definition);
            $samples = [];
            try {
                $page = $source->adapter === 'unverified' ? $fetcher->fetch($source, $source->url) : $adapters->discover($source);
                $links = collect($page['links'])->filter(fn ($l) => mb_strlen($l['title']) > 18 && collect(config('procurement_hub.keywords'))->contains(fn ($word) => app(HubScreening::class)->matches($l['title'].' '.($l['document']['extracted']['procurement_content'] ?? ''), $word)) && ! preg_match('/测试|注册|通知|模板下载/u', $l['title']))->unique('url')->take($limit);
                foreach ($links as $link) {
                    try {
                        $document = $adapters->document($source, $link);
                        $extracted = $document['extracted'] ?? app(HubSourceExtraction::class)->extract($source, $document, $link['title']);
                        if ($extracted) {
                            app(HubEvidenceRules::class)->validateExtraction($extracted, $document['text']);
                        }
                        $samples[] = ['url' => $link['url'], 'title' => $link['title'], 'status' => 'fetched', 'content_hash' => $document['content_hash'],
                            'structured' => $extracted !== null, 'kind' => $extracted['kind'] ?? null, 'missing' => $extracted['missing'] ?? [], 'characters' => mb_strlen($document['text']), 'excerpt' => mb_substr($document['text'], 0, 600),
                            'has_price_text' => (bool) preg_match('/\d[\d,.]*\s*(万元|元)/u', $document['text']),
                            'has_quantity_text' => (bool) preg_match('/\d[\d,.]*\s*(吨|套|平方米)/u', $document['text']),
                            'raw_path' => $document['raw_path']];
                    } catch (Throwable $exception) {
                        $samples[] = ['url' => $link['url'], 'title' => $link['title'], 'status' => 'unavailable', 'reason' => mb_substr($exception->getMessage(), 0, 240)];
                    }
                }
                $results[] = ['source' => $source->name, 'url' => $source->url, 'samples' => $samples, 'discovered_links' => count($page['links']), 'status' => count($samples) === $limit && collect($samples)->every(fn ($s) => $s['status'] === 'fetched') ? 'sampled' : 'incomplete'];
            } catch (Throwable $exception) {
                $results[] = ['source' => $source->name, 'url' => $source->url, 'samples' => [], 'status' => 'unavailable', 'reason' => mb_substr($exception->getMessage(), 0, 240)];
            }
            $this->info($source->name.': '.count($samples).' 个样本');
        }
        $report = ['checked_at' => now()->toIso8601String(), 'note' => '公开采集与字段证据校验；未调用模型、读取 ERP 或写入业务数据库；不足样本明确标记，不代表全量覆盖。', 'sources' => $results];
        if ($this->option('output')) {
            file_put_contents($this->option('output'), json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return collect($results)->every(fn ($s) => $s['status'] === 'sampled' && collect($s['samples'])->every(fn ($x) => $x['status'] === 'fetched')) ? self::SUCCESS : self::FAILURE;
    }
}
