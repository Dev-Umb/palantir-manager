<?php

namespace App\Actions;

use App\Models\HubEvidence;
use App\Models\HubNotice;
use App\Models\HubRun;
use App\Models\HubSource;
use App\Support\HubEvidenceRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IngestHubNotice
{
    public function __construct(private HubEvidenceRules $rules) {}

    public function handle(HubSource $source, array $document, array $extracted): HubNotice
    {
        $data = $this->rules->validateExtraction($extracted, $document['text']);

        return DB::transaction(function () use ($source, $document, $data): HubNotice {
            $urlHash = hash('sha256', $document['url']);
            $previous = HubEvidence::where('url_hash', $urlHash)->latest('id')->first();
            $parts = array_map(fn ($key) => $this->rules->normalize($data[$key] ?? ''), ['buyer', 'project_code', 'lot', 'round']);
            $projectKey = hash('sha256', ! in_array('', $parts, true) ? implode('|', $parts) : $document['url']);
            $identity = hash('sha256', $projectKey.'|'.$data['kind']);
            $notice = $previous ? HubNotice::lockForUpdate()->findOrFail($previous->hub_notice_id)
                : HubNotice::firstOrCreate(['identity_key' => $identity], [
                    'project_key' => $projectKey, 'title' => $data['title'], 'kind' => $data['kind'], 'facts' => [],
                ]);
            if ($notice->evidence()->where('url_hash', $urlHash)->where('content_hash', $document['content_hash'])->exists()) {
                return $notice;
            }
            $facts = array_intersect_key($data, array_flip(['requirements', 'procurement_content', 'amount', 'quantity', 'unit', 'spec', 'tax', 'freight', 'rental_period', 'supplier', 'transaction', 'amount_type', 'registration_closed', 'currency', 'procurement_scope']));
            $attributes = array_intersect_key($data, array_flip(['title', 'buyer', 'group_name', 'project_code', 'lot', 'round', 'kind', 'region', 'product']));
            $conflicts = $notice->facts['_conflicts'] ?? [];
            if (! $previous && $notice->evidence()->exists()) {
                foreach (['amount', 'quantity', 'unit', 'spec', 'tax', 'freight', 'rental_period'] as $field) {
                    $old = $notice->facts[$field] ?? '';
                    $new = $facts[$field] ?? '';
                    if ($old !== '' && $new !== '' && $this->rules->normalize($old) !== $this->rules->normalize($new)) {
                        $conflicts[$field] = ['previous' => $old, 'incoming' => $new, 'previous_evidence_id' => $notice->evidence()->latest('id')->value('id')];
                    }
                }
            }
            if ($conflicts) {
                $facts['_conflicts'] = $conflicts;
                $data['missing'][] = '转载来源存在价格或规格冲突，需人工核实后重新研究。';
            }
            $attributes['facts'] = $facts;
            $attributes['missing'] = $data['missing'] ?? [];
            foreach (['published_at', 'deadline'] as $field) {
                $foreign = ! in_array(parse_url($document['url'], PHP_URL_HOST), ['bid.cncecyc.com', 'cg.aceg.com.cn', 'bid.powerchina.cn', 'zbcg.sdhsg.com', 'shudaojt.tfygcgfw.com'], true);
                $raw = $data[$field] ?? '';
                $attributes[$field] = $this->date($raw, $foreign, $field === 'deadline');
                if ($raw !== '') {
                    $attributes['facts'][$field.'_text'] = $raw;
                    if ($field === 'deadline') {
                        $attributes['missing'][] = '原文截止时间：'.$raw.($attributes[$field] ? '（页面统一按北京时间显示）' : '（未确认精确时刻或时区，不自动判断是否截止）');
                    }
                }
            }
            $changed = $notice->evidence()->exists() && ($notice->fill($attributes)->isDirty()
                || ($previous && $previous->content_hash !== $document['content_hash']));
            if ($changed) {
                $notice->revision++;
            }
            $notice->fill($attributes)->save();
            $notice->evidence()->create([
                'hub_source_id' => $source->id, 'url' => $document['url'], 'url_hash' => $urlHash,
                'content_hash' => $document['content_hash'], 'text' => $document['text'],
                'raw_path' => $document['raw_path'] ?? null, 'extraction' => $data,
                'attachments' => $document['attachments'] ?? [],
                'fetched_at' => $document['fetched_at'],
            ]);
            if ($changed || $notice->wasRecentlyCreated || $data['kind'] === 'amendment') {
                $noticeIds = HubNotice::where('project_key', $notice->project_key)->pluck('id');
                HubNotice::whereIn('id', $noticeIds)->update(['updated_at' => now()]);
                HubRun::whereIn('hub_notice_id', $noticeIds)->whereNotNull('published_at')->update(['stale_at' => now()]);
            }
            if ($changed || $notice->wasRecentlyCreated || $data['kind'] === 'amendment') {
                foreach (HubRun::whereNotNull('published_at')->whereNull('stale_at')->cursor() as $report) {
                    if (array_key_exists($notice->id, $report->snapshot['notice_versions'] ?? [])) {
                        $report->update(['stale_at' => now()]);
                    }
                }
                // New observations may expand an existing market sample.
                HubRun::where('kind', 'research')->whereNull('hub_notice_id')->whereNotNull('published_at')->update(['stale_at' => now()]);
            }

            return $notice->refresh();
        });
    }

    private function date(string $raw, bool $foreign = false, bool $deadline = false): ?Carbon
    {
        $raw = trim($raw);
        if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $raw, $iso)) {
            try {
                Carbon::createSafe((int) $iso[1], (int) $iso[2], (int) $iso[3], (int) $iso[4], (int) $iso[5], (int) $iso[6], 'UTC');

                return Carbon::parse($raw)->utc();
            } catch (\Throwable) {
                return null;
            }
        }
        if ($foreign) {
            if ($deadline) {
                return null;
            }
            try {
                if (preg_match('/^\d{2}-[A-Za-z]{3}-20\d{2}$/', $raw)) {
                    return Carbon::createFromFormat('!d-M-Y', $raw, 'UTC');
                }
                if (preg_match('/^(20\d{2}-\d{2}-\d{2})(?:[+-]\d{2}:\d{2})?$/', $raw, $date)) {
                    return Carbon::createFromFormat('!Y-m-d', $date[1], 'UTC');
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }
        $raw = preg_replace('/\s+/u', '', $raw);
        if (! preg_match('/(20\d{2})[年\/\-\.](\d{1,2})[月\/\-\.](\d{1,2})(?:日)?(?:\s*(\d{1,2})[:时](\d{1,2}))?/u', $raw, $m)) {
            return null;
        }
        try {
            return Carbon::createSafe((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), 0, 'Asia/Shanghai')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
